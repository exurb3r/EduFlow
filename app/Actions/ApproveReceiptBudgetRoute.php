<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\ReceiptBudgetRoute;
use App\Models\ReceiptBudgetRouteApproval;
use App\Models\User;
use App\Services\DepartmentBudgetPlanner;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ApproveReceiptBudgetRoute
{
    public function __construct(private InstallationInstitution $institutions, private DepartmentBudgetPlanner $planner) {}

    public function handle(User $reviewer, ReceiptBudgetRoute $route, string $expectedDigest, string $reason): ReceiptBudgetRouteApproval
    {
        Gate::forUser($reviewer)->authorize('approve', $route);
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $route, $expectedDigest, $reason, $institution): ReceiptBudgetRouteApproval {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $route = ReceiptBudgetRoute::query()->where('organization_id', $institution->id)->findOrFail($route->id);
            Gate::forUser($reviewer)->authorize('approve', $route);
            if (! $route->hasValidSnapshot() || $route->snapshot_digest !== $expectedDigest
                || $route->valid_until->lte(now()) || trim($reason) === '' || mb_strlen($reason) > 1000) {
                throw ValidationException::withMessages(['route' => 'Exact intact current route and independent review reason required.']);
            }
            $this->planner->handle($reviewer, $route->budgetSnapshot);
            $existing = $route->approval;
            if ($existing !== null) {
                if (! $existing->hasValidEvidence($route) || $existing->approved_by !== $reviewer->id || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['route' => 'Route approval already recorded with different evidence.']);
                }

                return $existing;
            }
            if (ReceiptBudgetRoute::query()->where('organization_id', $institution->id)->where('source_stream', $route->source_stream)
                ->where('currency', $route->currency)->whereHas('approval')->where('receipts_from', '<', $route->valid_until)
                ->where('valid_until', '>', $route->receipts_from)->exists()) {
                throw ValidationException::withMessages(['route' => 'Approved source windows cannot overlap across budgets or contexts. One receipt must have one explicit route.']);
            }
            $approval = new ReceiptBudgetRouteApproval(['organization_id' => $institution->id, 'receipt_budget_route_id' => $route->id,
                'approved_by' => $reviewer->id, 'route_digest' => $expectedDigest, 'reason' => $reason]);
            $approval->approval_digest = PaymentIntent::digest($approval->content());
            $approval->save();
            activity('finance')->causedBy($reviewer)->performedOn($approval)->event('receipt_budget_route_approved')
                ->withProperties(['approval_digest' => $approval->approval_digest, 'can_execute' => false])
                ->log('Receipt routing independently approved for non-executable successor planning only');

            return $approval;
        }, 3);
    }
}
