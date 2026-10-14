<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\BudgetSnapshot;
use App\Models\PaymentIntent;
use App\Models\ReceiptAllocationPlan;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\ReceiptAllocationProposal;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final readonly class ProposeReceiptAllocation
{
    public function __construct(private ReceiptAllocationProposal $proposals, private InstallationInstitution $institutions) {}

    /**
     * Build and store an allocation proposal for a snapshot's realised cash.
     *
     * Storing it is bookkeeping for the review queue, not a decision: the
     * stored plan is immutable and non-executable, and a repeat request with
     * the same key returns the original rather than re-proposing.
     */
    public function handle(User $actor, BudgetSnapshot $snapshot, string $requestKey, ?Closure $progress = null, bool $fresh = false): ReceiptAllocationPlan
    {
        Gate::forUser($actor)->authorize('view', $snapshot);
        $institution = $this->institutions->require();
        if ($snapshot->organization_id !== $institution->id) {
            throw ValidationException::withMessages(['budget' => 'Allocation planning is scoped to this institution.']);
        }

        $existing = ReceiptAllocationPlan::query()->where('request_key', $requestKey)->first();
        if ($existing !== null) {
            if ($existing->organization_id !== $institution->id || $existing->budget_snapshot_id !== $snapshot->id || ! $existing->hasValidPlan()) {
                throw ValidationException::withMessages(['allocation' => 'Proposal identity already binds different or damaged evidence.']);
            }

            return $existing;
        }
        $plan = $this->proposals->build($actor, $snapshot, $progress, $fresh ? $requestKey : null);

        return DB::transaction(function () use ($actor, $snapshot, $requestKey, $institution, $plan): ReceiptAllocationPlan {
            $snapshot = BudgetSnapshot::query()->where('organization_id', $institution->id)->findOrFail($snapshot->id);
            $digest = PaymentIntent::digest($plan);
            $existing = ReceiptAllocationPlan::query()->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                if ($existing->organization_id !== $institution->id || ! hash_equals($existing->plan_digest, $digest)) {
                    throw ValidationException::withMessages(['allocation' => 'Proposal identity already binds a different plan. Re-run against current evidence.']);
                }

                return $existing;
            }

            $record = new ReceiptAllocationPlan(['organization_id' => $institution->id, 'budget_snapshot_id' => $snapshot->id,
                'institution_policy_version_id' => $plan['policy_version_id'], 'request_key' => $requestKey, 'currency' => $snapshot->currency,
                'snapshot_digest' => $snapshot->snapshot_digest, 'policy_digest' => $plan['policy_digest'],
                'publication_digest' => $plan['publication_digest'], 'proposed_minor_units' => (int) $plan['proposed_minor_units'],
                'unallocated_minor_units' => (int) $plan['unallocated_minor_units'], 'plan' => $plan]);
            $record->plan_digest = $digest;
            $record->save();

            activity('finance')->causedBy($actor)->performedOn($record)->event('receipt_allocation_proposed')
                ->withProperties(['proposed_minor_units' => $record->proposed_minor_units, 'can_execute' => false,
                    'service_capability' => $plan['service_capability']])->log('Receipt allocation proposed; no funds reserved and no payment approved');

            return $record;
        }, 3);
    }

    public static function requestKey(BudgetSnapshot $snapshot, string $policyDigest): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:allocation-plan:'.$snapshot->snapshot_digest.':'.$policyDigest)->toString();
    }
}
