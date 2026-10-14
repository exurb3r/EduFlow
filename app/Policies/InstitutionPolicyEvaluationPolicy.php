<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstitutionPolicyEvaluation;
use App\Models\Organization;
use App\Models\User;
use App\Services\InstallationInstitution;

class InstitutionPolicyEvaluationPolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function viewAny(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && ! $user->isImpersonated()
            && $user->hasAnyRole(['admin', 'super_admin']) && $this->institutions->current() instanceof Organization;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, InstitutionPolicyEvaluation $evaluation): bool
    {
        return $this->viewAny($user) && $evaluation->organization_id === $this->institutions->current()?->id;
    }
}
