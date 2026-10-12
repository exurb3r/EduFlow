<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FundingWindowApproval;
use App\Models\Organization;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentReservationRelease;
use App\Models\User;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Propose returning held bill + fee capacity to the department budget.
 *
 * A hold is released only when it will not be spent: the bill was withdrawn,
 * the draft was cancelled, or the obligation lapsed. An authorized payment can
 * never be released, because its funds are already committed to a submission
 * and "released" must not become a way to un-commit them.
 *
 * The reservation itself is never edited. Capacity comes back because a
 * separate append-only fact exists, so the cumulative chain stays verifiable.
 */
final readonly class ReleaseReservedCapacity
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(User $actor, PaymentReservation $reservation, string $requestKey, string $expectedDigest, string $reason): PaymentReservationRelease
    {
        Gate::forUser($actor)->authorize('propose', [PaymentReservationRelease::class, $reservation]);

        if (! Str::isUuid($requestKey) || ! hash_equals($reservation->snapshot_digest, $expectedDigest)
            || trim($reason) === '' || Str::length($reason) > 1000) {
            throw ValidationException::withMessages(['release' => 'A UUID, the exact held-reservation digest and a reason are required.']);
        }

        $requestKey = strtolower($requestKey);
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($actor, $reservation, $requestKey, $expectedDigest, $reason, $institution): PaymentReservationRelease {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();

            /** @var PaymentReservation $stored */
            $stored = PaymentReservation::query()->where('organization_id', $institution->id)->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('propose', [PaymentReservationRelease::class, $stored]);

            /** @var PaymentIntent|null $intent */
            $intent = PaymentIntent::query()->find($stored->payment_intent_id);

            if ($intent === null || ! $stored->hasValidEvidence($intent, FundingWindowApproval::query()->findOrFail($stored->funding_window_approval_id))) {
                throw ValidationException::withMessages(['release' => 'Held capacity evidence must be intact before it can be proposed for release.']);
            }

            if (! hash_equals($stored->snapshot_digest, $expectedDigest)) {
                throw ValidationException::withMessages(['expected_digest' => 'The exact stored reservation digest is required.']);
            }

            /** @var PaymentReservationRelease|null $existing */
            $existing = PaymentReservationRelease::query()->where('request_key', $requestKey)->first();

            if ($existing !== null) {
                if ($existing->payment_reservation_id !== $stored->id || $existing->proposed_by !== $actor->id
                    || $existing->reason !== $reason || $existing->source_digest !== $stored->snapshot_digest
                    || ! $existing->hasValidEvidence($stored)) {
                    throw ValidationException::withMessages(['request_key' => 'Release identity already binds a different hold, actor or feedback.']);
                }

                return $existing;
            }

            if (PaymentReservationRelease::query()->where('payment_reservation_id', $stored->id)->exists()) {
                throw ValidationException::withMessages(['release' => 'This hold already has a release proposal; capacity changes only through one reviewed fact.']);
            }

            // A submitted or authorized payment has committed funds. Releasing
            // it would be an un-commit path, not a capacity correction.
            if (PaymentAuthorization::query()->where('payment_reservation_id', $stored->id)->exists()) {
                throw ValidationException::withMessages(['release' => 'This hold has an authorization decision; committed funds are not released capacity.']);
            }

            $snapshot = [
                'schema_version' => 1,
                'institution_id' => $institution->id,
                'payment_reservation_id' => $stored->id,
                'payment_intent_id' => $stored->payment_intent_id,
                'invoice_id' => $stored->invoice_id,
                'funding_window_approval_id' => $stored->funding_window_approval_id,
                'request_key' => $requestKey,
                'proposed_by' => $actor->id,
                'reservation_digest' => $stored->snapshot_digest,
                'released_base_units' => (string) ($stored->amount_base_units + $stored->max_fee_base_units),
                'reason' => $reason,
            ];

            /**
             * content_digest is part of the validity proof, so the record is
             * assembled first and saved once it carries its own digest. A
             * `create()` here would insert an unprovable row.
             */
            $release = new PaymentReservationRelease([
                'request_key' => $requestKey,
                'organization_id' => $institution->id,
                'payment_reservation_id' => $stored->id,
                'proposed_by' => $actor->id,
                'reason' => $reason,
                'source_digest' => $stored->snapshot_digest,
                'snapshot' => $snapshot,
                'snapshot_digest' => PaymentIntent::digest($snapshot),
            ]);
            $release->content_digest = PaymentIntent::digest($release->content());
            $release->save();

            activity('finance')->causedBy($actor)->performedOn($release)->event('reservation_release_proposed')
                ->withProperties([
                    'payment_reservation_id' => $stored->id,
                    'released_base_units' => (string) ($stored->amount_base_units + $stored->max_fee_base_units),
                    'content_digest' => $release->content_digest,
                    'can_execute' => false,
                    'payments_submitted' => 0,
                ])
                ->log('Held bill and fee capacity proposed for release; no funds moved and nothing re-authorized');

            return $release;
        }, 3);
    }
}
