<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class InstitutionPolicyVersionPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, InstitutionPolicyVersion $institutionPolicyVersion): bool
    {
        return $this->viewAny($user) && $institutionPolicyVersion->exists
            && $institutionPolicyVersion->organization_id === $this->institutions->current()?->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function publish(User $user, InstitutionPolicyVersion $version): bool
    {
        return $this->view($user, $version) && $user->id !== $version->created_by && $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, InstitutionPolicyVersion $institutionPolicyVersion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InstitutionPolicyVersion $institutionPolicyVersion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, InstitutionPolicyVersion $institutionPolicyVersion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, InstitutionPolicyVersion $institutionPolicyVersion): bool
    {
        return false;
    }
}
