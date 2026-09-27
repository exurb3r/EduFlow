<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvoicePolicy
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
        return $authUser->can('ViewAny:Invoice');
    }

    public function view(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('View:Invoice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Invoice');
    }

    public function update(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Update:Invoice');
    }

    public function delete(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Delete:Invoice');
    }
}
