<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssistanceRequest;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AssistanceRequestPolicy
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
        return $authUser->can('ViewAny:AssistanceRequest');
    }

    public function view(AuthUser $authUser, AssistanceRequest $assistanceRequest): bool
    {
        return $authUser->can('View:AssistanceRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AssistanceRequest');
    }

    public function update(AuthUser $authUser, AssistanceRequest $assistanceRequest): bool
    {
        return $authUser->can('Update:AssistanceRequest');
    }

    public function delete(AuthUser $authUser, AssistanceRequest $assistanceRequest): bool
    {
        return $authUser->can('Delete:AssistanceRequest');
    }

    public function restore(AuthUser $authUser, AssistanceRequest $assistanceRequest): bool
    {
        return $authUser->can('Restore:AssistanceRequest');
    }

    public function forceDelete(AuthUser $authUser, AssistanceRequest $assistanceRequest): bool
    {
        return $authUser->can('ForceDelete:AssistanceRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AssistanceRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AssistanceRequest');
    }

    public function replicate(AuthUser $authUser, AssistanceRequest $assistanceRequest): bool
    {
        return $authUser->can('Replicate:AssistanceRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AssistanceRequest');
    }
}
