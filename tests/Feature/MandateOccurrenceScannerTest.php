<?php

declare(strict_types=1);

use App\Actions\ApproveFundingWindow;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\PrepareFundingWindow;
use App\Actions\PrepareRecurringMandate;
use App\Actions\ReviewInvoiceVersion;
use App\Actions\ReviewRecurringMandate;
use App\Models\BudgetSnapshot;
use App\Models\Invoice;
use App\Models\MandateOccurrence;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentSubmissionOutbox;
use App\Models\RecurringMandate;
use App\Models\Transaction as TransactionModel;
use App\Services\MandateOccurrenceScanner;
use Brick\Math\BigInteger;
use Illuminate\Support\Str;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

/**
 * The sessionless occurrence tick.
 *
 * The properties that matter are the ones a scheduler could quietly break: it
 * never reaches a rail, it never fabricates a human actor, it is idempotent, and
 * it records a *blocked* disposition rather than a silent skip when live
 * evidence cannot be read.
 *
 * @param  array<string, mixed>  $c
 */
function scannerBills(array $c, string $amount = '4.000000', int $dueInDays = 0): int
{
    $invoice = Invoice::query()->create([
        'organization_id' => $c['institution']->id,
        'vendor_id' => $c['vendor']->id,
        'budget_id' => $c['budget']->id,
        'reference' => 'MANDATE-'.Str::uuid(),
        'amount' => '999999',
        'due_date' => now()->addDays($dueInDays),
        'category' => 'software',
        'status' => 'pending',
    ]);

    $bill = app(CaptureInvoiceVersion::class)->handle($c['actor'], $invoice, [
        'capture_key' => (string) Str::uuid(),
        'source_amount' => $amount,
        'source_currency' => 'USDC',
        'source_evidence' => 'synthetic-mandate-bill',
        'business_approval_reference' => 'synthetic-contract-period',
        'department' => 'IT department',
        'period_start' => now()->toDateString(),
        'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '1',
        'rate_source' => 'identity-reference',
        'rate_observed_at' => now()->subMinute()->toIso8601String(),
        'rounding' => 'down',
    ]);

    app(ReviewInvoiceVersion::class)->handle($c['reviewer'], $bill, $bill->snapshot_digest, 'approve_evidence', 'Synthetic source checked.');

    return $bill->id;
}

function scannerBalance(string $native = '100000000000000000007'): void
{
    $arc = Mockery::mock(ArcNetworkGateway::class);

    if (! $arc instanceof ArcNetworkGateway) {
        throw new RuntimeException('Mockery did not build an Arc gateway.');
    }

    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    mockExpectation($arc, 'rpc')->andReturnUsing(fn (string $method): mixed => match ($method) {
        'eth_chainId' => '0x'.BigInteger::of(5042002)->toBase(16),
        'eth_getBalance' => '0x'.BigInteger::of($native)->toBase(16),
        'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64),
            'timestamp' => '0x'.BigInteger::of(now()->timestamp - 1)->toBase(16)],
        default => throw new RuntimeException('Unexpected Arc read: '.$method),
    });
    app()->instance(ArcNetworkGateway::class, $arc);
}

/** @param  array<string, mixed>  $c */
function scannerLiveMandate(array $c, RecurringMandate $mandate): RecurringMandate
{
    // A reviewed, live funding window is a precondition for release, so the
    // scanner has something real to read rather than an assumed reserve.
    $snapshot = BudgetSnapshot::query()->where('organization_id', $c['institution']->id)
        ->where('budget_id', $c['budget']->id)->latest('id')->firstOrFail();

    scannerBalance();
    $window = app(PrepareFundingWindow::class)->handle($c['actor'], $snapshot, $c['wallet'],
        (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
    app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Synthetic exclusive funds checked.');

    return $mandate->fresh();
}

/**
 * Prepare and approve a mandate with explicit caps, returning the approved record.
 *
 * @param  array<string, mixed>  $c
 */
function prepareAndApprove(array $c, string $ceiling = '5000000', string $daily = '10000000', string $period = '50000000'): RecurringMandate
{
    $input = mandateInput($c, $ceiling);
    $input['daily_limit_base_units'] = $daily;
    $input['period_limit_base_units'] = $period;

    $mandate = app(PrepareRecurringMandate::class)->handle($c['actor'], $input, (string) Str::uuid());
    approveMandate($c, $mandate);

    return $mandate->fresh();
}

/**
 * A reviewed budget snapshot containing the bills, without any funding window.
 *
 * The closed set is what makes a bill eligible at all; the funding window is a
 * separate, later precondition. Keeping them separable is what lets a test hold
 * one open and the other shut.
 *
 * @param  array<string, mixed>  $c
 * @param  array<int, int>  $billIds
 */
function captureClosedSet(array $c, array $billIds): void
{
    app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => 'IT department',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => $billIds, 'allocation' => '100', 'already_spent' => '0',
        'other_budget_commitments' => '0', 'opening_funds' => '100', 'realized_receipts' => '0', 'actual_outflows' => '0',
        'restricted_cash' => '0', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
        'budget_evidence' => 'synthetic-allocation', 'cash_evidence' => 'synthetic-opening',
        'commitment_evidence' => 'synthetic-obligations', 'commitments_exclude_selected_bills' => true,
        'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true, 'collection_review_ids' => [],
    ]);
}

/**
 * An approved mandate plus a reviewed, live funding window for its budget.
 *
 * The window is a precondition for release, so the scanner has something real
 * to read rather than an assumed reserve.
 *
 * @param  array<string, mixed>  $c
 * @param  array<int, int>  $billIds
 */
function approveAndFund(array $c, string $ceiling = '5000000', array $billIds = [],
    string $daily = '10000000', string $period = '50000000'): RecurringMandate
{
    $mandate = prepareAndApprove($c, $ceiling, $daily, $period);

    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => 'IT department',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => $billIds, 'allocation' => '100', 'already_spent' => '0',
        'other_budget_commitments' => '0', 'opening_funds' => '100', 'realized_receipts' => '0', 'actual_outflows' => '0',
        'restricted_cash' => '0', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
        'budget_evidence' => 'synthetic-allocation', 'cash_evidence' => 'synthetic-opening',
        'commitment_evidence' => 'synthetic-obligations', 'commitments_exclude_selected_bills' => true,
        'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true, 'collection_review_ids' => [],
    ]);

    scannerBalance();
    $window = app(PrepareFundingWindow::class)->handle($c['actor'], $snapshot, $c['wallet'],
        (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
    app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Synthetic exclusive funds checked.');

    return $mandate->fresh();
}

test('the tick does nothing at all while the mandate runtime is off', function (): void {
    $c = mandateContext();
    scannerBills($c);
    scannerBalance();

    config(['eduflow.mandate.runtime_enabled' => false]);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);

    expect($summary['scanned'])->toBe(0)
        ->and(MandateOccurrence::query()->count())->toBe(0);
});

test('an approved mandate releases a due bill and records a visible occurrence', function (): void {
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    $mandate = approveAndFund($c, '5000000', $billIds);
    config(['eduflow.mandate.runtime_enabled' => true]);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);

    expect($summary['release'])->toBe(1)
        ->and(MandateOccurrence::query()->count())->toBe(1);

    $occurrence = MandateOccurrence::query()->firstOrFail();

    // Automatic work is visible even though nobody was notified.
    expect($occurrence->disposition)->toBe('release')
        ->and($occurrence->checks['passed'])->toBeTrue()
        ->and($occurrence->checks['summary'])->toContain('Every release check passed')
        ->and($occurrence->hasValidEvidence())->toBeTrue()
        ->and($occurrence->checks_digest)->toBe(PaymentIntent::digest($occurrence->checks));
});

test('the tick never reaches a rail and never reserves anything', function (): void {
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    approveAndFund($c, '5000000', $billIds);
    config(['eduflow.mandate.runtime_enabled' => true]);

    app(MandateOccurrenceScanner::class)->scan($c['institution']);

    // A released occurrence is authority to skip one approval, not to pay.
    expect(PaymentReservation::query()->count())->toBe(0)
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0)
        ->and(TransactionModel::query()->count())->toBe(0)
        ->and(MandateOccurrence::query()->firstOrFail()->evidence()['can_execute'])->toBeFalse();
});

test('re-running a tick records nothing new rather than paying twice', function (): void {
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    approveAndFund($c, '5000000', $billIds);
    config(['eduflow.mandate.runtime_enabled' => true]);

    $scanner = app(MandateOccurrenceScanner::class);
    $scanner->scan($c['institution']);
    $second = $scanner->scan($c['institution']);

    expect($second['release'])->toBe(0)
        ->and($second['skipped'])->toBe(1)
        ->and(MandateOccurrence::query()->count())->toBe(1);
});

test('a reviewed bill outside the human-selected closed set is not an occurrence', function (): void {
    // Both bills are independently reviewed, and both are for the mandate's
    // vendor and budget. Only one was put in the allocation a human chose. The
    // other is well-formed and payable, and the lane still must not touch it --
    // otherwise a standing mandate quietly widens its own reach to every
    // invoice the vendor ever sends.
    $c = mandateContext();
    $selected = scannerBills($c);
    $unselected = scannerBills($c, '2.000000');

    approveAndFund($c, '5000000', [$selected]);
    config(['eduflow.mandate.runtime_enabled' => true]);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);
    $recorded = MandateOccurrence::query()->get();

    expect($summary['release'])->toBe(1)
        ->and($recorded)->toHaveCount(1)
        ->and($recorded->firstOrFail()->checks['checked_bill_id'])->toBe($selected)
        ->and($recorded->firstOrFail()->checks['checked_bill_id'])->not->toBe($unselected);
});

test('a bill for this vendor and budget is the only kind picked up', function (): void {
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    $mandate = approveAndFund($c, '5000000', $billIds);
    config(['eduflow.mandate.runtime_enabled' => true]);

    app(MandateOccurrenceScanner::class)->scan($c['institution']);
    $recorded = MandateOccurrence::query()->firstOrFail();

    expect($recorded->recurring_mandate_id)->toBe($mandate->id);
});

test('without a reviewed funding window the occurrence is blocked, not assumed funded', function (): void {
    // The mandate is approved but no window has ever been reviewed for this
    // budget, so there is nothing to protect cash by.
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    prepareAndApprove($c);
    captureClosedSet($c, $billIds);
    scannerBalance();
    config(['eduflow.mandate.runtime_enabled' => true]);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);

    expect($summary['blocked'])->toBe(1)
        ->and(MandateOccurrence::query()->firstOrFail()->disposition)->toBe('blocked')
        ->and(MandateOccurrence::query()->firstOrFail()->checks['funding_window_valid']['passed'])->toBeFalse();
});

test('a bill above the per-occurrence ceiling escalates for a human', function (): void {
    $c = mandateContext();
    $billIds = [scannerBills($c, '9.000000')];
    approveAndFund($c, '5000000', $billIds);
    config(['eduflow.mandate.runtime_enabled' => true]);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);
    $occurrence = MandateOccurrence::query()->firstOrFail();

    expect($summary['escalate'])->toBe(1)
        ->and($occurrence->disposition)->toBe('escalate')
        ->and($occurrence->reason)->toContain('per-occurrence ceiling')
        ->and($occurrence->isReleased())->toBeFalse();
});

test('the daily cap escalates a second bill that would breach it', function (): void {
    // Two 6 USDC bills with a 10 USDC per-occurrence ceiling and a 10 USDC
    // daily limit. Each bill is individually fine; only the second breaches
    // the cumulative cap, and it goes to a human rather than quietly fitting.
    $c = mandateContext();
    $billIds = [scannerBills($c, '6.000000'), scannerBills($c, '6.000000')];
    approveAndFund($c, '10000000', $billIds, '10000000', '50000000');
    config(['eduflow.mandate.runtime_enabled' => true]);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);
    $escalated = MandateOccurrence::query()->where('disposition', 'escalate')->firstOrFail();

    expect($summary['release'])->toBe(1)
        ->and($summary['escalate'])->toBe(1)
        ->and($escalated->checks['daily_cap']['passed'])->toBeFalse()
        ->and($escalated->reason)->toContain('daily cumulative cap');
});

test('a revoked mandate releases nothing on the next tick', function (): void {
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    $mandate = approveAndFund($c, '5000000', $billIds);
    config(['eduflow.mandate.runtime_enabled' => true]);

    app(ReviewRecurringMandate::class)->handle($c['reviewer'], $mandate, $mandate->snapshot_digest,
        'revoke', 'Contract terminated.');

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);

    expect($summary['scanned'])->toBe(0)
        ->and(MandateOccurrence::query()->count())->toBe(0);
});

test('a zero-allowance mandate is not even scanned', function (): void {
    $c = mandateContext('0');
    $billIds = [scannerBills($c)];
    prepareAndApprove($c, '0');
    config(['eduflow.mandate.runtime_enabled' => true]);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);

    expect($summary['scanned'])->toBe(0)
        ->and(MandateOccurrence::query()->count())->toBe(0);
});

test('unreadable live evidence is recorded as blocked rather than skipped', function (): void {
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    approveAndFund($c, '5000000', $billIds);
    config(['eduflow.mandate.runtime_enabled' => true]);

    // The treasury read now fails.
    $arc = Mockery::mock(ArcNetworkGateway::class);

    if (! $arc instanceof ArcNetworkGateway) {
        throw new RuntimeException('Mockery did not build an Arc gateway.');
    }

    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    mockExpectation($arc, 'rpc')->andThrow(new RuntimeException('gateway timeout'));
    app()->instance(ArcNetworkGateway::class, $arc);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);
    $occurrence = MandateOccurrence::query()->firstOrFail();

    // Invisible would be worse: an unpayable obligation that nobody can see
    // is an obligation nobody knows is stuck.
    expect($summary['blocked'])->toBe(1)
        ->and($occurrence->disposition)->toBe('blocked')
        ->and($occurrence->checks['facts_readable']['passed'])->toBeFalse()
        ->and($occurrence->reason)->toContain('could not be read');
});

test('the scanner is gated in the service, not only at the command', function (): void {
    // A future job or endpoint could call the scanner directly. The lane being
    // off has to mean off for the installation, not just for one entry point.
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    approveAndFund($c, '5000000', $billIds);

    config(['eduflow.mandate.runtime_enabled' => false]);

    $summary = app(MandateOccurrenceScanner::class)->scan($c['institution']);

    expect($summary)->toBe(['scanned' => 0, 'release' => 0, 'escalate' => 0, 'blocked' => 0, 'skipped' => 0])
        ->and(MandateOccurrence::query()->count())->toBe(0);
});

test('the artisan command runs and reports the scan without a live scheduler', function (): void {
    // The command is the scheduled entry point, so its happy path needs its own
    // test: a summary key renamed in the scanner once left it throwing here,
    // and nothing caught it because the service tests do not call the command.
    $c = mandateContext();
    $billIds = [scannerBills($c)];
    approveAndFund($c, '5000000', $billIds);

    config(['eduflow.mandate.runtime_enabled' => true]);

    $this->artisan('eduflow:dispatch-mandate-occurrences')
        ->expectsOutputToContain('Mandates in scope 1; occurrences released 1, escalated 0, blocked 0; already recorded 0.')
        ->assertSuccessful();
});

test('the artisan command refuses to read anything while the lane is off', function (): void {
    $c = mandateContext();
    config(['eduflow.mandate.runtime_enabled' => false]);

    $this->artisan('eduflow:dispatch-mandate-occurrences')
        ->expectsOutputToContain('Mandate lane is disabled. Nothing was read or recorded.')
        ->assertSuccessful();
});
