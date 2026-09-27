<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Transaction;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TransactionPolicy
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
        return $authUser->can('ViewAny:Transaction');
    }

    public function view(AuthUser $authUser, Transaction $transaction): bool
    {
        return $authUser->can('View:Transaction');
    }
}
