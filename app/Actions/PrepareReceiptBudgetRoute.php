<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\BudgetSnapshot;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\ReceiptBudgetRoute;
use App\Models\User;
use App\Services\DepartmentBudgetPlanner;
use App\Services\InstallationInstitution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PrepareReceiptBudgetRoute
{
    public function __construct(private InstallationInstitution $institutions, private DepartmentBudgetPlanner $planner) {}

    public function handle(User $actor, BudgetSnapshot $base, string $stream, string $receiptsFrom, string $validUntil, string $key): ReceiptBudgetRoute
    {
        Gate::forUser($actor)->authorize('create', ReceiptBudgetRoute::class);
        Gate::forUser($actor)->authorize('view', $base);
        Validator::make(['key' => $key, 'from' => $receiptsFrom, 'until' => $validUntil], [
            'key' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before:until'],
            'until' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
        ])->validate();
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($actor, $base, $stream, $receiptsFrom, $validUntil, $key, $institution): ReceiptBudgetRoute {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $base = BudgetSnapshot::query()->where('organization_id', $institution->id)->findOrFail($base->id);
            $this->planner->handle($actor, $base);
            if (isset($base->snapshot['receipt_routing'])) {
                throw ValidationException::withMessages(['budget_snapshot' => 'Routing requires staff-captured base context, not an automatically generated successor.']);
            }
            $route = new ReceiptBudgetRoute(['request_key' => Str::lower($key), 'organization_id' => $institution->id,
                'budget_snapshot_id' => $base->id, 'prepared_by' => $actor->id, 'source_stream' => $stream, 'currency' => $base->currency,
                'receipts_from' => Carbon::parse($receiptsFrom)->utc(), 'valid_until' => Carbon::parse($validUntil)->utc()]);
            $route->snapshot = $route->content($base);
            $route->snapshot_digest = PaymentIntent::digest($route->snapshot);
            if (! $route->hasValidSnapshot()) {
                throw ValidationException::withMessages(['route' => 'Current schema v3 USDC context, zero opening funds, exact digital source and original evidence expiry required.']);
            }
            $existing = ReceiptBudgetRoute::query()->where('request_key', $route->request_key)->first();
            if ($existing !== null) {
                if (! $existing->hasValidSnapshot() || $existing->snapshot_digest !== $route->snapshot_digest) {
                    throw ValidationException::withMessages(['key' => 'Routing identity already binds different context.']);
                }

                return $existing;
            }
            $route->save();
            activity('finance')->causedBy($actor)->performedOn($route)->event('receipt_budget_route_prepared')
                ->withProperties(['snapshot_digest' => $route->snapshot_digest, 'can_execute' => false])
                ->log('Exact receipt-to-budget planning context prepared; no allocation or spending authority');

            return $route;
        }, 3);
    }
}
