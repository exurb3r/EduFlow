<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssistanceRequest;
use App\Models\User;

class AssistanceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance_officer', 'super_admin']);
    }

    public function view(User $user, AssistanceRequest $assistanceRequest): bool
    {
        if ($this->viewAny($user)) {
            return true;
        }

        return $user->hasRole('student') && (
            $assistanceRequest->student()->where('user_id', $user->getKey())->exists()
            || $assistanceRequest->user_id === $user->getKey()
        );
    }

    public function create(User $user): bool
    {
        return $user->hasRole('student') && $user->student()->exists();
    }

    public function update(User $user, AssistanceRequest $assistanceRequest): bool
    {
        return false;
    }

    public function delete(User $user, AssistanceRequest $assistanceRequest): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, AssistanceRequest $assistanceRequest): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, AssistanceRequest $assistanceRequest): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, AssistanceRequest $assistanceRequest): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
