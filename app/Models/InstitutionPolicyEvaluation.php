<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One fresh advisory run. Concluded results and request identities never change.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $institution_policy_version_id
 * @property int $requested_by
 * @property string $request_key
 * @property string $source_type
 * @property int $source_id
 * @property string $source_digest
 * @property string $policy_digest
 * @property string $publication_digest
 * @property string $reason
 * @property array<string, mixed> $input
 * @property string $input_digest
 * @property string $state
 * @property string $stage
 * @property int $completed_targets
 * @property int $total_targets
 * @property list<array{stage:string,message:string,at:string}> $timeline
 * @property array<string, mixed>|null $result
 * @property string|null $result_digest
 * @property int|null $receipt_allocation_plan_id
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $last_error
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class InstitutionPolicyEvaluation extends Model
{
    protected $fillable = ['organization_id', 'institution_policy_version_id', 'requested_by', 'request_key', 'source_type',
        'source_id', 'source_digest', 'policy_digest', 'publication_digest', 'reason', 'input', 'input_digest', 'state', 'stage',
        'completed_targets', 'total_targets', 'timeline', 'result', 'result_digest', 'receipt_allocation_plan_id', 'provider',
        'model', 'last_error', 'started_at', 'completed_at'];

    protected $attributes = ['state' => 'queued', 'stage' => 'queued', 'completed_targets' => 0, 'total_targets' => 0];

    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'institution_policy_version_id' => 'integer', 'requested_by' => 'integer',
            'source_id' => 'integer', 'input' => 'array', 'timeline' => 'array', 'result' => 'array',
            'completed_targets' => 'integer', 'total_targets' => 'integer', 'receipt_allocation_plan_id' => 'integer',
            'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $evaluation): void {
            if (! $evaluation->hasValidInput()) {
                throw new LogicException('Evaluation requires intact institution policy and source identity.');
            }
        });
        static::updating(function (self $evaluation): void {
            if ($evaluation->isDirty(['organization_id', 'institution_policy_version_id', 'requested_by', 'request_key',
                'source_type', 'source_id', 'source_digest', 'policy_digest', 'publication_digest', 'reason', 'input', 'input_digest'])
                || ($evaluation->getRawOriginal('completed_at') !== null && $evaluation->isDirty())) {
                throw new LogicException('Evaluation identity and concluded evidence are immutable. Request a new run.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Evaluation history cannot be deleted.');
        });
    }

    public function hasValidInput(): bool
    {
        $policy = InstitutionPolicyVersion::query()->find($this->institution_policy_version_id);
        $publication = InstitutionPolicyPublication::query()->where('organization_id', $this->organization_id)
            ->where('institution_policy_version_id', $this->institution_policy_version_id)->where('publication_digest', $this->publication_digest)->first();

        return $policy !== null && $policy->organization_id === $this->organization_id && $policy->hasValidContent()
            && $publication !== null && $publication->hasValidHistory()
            && $policy->content_digest === $this->policy_digest
            && in_array($this->source_type, ['collection', 'transaction', 'budget_snapshot'], true)
            && $this->source_id > 0 && $this->requested_by > 0
            && ($this->input['source_type'] ?? null) === $this->source_type
            && ($this->input['source_id'] ?? null) === $this->source_id
            && ($this->input['source_digest'] ?? null) === $this->source_digest
            && hash_equals($this->input_digest, PaymentIntent::digest($this->input));
    }

    public function hasValidResult(): bool
    {
        if ($this->source_type === 'budget_snapshot') {
            $plan = ReceiptAllocationPlan::query()->find($this->receipt_allocation_plan_id);
            if ($plan === null || ! $plan->hasValidPlan() || $plan->organization_id !== $this->organization_id
                || $plan->budget_snapshot_id !== $this->source_id || $plan->policy_digest !== $this->policy_digest
                || $plan->publication_digest !== $this->publication_digest || $plan->plan_digest !== $this->result_digest) {
                return false;
            }
        } elseif ($this->receipt_allocation_plan_id !== null || ($this->result['funding_context_bound'] ?? null) !== false) {
            return false;
        }

        return $this->hasValidInput() && in_array($this->state, ['completed', 'needs_review'], true)
            && $this->completed_at !== null && is_array($this->result) && is_string($this->result_digest)
            && ($this->result['can_execute'] ?? null) === false
            && ($this->result['funds_reserved'] ?? null) === false
            && ($this->result['payment_approved'] ?? null) === false
            && ($this->result['actual_accounts_changed'] ?? null) === false
            && ($this->result['policy_digest'] ?? null) === $this->policy_digest
            && hash_equals($this->result_digest, PaymentIntent::digest($this->result));
    }

    /** @param array<string, mixed> $attributes */
    public function checkpoint(string $stage, string $message, array $attributes = []): void
    {
        $timeline = $this->timeline;
        $timeline[] = ['stage' => $stage, 'message' => $message, 'at' => now()->utc()->toIso8601String()];
        $this->update([...$attributes, 'stage' => $stage, 'timeline' => $timeline]);
    }
}
