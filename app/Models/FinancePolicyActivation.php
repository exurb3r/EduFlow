<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $finance_policy_version_id
 * @property int $approved_by
 * @property int|null $previous_activation_id
 * @property string|null $previous_activation_digest
 * @property FinancePolicyVersion|null $policyVersion
 * @property Carbon $created_at
 * @property string $policy_digest
 * @property string $activation_digest
 */
class FinancePolicyActivation extends Model
{
    protected $fillable = ['organization_id', 'finance_policy_version_id', 'approved_by', 'previous_activation_id', 'previous_activation_digest', 'policy_digest', 'activation_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $activation): void {
            /** @var FinancePolicyVersion|null $policy */
            $policy = FinancePolicyVersion::query()->whereKey($activation->finance_policy_version_id)->first();
            $current = self::current($activation->organization_id);
            if ($policy === null || ! $activation->hasValidEvidence($policy) || $current?->id !== $activation->previous_activation_id
                || $current?->activation_digest !== $activation->previous_activation_digest
                                || ($current instanceof FinancePolicyActivation && ! $current->hasValidHistory())) {
                throw new LogicException('Policy activation needs intact institution policy and a separate reviewer.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Policy activations are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Policy activation evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'finance_policy_version_id' => 'integer', 'approved_by' => 'integer', 'previous_activation_id' => 'integer'];
    }

    /** @return array<string, int|string|null> */
    public function content(): array
    {
        return [
            'schema_version' => 1,
            'institution_id' => $this->organization_id,
            'policy_version_id' => $this->finance_policy_version_id,
            'approved_by' => $this->approved_by,
            'previous_activation_id' => $this->previous_activation_id,
            'previous_activation_digest' => $this->previous_activation_digest,
            'policy_digest' => $this->policy_digest,
        ];
    }

    public function hasIntactDigest(): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $this->activation_digest) === 1
                    && hash_equals($this->activation_digest, PaymentIntent::digest($this->content()));
    }

    public function hasValidEvidence(FinancePolicyVersion $policy): bool
    {
        return $policy->hasValidContent() && $policy->id === $this->finance_policy_version_id
            && $policy->organization_id === $this->organization_id
            && $this->approved_by > 0 && $this->approved_by !== $policy->created_by
            && (($this->previous_activation_id === null && $this->previous_activation_digest === null)
                || ($this->previous_activation_id > 0 && is_string($this->previous_activation_digest)
                    && preg_match('/^[0-9a-f]{64}$/D', $this->previous_activation_digest) === 1))
            && hash_equals($policy->content_digest, $this->policy_digest) && $this->hasIntactDigest();
    }

    /**
     * Bounded, fail-closed verification of every earlier activation and its actual policy.
     * Hashes detect changed evidence, not privileged database rewrites with recomputed hashes.
     */
    public function hasValidHistory(): bool
    {
        if (! $this->exists) {
            return false;
        }

        /** @var Collection<int, self> $history */
        $history = self::query()->where('organization_id', $this->organization_id)
            ->where('id', '<=', $this->id)->with('policyVersion')->orderBy('id')->limit(10_001)->get();
        if ($history->isEmpty() || $history->count() > 10_000) {
            return false;
        }

        $previous = null;
        foreach ($history as $activation) {
            $policy = $activation->policyVersion;
            if ($policy === null || ! $activation->hasValidEvidence($policy)
                || $activation->previous_activation_id !== $previous?->id
                || $activation->previous_activation_digest !== $previous?->activation_digest) {
                return false;
            }
            $previous = $activation;
        }

        return $previous?->id === $this->id && $this->hasIntactDigest()
            && hash_equals($previous->activation_digest, $this->activation_digest);
    }

    /** @return BelongsTo<FinancePolicyVersion, $this> */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(FinancePolicyVersion::class, 'finance_policy_version_id');
    }

    public static function current(int $organizationId): ?self
    {
        /** @var self|null $activation */
        $activation = self::query()->where('organization_id', $organizationId)->orderByDesc('id')->first();

        return $activation;
    }
}
