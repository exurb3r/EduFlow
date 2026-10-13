<?php

declare(strict_types=1);

use App\Actions\ApproveFundingWindow;
use App\Actions\ApproveReceiptBudgetRoute;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\ImportTuitionPaymentAsCollection;
use App\Actions\PrepareFundingWindow;
use App\Actions\PrepareReceiptBudgetRoute;
use App\Actions\ReviewCollectionBatch;
use App\Actions\ReviewFinancePlan;
use App\Actions\ReviewVendorPayment;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Jobs\ProcessPaymentSubmission;
use App\Models\BudgetSnapshot;
use App\Models\FinanceWorkflowRun;
use App\Models\PaymentSubmissionOutbox;
use App\Models\ReceiptPlanTrigger;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\Transaction;
use App\Services\AcceptedPlanPayments;
use App\Services\FinanceReviewAlerts;
use App\Services\FinanceWorkflows;
use App\Services\IsolatedPaymentExecutor;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\StudentBillPaymentQuote;
use App\Services\StudentTuitionPaymentProcessor;
use App\Services\StudentTuitionPaymentService;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

test('verified student receipt funds a reviewed proposal and one independently approved vendor submission with visible progress', function (string $source): void {
    $c = paymentAuthorizationContext(withFunding: false);
    if ($source === 'bill') {
        $payer = billPaymentContext(['balance' => 200_000000], $c['institution']);
        config(['lepton.arc.treasury' => $c['wallet']->address]);
        $receipt = billConsent($payer, 100_000000);
        $service = app(StudentBillPaymentQuote::class);
        $service->recordSettlement($receipt, billSettlementStub($payer, $receipt, 100_000000, marker: 'e'));
        $receipt = $service->allocate($receipt->fresh());
        expect($receipt->hasValidSettlement())->toBeTrue();
    } else {
        $payer = consentContext($c['institution']);
        $payer['student']->wallet->update(['address' => '0x'.str_repeat('3', 40)]);
        $payer['student']->update(['payout_address' => '0x'.str_repeat('3', 40)]);
        $payer['student']->refresh();
        $payer['arc'] = tuitionArcGateway(200_000000);
        app()->instance(ArcNetworkGateway::class, $payer['arc']);
        config(['lepton.arc.treasury' => $c['wallet']->address]);
        $service = app(StudentTuitionPaymentService::class);
        $quote = $service->quote($payer['student'], $payer['account'], 100_000000);
        $attempt = $service->pay($payer['student'], $payer['account'], 100_000000, $quote['request_key'], $quote['request_digest'], true);
        mockExpectation($payer['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
        mockExpectation($payer['transport'], 'resolve')->andReturn(TransportResolution::completed('0x'.str_repeat('e', 64)));
        mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionReceipt', ['0x'.str_repeat('e', 64)])->andReturn([
            'transactionHash' => '0x'.str_repeat('e', 64), 'blockNumber' => '0x64', 'status' => '0x1',
            'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => [],
        ]);
        mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionByHash', ['0x'.str_repeat('e', 64)])->andReturn([
            'from' => $attempt->payer_address, 'to' => $attempt->recipient_address,
            'value' => '0x'.BigInteger::of('100000000000000000000')->toBase(16), 'blockNumber' => '0x64',
        ]);
        mockExpectation($payer['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);
        $processor = app(StudentTuitionPaymentProcessor::class);
        $processor->process($attempt->id);
        $processor->process($attempt->id);
        $receipt = StudentTuitionPayment::query()->where('request_key', $attempt->request_key)->firstOrFail();
        expect(StudentTuitionPaymentAttempt::query()->findOrFail($attempt->id)->hasValidSettlement())->toBeTrue();
    }

    $batch = app(ImportTuitionPaymentAsCollection::class)->handle($c['maker'], $receipt, (string) Str::uuid());
    $original = BudgetSnapshot::query()->where('budget_id', $c['budget']->id)->firstOrFail();
    config(['eduflow.background_finance.enabled' => true, 'eduflow.background_finance.receipt_routing_enabled' => true]);
    $base = app(CaptureBudgetSnapshot::class)->handle($c['maker'], $c['budget'], [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => $original->snapshot['department']['name'],
        'period_start' => $original->snapshot['department']['period_start'], 'period_end' => $original->snapshot['department']['period_end'],
        'as_of' => now()->utc()->toIso8601String(), 'valid_until' => now()->addHour()->utc()->toIso8601String(),
        'bill_ids' => array_column($original->snapshot['bills'], 'id'), 'collection_review_ids' => [],
        'allocation' => '100', 'already_spent' => '0', 'other_budget_commitments' => '0', 'opening_funds' => '0',
        'realized_receipts' => '0', 'actual_outflows' => '0', 'restricted_cash' => '0', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
        'budget_evidence' => 'existing-allocation', 'cash_evidence' => 'verified-tuition', 'commitment_evidence' => 'reviewed-commitments',
        'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true,
    ]);
    $route = app(PrepareReceiptBudgetRoute::class)->handle($c['maker'], $base, $batch->source_stream,
        $batch->collected_from->toIso8601String(), $base->snapshot['valid_until'], (string) Str::uuid());
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $route, $route->snapshot_digest, 'Exact existing budget and receipt stream reviewed.');
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'synthetic-independent-receipt-check', 'Pilot source and restrictions checked.');
    $collectionRun = FinanceWorkflowRun::query()->where('collection_batch_id', $batch->id)->sole();
    app(FinanceWorkflows::class)->process($collectionRun->id);
    $trigger = ReceiptPlanTrigger::query()->where('collection_batch_review_id', $review->id)->sole();
    $snapshot = BudgetSnapshot::query()->findOrFail($trigger->budget_snapshot_id);
    $run = FinanceWorkflowRun::query()->where('budget_snapshot_id', $snapshot->id)->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    app(FinanceReviewAlerts::class)->deliver($run->id);
    expect($run->fresh()->result['bills'][0]['decision'])->toBe('propose_human_review')
        ->and(DB::table('notifications')->where('notifiable_id', $c['reviewer']->id)->exists())->toBeTrue();
    $run->refresh();
    app(ReviewFinancePlan::class)->handle($c['reviewer'], $run, $run->result_digest, 'accept_plan', 'Tuition-funded proposal reviewed.');
    stubPaymentAuthorizationBalance('100000000000000000007');
    $window = app(PrepareFundingWindow::class)->handle($c['maker'], $snapshot, $c['wallet'], (string) Str::uuid(), now()->addMinutes(10)->utc()->toIso8601String());
    $approval = app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Synthetic source funds checked.');
    $plans = app(AcceptedPlanPayments::class);
    $c['hold'] = $plans->prepareBill($c['maker'], $run, $c['drafts'][0]->invoice_version_id, $approval,
        $run->result_digest, $approval->approval_digest, new Money(2, CurrencyCode::USDC));
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    $entry = PaymentSubmissionOutbox::query()->firstOrFail();
    $sends = 0;
    scriptedTransport(function () use (&$sends): TransportAcceptance {
        $sends++;

        return new TransportAcceptance('216c7ce7-8f2f-53ac-8b06-f18aac151ce3');
    }, fn (): TransportResolution => TransportResolution::completed('0x'.str_repeat('f', 64)));
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle', 'eduflow.submission.transport' => 'circle_agent_wallet']);
    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));
    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));
    expect($plans->progress($c['reviewer'], $run)[0]['state'])->toBe('accepted');
    stubArc(settledReceiptFor($entry));
    $this->artisan('eduflow:reconcile-payment-submissions')->assertSuccessful();
    $this->artisan('eduflow:reconcile-payment-submissions')->assertSuccessful();
    expect($plans->progress($c['reviewer'], $run)[0]['settled'])->toBeTrue()
        ->and($sends)->toBe(1)->and(Transaction::query()->count())->toBe(0)
        ->and($c['drafts'][0]->invoice->fresh()->status)->toBe('pending')
        ->and($snapshot->snapshot['movement_receipts_minor_units'])->toBe('100000000');
})->with(['tuition', 'bill']);
