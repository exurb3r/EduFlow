<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgentDecision;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AgentDecisionPolicy
{
    use HandlesAuthorization;

    public function before(AuthUser $authUser, string $ability): ?bool
    {
        if ($authUser->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AgentDecision');
    }

    public function view(AuthUser $authUser, AgentDecision $agentDecision): bool
    {
        return $authUser->can('View:AgentDecision');
    }
}
