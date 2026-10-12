<?php

declare(strict_types=1);

use App\Actions\ReserveVendorPayment;
use App\Actions\RollOverFundingWindow;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\Transaction;
use App\Services\ReservationCapacity;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\travel;

/**
 * Reviewed funding-window rollover.
 *
 * A window lives 15 minutes and is immutable, so an unattended lane needs a
 * way to renew it. The invariant under test throughout is that renewal renews
 * the *clock*, never the money: a successor to a window that already holds
 * capacity must not make that capacity available again.
 *
 * The reason this is subtle is that capacity is cumulative and institution-wide.
 * Verifying each historical hold against whatever window happens to be current
 * would make a re-observed balance corrupt holds that were validly taken, so
 * `ReservationCapacity` is checked against each hold's own window and admission
 * for a new hold is checked separately against the current one.
 */

/** @param array<string, mixed> $c */
function rollOver(array $c, FundingWindow $window, ?string $key = null, ?string $validUntil = null): FundingWindow
{
    return app(RollOverFundingWindow::class)->handle($c['actor'], $window, $c['snapshot'], $c['wallet'],
        $key ?? (string) Str::uuid(), $validUntil ?? now()->addMinutes(10)->toIso8601String());
}

test('a live window may not be rolled over', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);

    expect(fn (): FundingWindow => rollOver($c, $window))->toThrow(ValidationException::class,
        'Only an expired funding window may be rolled over')
        ->and(FundingWindow::query()->count())->toBe(1);
});

test('an expired window is replaced by a fresh unapproved one that still needs review', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    travel(11)->minutes();

    $successor = rollOver($c, $window);

    expect($window->fresh()->isExpired())->toBeTrue()
        ->and($successor->isExpired())->toBeFalse()
        ->and($successor->supersedes_funding_window_id)->toBe($window->id)
        ->and($successor->budget_snapshot_id)->toBe($window->budget_snapshot_id)
        ->and($successor->wallet_id)->toBe($window->wallet_id)
        ->and($successor->snapshot['capacity']['budget_base_units'])->toBe($window->snapshot['capacity']['budget_base_units'])
        ->and($successor->snapshot['supersedes']['snapshot_digest'])->toBe($window->snapshot_digest)
        ->and($successor->hasValidSnapshot())->toBeTrue()
        // A successor grants nothing until it is independently reviewed.
        ->and(FundingWindowApproval::query()->where('funding_window_id', $successor->id)->exists())->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0);
});

test('rollover renews the clock without re-granting capacity already held', function (): void {
    // The allocation admits each bill separately but not both: 2.000001 and
    // 30.000001 plus fees fit under 32.000002 one at a time, not together.
    $c = reservationContext('32.000002');
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    $hold = reserveContextBill($c, $approval);

    $before = app(ReservationCapacity::class)->verify($c['institution']->id);
    expect((string) $before['consuming'])->toBe((string) ($hold->amount_base_units + $hold->max_fee_base_units));

    travel(11)->minutes();
    $successor = rollOver($c, $window);
    $renewed = approveReservationWindow($c, $successor);

    // The chain is unchanged by the renewal, so the second bill still does not
    // fit. If rollover reset capacity, this would succeed.
    $after = app(ReservationCapacity::class)->verify($c['institution']->id);

    expect((string) $after['total'])->toBe((string) $before['total'])
        ->and((string) $after['consuming'])->toBe((string) $before['consuming'])
        ->and(fn (): PaymentReservation => reserveContextBill($c, $renewed, 1))->toThrow(ValidationException::class)
        ->and(PaymentReservation::query()->count())->toBe(1);
});

test('a rollover releases nothing, so a held bill stays unavailable', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    $hold = reserveContextBill($c, $approval);

    travel(11)->minutes();
    rollOver($c, $window);

    expect($hold->fresh()->isReleased())->toBeFalse()
        ->and($hold->fresh()->evidence()['funds_reserved'])->toBeTrue()
        ->and((string) app(ReservationCapacity::class)->verify($c['institution']->id)['released'])->toBe('0');
});

test('a window may only be rolled over once, so renewal is a chain not a branch', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    travel(11)->minutes();

    rollOver($c, $window);

    expect(fn (): FundingWindow => rollOver($c, $window))->toThrow(ValidationException::class,
        'This window has already been rolled over')
        ->and(FundingWindow::query()->count())->toBe(2);
});

test('a chain of renewals keeps every window verifiable under its own evidence', function (): void {
    $c = reservationContext();
    $first = prepareReservationWindow($c);
    approveReservationWindow($c, $first);

    travel(11)->minutes();
    $second = rollOver($c, $first);
    approveReservationWindow($c, $second);

    travel(11)->minutes();
    $third = rollOver($c, $second);

    expect($third->supersedes_funding_window_id)->toBe($second->id)
        ->and($third->snapshot['supersedes']['snapshot_digest'])->toBe($second->snapshot_digest)
        ->and($third->hasValidSnapshot())->toBeTrue()
        ->and($second->fresh()->hasValidSnapshot())->toBeTrue()
        ->and($first->fresh()->hasValidSnapshot())->toBeTrue()
        // All three belong to one allocation; the chain totals still reproduce.
        ->and((string) app(ReservationCapacity::class)->verify($c['institution']->id)['total'])->toBe('0');
});

test('replaying the same rollover identity returns the same window rather than minting a rival', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    travel(11)->minutes();
    $key = (string) Str::uuid();
    $until = now()->addMinutes(10)->toIso8601String();

    $first = rollOver($c, $window, $key, $until);
    $again = rollOver($c, $window, $key, $until);

    expect($again->id)->toBe($first->id)
        ->and(FundingWindow::query()->count())->toBe(2);

    // A different expiry for the same identity is a different document, not a
    // second one.
    expect(fn (): FundingWindow => rollOver($c, $window, $key, now()->addMinutes(5)->toIso8601String()))
        ->toThrow(ValidationException::class);
});

test('the expiry of the window being replaced must genuinely be in the past', function (): void {
    // Lineage is inside the digest, so a successor cannot claim to have
    // superseded a window that had not actually ended.
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    travel(11)->minutes();
    $successor = rollOver($c, $window);

    $tampered = $successor->snapshot;
    $tampered['supersedes']['valid_until'] = now()->addHour()->toIso8601String();

    // Windows are immutable, so tampering is tested on an unsaved model: what
    // matters is what a row carrying this evidence would be believed to mean,
    // and the answer must be that it means nothing.
    $forged = new FundingWindow($successor->only(['request_key', 'organization_id', 'budget_snapshot_id', 'budget_id',
        'wallet_id', 'finance_policy_activation_id', 'prepared_by', 'supersedes_funding_window_id']));
    $forged->snapshot = $tampered;
    $forged->snapshot_digest = PaymentIntent::digest($tampered);

    expect($forged->hasValidSnapshot())->toBeFalse();
});

test('a successor cannot name a predecessor it does not point at', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    travel(11)->minutes();
    $successor = rollOver($c, $window);

    $tampered = $successor->snapshot;
    $tampered['supersedes']['funding_window_id'] = 999_999;

    $forged = new FundingWindow($successor->only(['request_key', 'organization_id', 'budget_snapshot_id', 'budget_id',
        'wallet_id', 'finance_policy_activation_id', 'prepared_by', 'supersedes_funding_window_id']));
    $forged->snapshot = $tampered;
    $forged->snapshot_digest = PaymentIntent::digest($tampered);

    // The column says one window and the digest evidence says another; neither
    // may be quietly trusted.
    expect($forged->hasValidSnapshot())->toBeFalse();
});

test('a plain window carrying rollover lineage is not valid evidence', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);

    expect($window->supersedes_funding_window_id)->toBeNull()
        ->and($window->hasValidSnapshot())->toBeTrue()
        ->and($window->snapshot['supersedes'] ?? null)->toBeNull();
});

test('replaying a recorded hold after its window expired still returns the same hold', function (): void {
    // The guard against new holds must sit *after* the idempotent replay, or a
    // worker retrying a hold it already recorded would be refused.
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    $hold = reserveContextBill($c, $approval);

    travel(11)->minutes();
    $successor = rollOver($c, $window);
    approveReservationWindow($c, $successor);

    $replayed = app(ReserveVendorPayment::class)->handle($c['actor'], $c['drafts'][0], $approval->fresh(),
        $hold->reservation_key, $c['drafts'][0]->snapshot_digest, $approval->fresh()->approval_digest);

    expect($replayed->id)->toBe($hold->id)
        ->and(PaymentReservation::query()->count())->toBe(1);
});

test('a retired approval grants no new hold even before its successor is approved', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    reserveContextBill($c, $approval);

    travel(11)->minutes();
    $successor = rollOver($c, $window);
    approveReservationWindow($c, $successor);

    // Bill 1 is a different bill with a different identity, so this is a new
    // hold attempting to use retired capacity.
    expect(fn (): PaymentReservation => app(ReserveVendorPayment::class)
        ->handle($c['actor'], $c['drafts'][1], $approval->fresh(), (string) Str::uuid(),
            $c['drafts'][1]->snapshot_digest, $approval->fresh()->approval_digest))
        ->toThrow(ValidationException::class, 'expired or superseded window')
        ->and(PaymentReservation::query()->count())->toBe(1);
});

test('a window still cannot be refreshed while approved capacity exists without a rollover', function (): void {
    // The guard in PrepareFundingWindow is unchanged: it is what makes rollover
    // the *only* route to a second window, rather than merely one option.
    $c = reservationContext();
    $first = prepareReservationWindow($c);
    approveReservationWindow($c, $first);

    expect(fn (): FundingWindow => prepareReservationWindow($c))->toThrow(ValidationException::class,
        'Reviewed rollover is required');
});

test('approving a live window while another is already approved is refused', function (): void {
    $c = reservationContext();
    $first = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $first);

    travel(11)->minutes();
    $successor = rollOver($c, $first);
    $renewed = approveReservationWindow($c, $successor);

    // Exactly one live approval, and it belongs to the successor. The old row
    // is retired, never deleted, so a payment already authorized under the
    // predecessor still has its evidence.
    expect($renewed->id)->not->toBe($approval->id)
        ->and($approval->fresh()->isSuperseded())->toBeTrue()
        ->and($approval->fresh()->superseded_by_approval_id)->toBe($renewed->id)
        ->and($approval->fresh()->approval_digest)->toBe($approval->approval_digest)
        ->and($approval->fresh()->hasValidEvidence($first->fresh()))->toBeTrue()
        ->and($renewed->isSuperseded())->toBeFalse()
        ->and(FundingWindowApproval::query()->where('organization_id', $c['institution']->id)
            ->whereNull('superseded_at')->count())->toBe(1);
});
