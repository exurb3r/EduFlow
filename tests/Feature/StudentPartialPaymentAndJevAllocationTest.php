<?php

declare(strict_types=1);

use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureCollectionBatch;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\ImportTuitionPaymentAsCollection;
use App\Actions\ProposeReceiptAllocation;
use App\Actions\ReviewCollectionBatch;
use App\Actions\ReviewInvoiceVersion;
use App\Jobs\EvaluateInstitutionPolicy;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\PaymentReservation;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Notifications\TuitionPaymentSuccessNotification;
use App\Services\DefaultInstitutionPolicy;
use App\Services\InstitutionPolicyEvaluations;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\StudentTuitionPaymentProcessor;
use App\Services\StudentTuitionPaymentService;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

test('student partially pays 5 USDC of 200 USDC tuition, then JEV classifies and allocates within received cash', function (): void {
    Notification::fake();

    // 1. Setup institution and published policy
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $template = DefaultInstitutionPolicy::template();
    $template['controls']['minimum_reserve'] = '0';
    $published = pilotPublishedDefaultPolicy($c, $template);
    $policy = $published['policy'];

    // 2. Setup consenting student owing 200 USDC tuition with a funded testnet wallet
    $payer = consentContext($c['institution']);
    config(['lepton.arc.treasury' => $c['wallet']->address]);
    $account = $payer['account'];
    $account->update(['total_amount' => 200_000000, 'paid_amount' => 0]); // 200.00 USDC total

    // 3. Student quotes and consents to a 5 USDC partial downpayment
    $service = app(StudentTuitionPaymentService::class);
    $quote = $service->quote($payer['student'], $account, 5_000000);
    expect($quote['amount_base_units'])->toBe(5_000000)
        ->and($quote['remaining_base_units'])->toBe(200_000000);

    $attempt = $service->pay($payer['student'], $account, 5_000000, $quote['request_key'], $quote['request_digest'], true);
    expect($attempt->hasValidConsent())->toBeTrue()
        ->and($attempt->amount_base_units)->toBe(5_000000)
        ->and($attempt->state)->toBe('prepared');

    // 4. Arc Testnet rail submits and verifies the settlement
    $hash = '0x'.str_repeat('a', 64);
    mockExpectation($payer['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    mockExpectation($payer['transport'], 'resolve')->andReturn(TransportResolution::completed($hash));
    mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionReceipt', [$hash])->andReturn([
        'transactionHash' => $hash, 'blockNumber' => '0x64', 'status' => '0x1',
        'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => [],
    ]);
    mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionByHash', [$hash])->andReturn([
        'from' => $attempt->payer_address, 'to' => $attempt->recipient_address,
        'value' => '0x'.BigInteger::of('5000000000000000000')->toBase(16), 'blockNumber' => '0x64',
    ]);
    mockExpectation($payer['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('b', 64)]);

    // 5. Process the attempt: submit, then resolve + verify + post
    $processor = app(StudentTuitionPaymentProcessor::class);
    $processor->process($attempt->id);
    $processor->process($attempt->id);

    // 6. Verify student account state: 5 paid, 195 remaining, partially paid!
    $account->refresh();
    expect($attempt->refresh()->hasValidSettlement())->toBeTrue()
        ->and($attempt->posted_at)->not->toBeNull()
        ->and($account->paid_amount)->toBe(5_000000)
        ->and($account->remainingAmount())->toBe(195_000000)
        ->and($account->isPartiallyPaid())->toBeTrue()
        ->and($account->isPaidInFull())->toBeFalse()
        ->and($account->statusLabel())->toBe('Partially paid')
        ->and(Transaction::query()->count())->toBe(0); // Testnet receipt writes no legacy float ledger revenue

    Notification::assertSentTo($payer['student']->user, TuitionPaymentSuccessNotification::class);

    // 7. Reconcile verified payment into Collections
    $payment = StudentTuitionPayment::query()->where('request_key', $attempt->request_key)->sole();
    $batch = app(ImportTuitionPaymentAsCollection::class)->handle($c['maker'], $payment, (string) Str::uuid());
    expect($batch->received_minor_units)->toBe(5_000000)
        ->and($batch->restricted_minor_units)->toBe(0)
        ->and($batch->isMovementSourced())->toBeTrue();

    // 8. Independent review of the collection batch
    $review = app(ReviewCollectionBatch::class)->handle(
        $c['reviewer'],
        $batch,
        $batch->snapshot_digest,
        'approve_receipts',
        'Arc Testnet verified transfer checked',
        'Verified 5.00 USDC downpayment; 195.00 USDC remaining balance remains debt, not cash.',
    );
    expect($review->hasValidEvidence($batch))->toBeTrue();

    // 9. Setup candidate bills: Bill A (5 USDC), Bill B (30 USDC) under active department budget
    /** @var Budget $budget */
    $budget = Budget::query()->create([
        'organization_id' => $c['institution']->id, 'name' => 'Campus Technology', 'category' => 'technology',
        'allocated_amount' => '100', 'spent_amount' => '0', 'remaining_amount' => '100', 'status' => 'active',
    ]);
    $vendor = Vendor::query()->create([
        'organization_id' => $c['institution']->id, 'name' => 'Essential Cloud Host', 'wallet_address' => '0x'.str_repeat('4', 40),
        'status' => 'verified', 'risk_level' => 'low',
    ]);
    $invoiceA = Invoice::query()->create([
        'organization_id' => $c['institution']->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'INV-ESSENTIAL-5', 'amount' => '5', 'due_date' => now()->addDay(), 'category' => 'technology', 'status' => 'pending',
    ]);
    $invoiceB = Invoice::query()->create([
        'organization_id' => $c['institution']->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'INV-SERVER-30', 'amount' => '30', 'due_date' => now()->addDays(2), 'category' => 'technology', 'status' => 'pending',
    ]);
    $billA = app(CaptureInvoiceVersion::class)->handle($c['maker'], $invoiceA, [
        'capture_key' => (string) Str::uuid(), 'source_amount' => '5', 'source_currency' => 'USDC',
        'source_evidence' => 'approved-essential-cloud-invoice', 'business_approval_reference' => 'dean-approved',
        'department' => 'Campus Technology', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '1', 'rate_source' => 'identity-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
    ]);
    $billB = app(CaptureInvoiceVersion::class)->handle($c['maker'], $invoiceB, [
        'capture_key' => (string) Str::uuid(), 'source_amount' => '30', 'source_currency' => 'USDC',
        'source_evidence' => 'approved-server-invoice', 'business_approval_reference' => 'dean-approved',
        'department' => 'Campus Technology', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '1', 'rate_source' => 'identity-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
    ]);
    app(ReviewInvoiceVersion::class)->handle($c['reviewer'], $billA, $billA->snapshot_digest, 'approve_evidence', 'Essential bill evidence confirmed.');
    app(ReviewInvoiceVersion::class)->handle($c['reviewer'], $billB, $billB->snapshot_digest, 'approve_evidence', 'Server bill evidence confirmed.');

    // 10. Capture planning snapshot binding the 5.00 USDC tuition receipt
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['maker'], $budget, [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => 'Campus Technology',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->utc()->toIso8601String(), 'valid_until' => now()->addHour()->utc()->toIso8601String(),
        'bill_ids' => [$billA->id, $billB->id], 'collection_review_ids' => [$review->id],
        'allocation' => '100', 'already_spent' => '0', 'other_budget_commitments' => '0', 'opening_funds' => '0',
        'realized_receipts' => '5', 'actual_outflows' => '0', 'restricted_cash' => '0', 'protected_reserve' => '0',
        'other_cash_commitments' => '0', 'budget_evidence' => 'Department tech budget', 'cash_evidence' => 'Tuition downpayment receipt',
        'commitment_evidence' => 'Obligation register', 'commitments_exclude_selected_bills' => true,
        'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true,
    ]);
    expect($snapshot->headroom()['cash_minor_units'])->toBe('5000000');

    // 11. Configure JEV classification engine
    config(['eduflow.institution_policy.classification_engine' => 'jev']);
    Classification::fake(function (ClassificationPrompt $prompt): array {
        return ['category' => new ChoiceAnswer('supplier-invoices', ['supplier-invoices' => 0.95], confidence: 0.95)];
    });

    // 12. Run receipt allocation proposal
    $holdsBefore = PaymentReservation::query()->count();
    $plan = app(ProposeReceiptAllocation::class)->handle($c['reviewer'], $snapshot, (string) Str::uuid(), fresh: true);

    expect($plan->hasValidPlan())->toBeTrue()
        ->and($plan->plan['service_capability'])->toBe('published_policy_allocation_proposal')
        ->and($plan->plan['realized_receipts_minor_units'])->toBe('5000000')
        ->and($plan->proposed_minor_units)->toBe(5_000000)
        ->and($plan->unallocated_minor_units)->toBe(0)
        ->and($plan->plan['funds_reserved'])->toBeFalse()
        ->and($plan->plan['can_execute'])->toBeFalse()
        ->and(PaymentReservation::query()->count())->toBe($holdsBefore)
        ->and(Transaction::query()->count())->toBe(0);

    // Bill A (5 USDC) fits the collected tuition cash -> proposed for human review
    $lineA = collect($plan->plan['lines'])->firstWhere('target_id', $invoiceA->id);
    expect($lineA['decision'])->toBe('propose_human_review')
        ->and($lineA['proposed_minor_units'])->toBe('5000000')
        ->and($lineA['category_id'])->toBe('supplier-invoices');

    // Bill B (30 USDC) exceeds the remaining tuition cash -> held for review
    $lineB = collect($plan->plan['lines'])->firstWhere('target_id', $invoiceB->id);
    expect($lineB['decision'])->toBe('hold')
        ->and($lineB['proposed_minor_units'])->toBe('0')
        ->and($lineB['checks']['realized_cash_available'])->toBeFalse();
});

test('admin can inspect the 5 USDC partial tuition collection in evaluations page and JEV classifies it live', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));

    $context = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $template = DefaultInstitutionPolicy::template();
    $template['controls']['minimum_reserve'] = '0';
    $template['clauses'][] = [
        'id' => 'tuition-receipts',
        'title' => 'Partial tuition collections',
        'text' => 'Tuition receipts include verified partial payments and downpayments. For example, receiving 5 USDC toward a 200 USDC tuition account records 5 USDC received and 195 USDC still owed.',
    ];
    $template['categories'][] = [
        'id' => 'tuition-receipts',
        'name' => 'Tuition receipts',
        'description' => 'Incoming full or partial tuition payments, not spending destinations.',
        'inclusions' => 'Verified tuition payments and downpayments.',
        'exclusions' => 'Unpaid tuition, vendor invoices and other student fees.',
        'clause_ids' => ['tuition-receipts'],
    ];
    $published = pilotPublishedDefaultPolicy($context, $template);

    // Seed a collection batch representing a 5 USDC tuition downpayment
    $batch = app(CaptureCollectionBatch::class)->handle($context['maker'], [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-fees',
        'source_reference' => 'TUITION-DOWNPAYMENT-5USDC', 'source_document_digest' => hash('sha256', 'tuition-downpayment-proof'),
        'currency' => 'USDC', 'received_amount' => '5', 'restricted_amount' => '0',
        'collected_from' => now()->subDay()->toIso8601String(), 'collected_until' => now()->toIso8601String(),
        'cash_evidence_reference' => 'Cashier verified downpayment receipt',
        'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);

    // Create an evaluation run for this collection
    $service = app(InstitutionPolicyEvaluations::class);
    $source = $service->source($context['reviewer'], 'collection', $batch->id);
    $run = $service->request($context['reviewer'], [
        'source_type' => 'collection', 'source_id' => $batch->id, 'source_digest' => $source['source_digest'],
        'expected_publication_digest' => $published['publication']->publication_digest, 'request_key' => (string) Str::uuid(),
        'reason' => 'Evaluate 5 USDC tuition collection with JEV.',
    ]);

    // JEV classifies the collection as incoming tuition receipt
    config(['eduflow.institution_policy.classification_engine' => 'jev']);
    Classification::fake(function (ClassificationPrompt $prompt): array {
        return ['category' => new ChoiceAnswer('tuition-receipts', ['tuition-receipts' => 0.95], confidence: 0.95)];
    });

    // Execute the evaluation job
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    expect($run->refresh()->hasValidResult())->toBeTrue()
        ->and($run->result['category_id'])->toBe('tuition-receipts');

    // Visit evaluations page as reviewer
    $this->actingAs($context['reviewer']);
    $page = visit('/finance/policy-evaluations?source_type=collection&source_id='.$batch->id);
    $page->assertSee('Policy evaluations')
        ->assertSee('TUITION-DOWNPAYMENT-5USDC')
        ->assertSee('Classification only')
        ->assertSee('Advisory result')
        ->assertSee('tuition-receipts')
        ->assertNoJavaScriptErrors();

    $page->screenshot(fullPage: false, filename: 'jev-tuition-partial-allocation');
    expect(Transaction::query()->count())->toBe(0);
})->group('browser');
