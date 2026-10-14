<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An immutable record of one receipt allocation proposal.
 *
 * A plan is what the agent suggested, kept exactly as suggested. It is not a
 * decision: nothing here reserves funds, approves a payment, or changes a
 * budget. Finance approves a specific line separately, and the resulting
 * payment still goes through the ordinary payment intent path.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $budget_snapshot_id
 * @property int $institution_policy_version_id
 * @property string $request_key
 * @property string $currency
 * @property string $snapshot_digest
 * @property string $policy_digest
 * @property string $publication_digest
 * @property int $proposed_minor_units
 * @property int $unallocated_minor_units
 * @property array<string, mixed> $plan
 * @property string $plan_digest
 * @property-read BudgetSnapshot $budgetSnapshot
 * @property-read InstitutionPolicyVersion $policy
 */
class ReceiptAllocationPlan extends Model
{
    protected $fillable = ['organization_id', 'budget_snapshot_id', 'institution_policy_version_id', 'request_key', 'currency',
        'snapshot_digest', 'policy_digest', 'publication_digest', 'proposed_minor_units', 'unallocated_minor_units', 'plan', 'plan_digest'];

    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'budget_snapshot_id' => 'integer', 'institution_policy_version_id' => 'integer',
            'proposed_minor_units' => 'integer', 'unallocated_minor_units' => 'integer', 'plan' => 'array'];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $plan): void {
            if (! $plan->hasValidPlan()) {
                throw new LogicException('Allocation plan requires intact budget, policy and non-executable evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Allocation plans are immutable. Re-run the proposal against current evidence.');
        });
        static::deleting(function (): never {
            throw new LogicException('Allocation plan evidence cannot be deleted.');
        });
    }

    public function hasValidPlan(): bool
    {
        $snapshot = $this->budgetSnapshot;

        if (! $snapshot->exists || ! $this->policy->exists) {
            return false;
        }
        if ($snapshot->organization_id !== $this->organization_id || $this->policy->organization_id !== $this->organization_id) {
            return false;
        }
        if ($snapshot->snapshot_digest !== $this->snapshot_digest || $this->policy->content_digest !== $this->policy_digest) {
            return false;
        }
        if (! $this->policy->hasValidContent() || ! hash_equals($this->plan_digest, PaymentIntent::digest($this->plan))) {
            return false;
        }
        $plan = $this->plan;

        // The proposal must never look executable, must not total more than
        // what was captured as free headroom, and must never touch the
        // protected reserve. These are the invariants an officer relies on when
        // reading "5 USDC proposed".
        if (($plan['can_execute'] ?? null) !== false || ($plan['funds_reserved'] ?? null) !== false
            || ($plan['payment_approved'] ?? null) !== false || ($plan['actual_accounts_changed'] ?? null) !== false) {
            return false;
        }
        if (($plan['purpose'] ?? null) !== 'receipt_allocation_proposal_only'
            || ($plan['schema_version'] ?? null) !== 1
            || ($plan['snapshot_id'] ?? null) !== $this->budget_snapshot_id
            || ($plan['snapshot_digest'] ?? null) !== $this->snapshot_digest
            || ($plan['policy_version_id'] ?? null) !== $this->institution_policy_version_id
            || ($plan['currency'] ?? null) !== $this->currency
            || ($plan['budget_snapshot_id'] ?? $plan['snapshot_id']) !== $this->budget_snapshot_id) {
            return false;
        }
        $lines = $plan['lines'] ?? null;
        if (! is_array($lines) || $lines === [] || count($lines) > 100) {
            return false;
        }
        $proposed = 0;
        foreach ($lines as $line) {
            if (! is_array($line) || ($line['can_execute'] ?? true) !== false
                || ! in_array($line['decision'] ?? null, ['propose_human_review', 'hold'], true)
                || ! preg_match('/^(0|[1-9][0-9]*)$/D', (string) ($line['proposed_minor_units'] ?? ''))
                || ! preg_match('/^(0|[1-9][0-9]*)$/D', (string) ($line['amount_minor_units'] ?? ''))) {
                return false;
            }
            $grant = (int) $line['proposed_minor_units'];
            $asked = (int) $line['amount_minor_units'];
            // A grant can never exceed what was asked for, and a held line can
            // never carry a grant.
            if ($grant > $asked || ($grant > 0 && $line['decision'] !== 'propose_human_review')) {
                return false;
            }
            $proposed += $grant;
        }
        if ($proposed !== $this->proposed_minor_units || (string) $proposed !== (string) ($plan['proposed_minor_units'] ?? null)) {
            return false;
        }

        return $proposed <= (int) ($plan['headroom']['budget_minor_units'] ?? 0)
            && $proposed <= (int) ($plan['headroom']['cash_minor_units'] ?? 0)
            && ($plan['reserve_intact'] ?? false) === true;
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function budgetSnapshot(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class, 'budget_snapshot_id');
    }

    /** @return BelongsTo<InstitutionPolicyVersion, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(InstitutionPolicyVersion::class, 'institution_policy_version_id');
    }
}
