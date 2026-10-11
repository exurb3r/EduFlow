<?php

declare(strict_types=1);

use App\Actions\ProposePaymentIntentChange;
use App\Actions\ReleaseReservedCapacity;
use App\Actions\ReserveVendorPayment;
use App\Actions\ReviewPaymentIntentChange;
use App\Actions\ReviewReservationRelease;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentReservationRelease;
use App\Models\PaymentReservationReleaseReview;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReservationCapacity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Reviewed capacity release.
 *
 * The invariant under test throughout: a released hold still happened. It stays
 * in the cumulative chain, stays reproducible, and stays auditable. What
 * release changes is how much capacity is *consumed*, never what was recorded.
 */

/** @return array{proposal: PaymentReservationRelease, proposer: User} */
function proposeRelease(PaymentReservation $hold, string $reason = 'Bill withdrawn by the department.'): array
{
    $proposer = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));

    return [
        'proposal' => app(ReleaseReservedCapacity::class)->handle($proposer, $hold, (string) Str::uuid(), $hold->snapshot_digest, $reason),
        'proposer' => $proposer,
    ];
}

/** @param array<string, mixed> $c */
function approveRelease(array $c, PaymentReservation $hold, string $reason = 'Capacity returned.'): PaymentReservationReleaseReview
{
    $proposal = PaymentReservationRelease::query()->where('payment_reservation_id', $hold->id)->firstOrFail();

    return app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest, 'approve_release', $reason);
}

test('releasing a hold requires an independent reviewer and leaves the chain intact', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    $hold = reserveContextBill($c, $approval);

    ['proposal' => $proposal, 'proposer' => $proposer] = proposeRelease($hold);

    expect($proposal->hasValidEvidence($hold))->toBeTrue()
        ->and($proposal->evidence()['funds_reserved'])->toBeTrue()
        ->and($proposal->evidence()['decided'])->toBeFalse()
        ->and($hold->isReleased())->toBeFalse()
        ->and($hold->evidence()['state'])->toBe('held');

    // Neither the proposer nor the original holder may decide the release.
    expect(fn () => app(ReviewReservationRelease::class)->handle($proposer, $proposal, $proposal->content_digest,
        'approve_release', 'Self review.'))->toThrow(AuthorizationException::class);

    expect(fn () => app(ReviewReservationRelease::class)->handle($c['actor'], $proposal, $proposal->content_digest,
        'approve_release', 'Undoing my own hold.'))->toThrow(AuthorizationException::class);

    $review = app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest,
        'approve_release', 'Bill withdrawn; capacity returned.');

    expect($review->hasValidEvidence($proposal))->toBeTrue()
        ->and($hold->fresh()->isReleased())->toBeTrue()
        ->and($hold->fresh()->evidence()['state'])->toBe('released')
        ->and($hold->fresh()->evidence()['funds_reserved'])->toBeFalse();

    // The chain is untouched: the hold keeps its recorded position and totals,
    // because what happened cannot be rewritten by what was decided later.
    expect($hold->snapshot['prior_reserved_base_units'])->toBe('0')
        ->and($hold->snapshot_digest)->toBe($hold->fresh()->snapshot_digest);

    $chain = app(ReservationCapacity::class)->verify($c['institution']->id);
    $held = (string) ($hold->amount_base_units + $hold->max_fee_base_units);

    expect((string) $chain['total'])->toBe($held)
        ->and((string) $chain['consuming'])->toBe('0')
        ->and((string) $chain['released'])->toBe($held);
});

test('released capacity becomes spendable again while cumulative history still reproduces', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);

    $first = reserveContextBill($c, $approval);
    $second = reserveContextBill($c, $approval, 1);

    expect($first->snapshot['prior_reserved_base_units'])->toBe('0')
        ->and($second->snapshot['prior_reserved_base_units'])
        ->toBe((string) ($first->amount_base_units + $first->max_fee_base_units));

    proposeRelease($first);
    approveRelease($c, $first, 'First bill withdrawn.');

    $chain = app(ReservationCapacity::class)->verify($c['institution']->id);

    // The second hold still consumes; only the first stopped.
    expect((string) $chain['consuming'])->toBe((string) ($second->amount_base_units + $second->max_fee_base_units))
        ->and((string) $chain['total'])->toBe((string) ($first->amount_base_units + $first->max_fee_base_units
            + $second->amount_base_units + $second->max_fee_base_units))
        ->and((string) $chain['released'])->toBe((string) ($first->amount_base_units + $first->max_fee_base_units));
});

test('a release cannot un-commit funds that were already authorized', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);

    // Written straight past the model guard: the point under test is that the
    // policy refuses a release whenever an authorization exists, regardless of
    // how that row was produced.
    DB::table('payment_authorizations')->insert([
        'request_key' => (string) Str::uuid(), 'organization_id' => $c['institution']->id,
        'payment_intent_id' => $hold->payment_intent_id, 'payment_reservation_id' => $hold->id,
        'invoice_id' => $hold->invoice_id, 'reviewed_by' => $c['reviewer']->id,
        'decision' => 'hold_payment', 'mfa_method' => 'fortify_totp', 'factor_fingerprint' => str_repeat('a', 64),
        'mfa_timestep' => 1, 'snapshot' => '{}', 'snapshot_digest' => str_repeat('b', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $proposer = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));

    expect(Gate::forUser($proposer)->allows('propose', [PaymentReservationRelease::class, $hold]))->toBeFalse()
        ->and(fn () => app(ReleaseReservedCapacity::class)->handle($proposer, $hold, (string) Str::uuid(),
            $hold->snapshot_digest, 'Trying to un-commit.'))->toThrow(AuthorizationException::class)
        ->and($hold->fresh()->isReleased())->toBeFalse();
});

test('a hold cannot be released twice and a decided release is final', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);

    ['proposal' => $proposal] = proposeRelease($hold);

    expect(fn (): array => proposeRelease($hold))->toThrow(ValidationException::class);

    app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest,
        'approve_release', 'Capacity returned.');

    // A changed decision is refused rather than appended as a second fact.
    expect(fn () => app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest,
        'reject', 'Changed my mind.'))->toThrow(ValidationException::class);

    expect(PaymentReservationReleaseReview::query()->where('payment_reservation_id', $hold->id)->count())->toBe(1);
});

test('identical retry returns recorded evidence without deciding twice', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);

    ['proposal' => $proposal] = proposeRelease($hold);

    $first = app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest,
        'approve_release', 'Capacity returned.');
    $again = app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest,
        'approve_release', 'Capacity returned.');

    expect($again->id)->toBe($first->id)
        ->and(PaymentReservationReleaseReview::query()->count())->toBe(1);
});

test('a reject returns no capacity at all', function (): void {
    $c = reservationContext();
    $window = prepareReservationWindow($c);
    $approval = approveReservationWindow($c, $window);
    $hold = reserveContextBill($c, $approval);

    ['proposal' => $proposal] = proposeRelease($hold);
    app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest,
        'reject', 'Bill is still owed; the hold stands.');

    expect($hold->fresh()->isReleased())->toBeFalse()
        ->and($hold->fresh()->evidence()['funds_reserved'])->toBeTrue()
        ->and((string) app(ReservationCapacity::class)->verify($c['institution']->id)['consuming'])
        ->toBe((string) ($hold->amount_base_units + $hold->max_fee_base_units));
});

test('release records are immutable and decisions are constrained at the database', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);
    ['proposal' => $proposal] = proposeRelease($hold);
    $review = approveRelease($c, $hold);

    $proposal->reason = 'Rewritten';

    expect(fn () => $proposal->save())->toThrow(LogicException::class);

    $review->decision = 'reject';

    expect(fn () => $review->save())->toThrow(LogicException::class);
    expect(fn () => $proposal->delete())->toThrow(LogicException::class);

    // A fabricated authority decision is refused at the storage boundary too.
    expect(fn () => DB::table('payment_reservation_release_reviews')->insert([
        'organization_id' => $c['institution']->id, 'payment_reservation_release_id' => $proposal->id,
        'payment_reservation_id' => $hold->id, 'reviewed_by' => $c['reviewer']->id, 'decision' => 'invent_authority',
        'reason' => 'Not a real decision.', 'proposal_digest' => str_repeat('c', 64), 'review_digest' => str_repeat('d', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the schema allows a second reservation row once the first is released, and the action still refuses a second live hold', function (): void {
    // UNIQUE(invoice_id) made a released bill permanently un-reservable: the
    // row has to stay, because capacity history must reproduce, and it also
    // blocked the new hold. History is append-only, so the second row must be
    // storable. What must remain impossible is two holds consuming capacity at
    // once, and that lives in the action, not the schema.
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);

    // While the hold is live, admitting another one on the same bill is refused.
    $draft = PaymentIntent::query()->findOrFail($hold->payment_intent_id);

    expect(fn () => app(ReserveVendorPayment::class)->handle($c['actor'], $draft, $approval,
        (string) Str::uuid(), $hold->snapshot['intent_digest'], $approval->approval_digest,
    ))->toThrow(ValidationException::class);

    proposeRelease($hold);
    approveRelease($c, $hold);

    // After a reviewed release the row is inert, and the schema no longer
    // forbids the history that a re-reservation would add. A second row on the
    // same bill, under a different draft, is now storable.
    expect($hold->fresh()->isReleased())->toBeTrue();

    DB::table('payment_reservations')->insert([
        'reservation_key' => (string) Str::uuid(),
        'organization_id' => $hold->organization_id,
        'payment_intent_id' => $c['drafts'][1]->id,
        'invoice_id' => $hold->invoice_id,
        'funding_window_approval_id' => $hold->funding_window_approval_id,
        'reserved_by' => $hold->reserved_by,
        'amount_base_units' => $hold->amount_base_units,
        'max_fee_base_units' => $hold->max_fee_base_units,
        'snapshot' => '{}',
        'snapshot_digest' => str_repeat('e', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(PaymentReservation::query()->where('invoice_id', $hold->invoice_id)->count())->toBe(2)
        // One hold per draft is still a database guarantee, which is what
        // actually protects against double-holding the same authorization.
        ->and(fn () => DB::table('payment_reservations')->insert([
            'reservation_key' => (string) Str::uuid(),
            'organization_id' => $hold->organization_id,
            'payment_intent_id' => $c['drafts'][1]->id,
            'invoice_id' => $hold->invoice_id,
            'funding_window_approval_id' => $hold->funding_window_approval_id,
            'reserved_by' => $hold->reserved_by,
            'amount_base_units' => $hold->amount_base_units,
            'max_fee_base_units' => $hold->max_fee_base_units,
            'snapshot' => '{}',
            'snapshot_digest' => str_repeat('f', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
});

test('a released hold no longer blocks draft recovery', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);
    $draft = PaymentIntent::query()->findOrFail($hold->payment_intent_id);

    expect(fn () => app(ProposePaymentIntentChange::class)->handle($c['actor'], $draft, (string) Str::uuid(),
        $draft->snapshot_digest, 'cancel', 'Withdrawn.'))->toThrow(ValidationException::class);

    proposeRelease($hold);
    approveRelease($c, $hold);

    $change = app(ProposePaymentIntentChange::class)->handle($c['actor'], $draft, (string) Str::uuid(),
        $draft->snapshot_digest, 'cancel', 'Bill withdrawn by the department.');

    expect($change->hasValidEvidence($draft))->toBeTrue();

    // The proposal is only half the recovery. This half was untested, and it
    // tested existence rather than liveness, so the released row kept blocking
    // the very approval the release exists to unblock -- leaving the bill
    // permanently un-recoverable after a correct release.
    $review = app(ReviewPaymentIntentChange::class)->handle($c['reviewer'], $change, $change->content_digest,
        'approve_change', 'Recovery independently reviewed.');

    expect($review->decision)->toBe('approve_change')
        ->and($review->retired_payment_intent_id)->toBe($draft->id);
});

test('a live hold still blocks draft recovery even if a release was merely proposed', function (): void {
    // The fix must not weaken the guard: an unreleased hold still holds
    // capacity, so recovery stays closed until the release is actually decided.
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);
    $draft = PaymentIntent::query()->findOrFail($hold->payment_intent_id);

    proposeRelease($hold);

    expect(fn () => app(ProposePaymentIntentChange::class)->handle($c['actor'], $draft, (string) Str::uuid(),
        $draft->snapshot_digest, 'cancel', 'Withdrawn.'))->toThrow(ValidationException::class);
});

test('release never moves funds, authorizes a payment or creates an executor', function (): void {
    $c = reservationContext();
    $approval = approveReservationWindow($c, prepareReservationWindow($c));
    $hold = reserveContextBill($c, $approval);
    ['proposal' => $proposal] = proposeRelease($hold);
    $review = approveRelease($c, $hold);

    expect($review->evidence())->toMatchArray([
        'payment_approved' => false,
        'external_funds_locked' => false,
        'can_execute' => false,
        'local_accounts_changed' => false,
    ])->and($proposal->evidence()['can_execute'])->toBeFalse()
        ->and($hold->fresh()->evidence()['payment_approved'])->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0)
        ->and(PaymentAuthorization::query()->where('payment_reservation_id', $hold->id)->count())->toBe(0);
});
