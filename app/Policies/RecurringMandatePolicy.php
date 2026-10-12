<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\RecurringMandate;
use App\Models\User;
use App\Services\InstallationInstitution;

/**
 * Who may write, approve or revoke a standing mandate.
 *
 * Two rules do the work here.
 *
 * The approver must not be the writer. That separation is enforced again in
 * `ReviewRecurringMandate` against real identities rather than a role, because
 * §14.7 forbids anyone authoring their own mandate and a policy cannot see which
 * human is which.
 *
 * The approver must be a human being. `super_admin` is a human role; nothing
 * here admits a service account, a sessionless job or an agent, because
 * "approved" has to mean a person decided.
 */
class RecurringMandatePolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function create(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, RecurringMandate $mandate): bool
    {
        return $mandate->exists && $this->create($user)
            && $mandate->organization_id === $this->institutions->current()?->id;
    }

    public function approve(User $user, RecurringMandate $mandate): bool
    {
        if (! $this->view($user, $mandate)) {
            return false;
        }

        // An approver holds a different capability from a preparer, and never
        // the same identity — checked by the action, which has the record.
        return $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']) && $user->id !== $mandate->prepared_by
            && ! $user->hasRole('service_account');
    }

    public function release(User $user, RecurringMandate $mandate): bool
    {
        // Automatic releases are made by the evaluator, not by a person, so
        // nobody may trigger one here. Admitting a human "release" would be a
        // second approval path that bypasses the mandate.
        return $this->view($user, $mandate) && config('eduflow.mandate.runtime_enabled', false) === true
            && $user->hasPermissionTo('ReleaseMandate:RecurringMandate');
    }
}
