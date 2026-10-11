<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\PaymentAuthorization;
use App\Models\PaymentReservation;
use App\Models\PaymentReservationRelease;
use App\Models\User;
use App\Services\InstallationInstitution;

/**
 * Capacity release is a two-person act on both levels.
 *
 * The proposer cannot be the staff member who took the hold, and the reviewer
 * cannot be either of them. Allowing the holder to undo their own reservation
 * would make the maker/checker separation on reservations decorative, so the
 * rule lives here rather than in the action that happens to enforce it.
 */
class PaymentReservationReleasePolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function view(User $user, PaymentReservationRelease $release): bool
    {
        $institution = $this->institutions->current();

        return $user->exists && $institution instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
            && $release->organization_id === $institution->id;
    }

    public function propose(User $user, PaymentReservation $reservation): bool
    {
        if (! $reservation->exists || ! $user->hasVerifiedEmail()
            || ! $user->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
            || $reservation->organization_id !== $this->institutions->current()?->id) {
            return false;
        }

        // Committed funds are not spare capacity, and a decision already made
        // must not become un-made through this route.
        if (PaymentAuthorization::query()->where('payment_reservation_id', $reservation->id)->exists()) {
            return false;
        }

        return $user->id !== $reservation->reserved_by;
    }

    public function review(User $user, PaymentReservationRelease $release): bool
    {
        if (! $release->exists || ! $this->view($user, $release) || ! $user->hasVerifiedEmail()) {
            return false;
        }

        // Review is a supervisory act, and it must be independent of both the
        // proposal and the original hold.
        if ($user->id === $release->proposed_by || ! $user->hasAnyRole(['admin', 'super_admin'])) {
            return false;
        }

        $reservation = $release->reservation;

        return $reservation instanceof PaymentReservation && $user->id !== $reservation->reserved_by;
    }
}
