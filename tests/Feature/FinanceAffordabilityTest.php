<?php

declare(strict_types=1);

use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureCollectionBatch;
use App\Actions\ImportTuitionPaymentAsCollection;
use App\Actions\ProposeReceiptAllocation;
use App\Actions\ReviewCollectionBatch;
use App\Models\BudgetSnapshot;
use App\Models\FinancePolicyActivation;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\PaymentReservation;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DefaultInstitutionPolicy;
use App\Services\FinanceAffordability;
use App\Services\InstitutionPolicyInterpreter;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\StudentTuitionPaymentProcessor;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

function affordabilityArc(string $decimal = '69.9', int $chainId = 5042002): void
{
    $arc = Mockery::mock(ArcNetworkGateway::class);
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    mockExpectation($arc, 'rpc')->andReturnUsing(static fn (string $method): mixed => match ($method) {
        'eth_chainId' => '0x'.BigInteger::of($chainId)->toBase(16),
        'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64), 'timestamp' => '0x'.BigInteger::of(now()->timestamp - 1)->toBase(16)],
        'eth_getBalance' => '0x'.BigDecimal::of($decimal)->withPointMovedRight(18)->toBigInteger()->toBase(16),
        default => throw new RuntimeException('Unexpected read '.$method),
    });
    app()->instance(ArcNetworkGateway::class, $arc);
}

test('finance compares exact observed cash reserves holds and unpaid bills without changing ledger', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360, 'lepton.arc.treasury' => $c['wallet']->address]);
    pilotPublishedDefaultPolicy($c, [...DefaultInstitutionPolicy::template(),
        'controls' => ['minimum_reserve' => '10', 'max_auto_payment' => '0', 'max_daily_disbursement' => '0', 'max_fee' => '0']]);
    affordabilityArc();
    $before = $c['wallet']->refresh()->getRawOriginal();
    $summary = app(FinanceAffordability::class)->summarize($c['reviewer']);
    expect($summary['observation']['balance_base_units'])->toBe('69900000')
        ->and($summary['available_base_units'])->toBe('59900000')->and($summary['unpaid_base_units'])->toBe('55000002')
        ->and($summary['funding_gap_base_units'])->toBe('0')->and($summary['can_execute'])->toBeFalse()
        ->and($c['wallet']->refresh()->getRawOriginal())->toBe($before)->and(Transaction::query()->count())->toBe(0);
});

test('missing policy and wrong chain leave funding unknown instead of substituting ledger', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['lepton.arc.treasury' => $c['wallet']->address]);
    affordabilityArc(chainId: 1);
    $summary = app(FinanceAffordability::class)->summarize($c['reviewer']);
    expect($summary['observation']['available'])->toBeFalse()->and($summary['available_base_units'])->toBeNull()
        ->and($summary['funding_gap_base_units'])->toBeNull()->and($summary['blockers'])->not->toBeEmpty();
});

test('one micro USDC is preserved and separate bills cannot imply cumulative affordability', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360, 'lepton.arc.treasury' => $c['wallet']->address]);
    pilotPublishedDefaultPolicy($c);
    affordabilityArc('50.000001');
    $summary = app(FinanceAffordability::class)->summarize($c['reviewer']);
    expect($summary['funding_gap_base_units'])->toBe('5000001')
        ->and(array_column($summary['bills'], 'funding_label'))->toBe(['Fits individually; approval needed', 'Fits individually; approval needed']);
});

test('fake gateway never supplies spendable wallet balance and student cannot read institution finances', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['lepton.default' => 'fake', 'lepton.arc.treasury' => $c['wallet']->address]);
    $summary = app(FinanceAffordability::class)->summarize($c['reviewer']);
    expect($summary['observation']['is_fake'])->toBeTrue()->and($summary['observation']['balance_base_units'])->toBeNull();
    $student = User::factory()->create()->assignRole(Role::findOrCreate('student', 'web'));
    expect(fn () => app(FinanceAffordability::class)->summarize($student))->toThrow(AuthorizationException::class);
});

test('JEV uses saved policy categories and never creates amounts or payment permission', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $published = pilotPublishedDefaultPolicy($c);
    config(['eduflow.institution_policy.classification_engine' => 'jev']);
    Classification::fake([['category' => new ChoiceAnswer('supplier-invoices', ['supplier-invoices' => 0.95], confidence: 0.95)]]);
    $answer = app(InstitutionPolicyInterpreter::class)->interpret($published['policy'], 'classification', ['description' => 'Reviewed cloud hosting invoice.']);
    expect($answer['category_id'])->toBe('supplier-invoices')->and($answer['jev_called'])->toBeTrue()
        ->and($answer['can_execute'])->toBeFalse()->and($answer)->not->toHaveKeys(['amount', 'recipient', 'approved']);
    expect(app(InstitutionPolicyInterpreter::class)->classificationProvider())->toBe(['name' => 'typesafe', 'model' => 'jev-latest']);
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('category'));
});

test('uncertain JEV classification holds rather than forcing miscellaneous category', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $published = pilotPublishedDefaultPolicy($c);
    config(['eduflow.institution_policy.classification_engine' => 'jev']);
    Classification::fake([['category' => new ChoiceAnswer('supplier-invoices', ['supplier-invoices' => 0.55])]]);
    $answer = app(InstitutionPolicyInterpreter::class)->interpret($published['policy'], 'classification', ['description' => 'Unclear request']);
    expect($answer['category_id'])->toBeNull()->and($answer['needs_review'])->toBeTrue()->and($answer['can_execute'])->toBeFalse();
});

test('unpublished institution policy leaves protected availability unknown despite observed money', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['lepton.arc.treasury' => $c['wallet']->address]);
    affordabilityArc('69.9');
    $summary = app(FinanceAffordability::class)->summarize($c['reviewer']);
    expect($summary['observation']['available'])->toBeTrue()->and($summary['available_base_units'])->toBeNull()
        ->and($summary['funding_gap_base_units'])->toBeNull()->and($summary['raw_funding_gap_base_units'])->toBe('0');
});

test('restricted reviewed receipts are protected not added to chain balance a second time', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360, 'lepton.arc.treasury' => $c['wallet']->address]);
    pilotPublishedDefaultPolicy($c);
    $batch = app(CaptureCollectionBatch::class)->handle($c['maker'], [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-fees', 'source_reference' => 'PARTIAL-TUITION-5',
        'source_document_digest' => hash('sha256', 'synthetic-five-usdc-cash'), 'currency' => 'USDC', 'received_amount' => '5', 'restricted_amount' => '1',
        'collected_from' => now()->subDays(2)->toIso8601String(), 'collected_until' => now()->subDay()->toIso8601String(),
        'cash_evidence_reference' => 'Synthetic cashier check', 'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);
    app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest, 'approve_receipts', 'Independent check', 'Five received and one restricted.');
    affordabilityArc('5');
    $summary = app(FinanceAffordability::class)->summarize($c['reviewer']);
    expect($summary['reviewed_receipts_base_units'])->toBe('5000000')->and($summary['restricted_base_units'])->toBe('1000000')
        ->and($summary['available_base_units'])->toBe('4000000')->and($summary['funding_gap_base_units'])->toBe('51000002');
});

test('held capacity remains unavailable and damaged hold makes comparison unknown', function (): void {
    $c = paymentAuthorizationContext();
    config(['queue.connections.database.retry_after' => 360, 'lepton.arc.treasury' => $c['wallet']->address]);
    pilotPublishedDefaultPolicy($c);
    affordabilityArc('69.9');
    $summary = app(FinanceAffordability::class)->summarize($c['reviewer']);
    expect($summary['committed_base_units'])->toBe('25000003')->and($summary['available_base_units'])->toBe('44899997')
        ->and($summary['committed_unpaid_base_units'])->toBe('25000001')->and($summary['funding_gap_base_units'])->toBe('0')
        ->and($summary['bills'][$c['drafts'][0]->invoice_id]['funding_label'])->toBe('Already committed; inspect payment');
    DB::table('payment_reservations')->where('id', $c['hold']->id)->update(['snapshot_digest' => str_repeat('0', 64)]);
    $broken = app(FinanceAffordability::class)->summarize($c['reviewer']);
    expect($broken['committed_base_units'])->toBeNull()->and($broken['available_base_units'])->toBeNull();
});

test('policy drafting command previews by default saves successor once and never activates', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    $before = FinancePolicyActivation::query()->count();
    $this->artisan('eduflow:draft-institution-policy', ['author' => $c['maker']->id])->assertSuccessful();
    expect(InstitutionPolicyVersion::query()->count())->toBe(0);
    $this->artisan('eduflow:draft-institution-policy', ['author' => $c['maker']->id, '--save' => true])->assertSuccessful();
    $policy = InstitutionPolicyVersion::query()->sole();
    expect($policy->hasValidContent())->toBeTrue()->and(collect($policy->content['clauses'])->pluck('id')->all())->toContain('bill-affordability', 'pilot');
    $this->artisan('eduflow:draft-institution-policy', ['author' => $c['maker']->id, '--save' => true])->assertSuccessful();
    expect(InstitutionPolicyVersion::query()->count())->toBe(1)
        ->and(InstitutionPolicyPublication::query()->count())->toBe(0)
        ->and(FinancePolicyActivation::query()->count())->toBe($before);
});

test('conflicting JEV choices require review and provider failures create no executable result', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $published = pilotPublishedDefaultPolicy($c);
    config(['eduflow.institution_policy.classification_engine' => 'jev']);
    Classification::fake([['category' => new ChoiceAnswer('supplier-invoices', ['supplier-invoices' => 0.95, 'student-clubs' => 0.85])]]);
    $answer = app(InstitutionPolicyInterpreter::class)->interpret($published['policy'], 'classification', ['description' => 'Conflicting evidence']);
    expect($answer['needs_review'])->toBeTrue()->and($answer['category_id'])->toBeNull();
    Classification::fake(static function (ClassificationPrompt $prompt): array {
        throw new RuntimeException('Synthetic provider unavailable');
    });
    expect(fn () => app(InstitutionPolicyInterpreter::class)->interpret($published['policy'], 'classification', ['description' => 'Cloud bill']))
        ->toThrow(RuntimeException::class)->and(Transaction::query()->count())->toBe(0);
});

test('unposted tuition with broken consent is visible as a finance blocker and cannot count as collections', function (): void {
    $c = consentContext();
    $attempt = confirmConsent($c);
    DB::table('student_tuition_payment_attempts')->where('id', $attempt->id)->update(['state' => 'verified', 'consent_digest' => null]);
    $owner = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'finance_officer'))->firstOrFail();
    $summary = app(FinanceAffordability::class)->summarize($owner);
    expect($summary['invalid_tuition_attempts'])->toBe(1)->and($summary['reviewed_receipts_base_units'])->toBe('0')
        ->and(implode(' ', $summary['blockers']))->toContain('do not submit replacement payments');
});

test('five USDC settled tuition is reviewed then JEV proposes only receipt-backed bounded allocations', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    pilotPublishedDefaultPolicy($c);
    $payer = consentContext($c['institution']);
    config(['lepton.arc.treasury' => $c['wallet']->address]);
    $attempt = confirmConsent($payer);
    $hash = '0x'.str_repeat('f', 64);
    mockExpectation($payer['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    mockExpectation($payer['transport'], 'resolve')->andReturn(TransportResolution::completed($hash));
    mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionReceipt', [$hash])->andReturn([
        'transactionHash' => $hash, 'blockNumber' => '0x64', 'status' => '0x1', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => []]);
    mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionByHash', [$hash])->andReturn([
        'from' => $attempt->payer_address, 'to' => $attempt->recipient_address, 'value' => '0x'.BigInteger::of('5000000000000000000')->toBase(16), 'blockNumber' => '0x64']);
    mockExpectation($payer['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);
    $processor = app(StudentTuitionPaymentProcessor::class);
    $processor->process($attempt->id);
    $processor->process($attempt->id);
    expect($attempt->refresh()->hasValidSettlement())->toBeTrue()->and($payer['account']->refresh()->paid_amount)->toBe(5_000000);
    $payment = StudentTuitionPayment::query()->sole();
    $batch = app(ImportTuitionPaymentAsCollection::class)->handle($c['maker'], $payment, (string) Str::uuid());
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest, 'approve_receipts', 'Synthetic proof checked', 'Verified five USDC and no overpayment.');
    $base = BudgetSnapshot::query()->firstOrFail();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['maker'], $c['budget'], [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => $base->snapshot['department']['name'],
        'period_start' => $base->snapshot['department']['period_start'], 'period_end' => $base->snapshot['department']['period_end'],
        'as_of' => now()->utc()->toIso8601String(), 'valid_until' => now()->addHour()->utc()->toIso8601String(),
        'bill_ids' => array_column($base->snapshot['bills'], 'id'), 'collection_review_ids' => [$review->id],
        'allocation' => '100', 'already_spent' => '0', 'other_budget_commitments' => '0', 'opening_funds' => '0',
        'realized_receipts' => '5', 'actual_outflows' => '0', 'restricted_cash' => '0', 'protected_reserve' => '0', 'other_cash_commitments' => '0',
        'budget_evidence' => 'Synthetic department allocation', 'cash_evidence' => 'Verified testnet tuition receipt', 'commitment_evidence' => 'Synthetic commitments',
        'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true,
    ]);
    config(['eduflow.institution_policy.classification_engine' => 'jev']);
    Classification::fake(fn (ClassificationPrompt $prompt): array => ['category' => new ChoiceAnswer('supplier-invoices', ['supplier-invoices' => 0.95])]);
    $before = PaymentReservation::query()->count();
    $plan = app(ProposeReceiptAllocation::class)->handle($c['reviewer'], $snapshot, (string) Str::uuid(), fresh: true);
    expect($plan->hasValidPlan())->toBeTrue()->and($plan->plan['realized_receipts_minor_units'])->toBe('5000000')
        ->and($plan->proposed_minor_units)->toBeLessThanOrEqual(5_000000)->and($plan->plan['funds_reserved'])->toBeFalse()
        ->and($plan->plan['lines'][0]['proposed_minor_units'])->toBe('0')
        ->and(PaymentReservation::query()->count())->toBe($before)->and(Transaction::query()->count())->toBe(0);
});
