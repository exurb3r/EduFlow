<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReceiptBudgetRoute;
use App\Models\User;
use App\Services\InstallationInstitution;

class ReceiptBudgetRoutePolicy
{
    public function __construct(private readonly InstallationInstitution $institutions) {}

    public function viewAny(User $user): bool
    {
        return $user->exists && $user->hasVerifiedEmail() && $this->institutions->current() instanceof Organization
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin']);
    }

    public function view(User $user, ReceiptBudgetRoute $receiptBudgetRoute): bool
    {
        return $this->viewAny($user) && $receiptBudgetRoute->exists
            && $receiptBudgetRoute->organization_id === $this->institutions->current()?->id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function approve(User $user, ReceiptBudgetRoute $route): bool
    {
        return $this->view($user, $route) && $user->hasAnyRole(['admin', 'super_admin'])
            && $user->id !== $route->prepared_by && $user->id !== $route->budgetSnapshot->prepared_by;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ReceiptBudgetRoute $receiptBudgetRoute): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ReceiptBudgetRoute $receiptBudgetRoute): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ReceiptBudgetRoute $receiptBudgetRoute): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ReceiptBudgetRoute $receiptBudgetRoute): bool
    {
        return false;
    }
}
