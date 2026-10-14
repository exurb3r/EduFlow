<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $institution_policy_version_id
 * @property int $approved_by
 * @property int $finance_policy_activation_id
 * @property int|null $previous_publication_id
 * @property string $policy_digest
 * @property string $reason
 * @property string $publication_digest
 * @property-read InstitutionPolicyVersion $version
 */
class InstitutionPolicyPublication extends Model
{
    protected $fillable = ['organization_id', 'institution_policy_version_id', 'approved_by', 'finance_policy_activation_id',
        'previous_publication_id', 'policy_digest', 'reason', 'publication_digest'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'institution_policy_version_id' => 'integer', 'approved_by' => 'integer',
            'finance_policy_activation_id' => 'integer', 'previous_publication_id' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        return $this->only(['organization_id', 'institution_policy_version_id', 'approved_by', 'finance_policy_activation_id',
            'previous_publication_id', 'policy_digest', 'reason']);
    }

    public function hasValidEvidence(): bool
    {
        try {
            $activation = FinancePolicyActivation::query()->find($this->finance_policy_activation_id);

            return $activation !== null && $activation->hasValidEvidence($this->version->financePolicy)
                && $activation->approved_by === $this->approved_by
                && $this->version->hasValidContent() && $this->version->organization_id === $this->organization_id
                && $this->version->created_by !== $this->approved_by && $this->version->content_digest === $this->policy_digest
                && hash_equals($this->publication_digest, PaymentIntent::digest($this->content()));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether this publication's own controls are the ones currently in force.
     *
     * A separately activated FinancePolicyVersion makes this publication's
     * limits stale while its clauses and categories stay published. The
     * distinction matters because one governs which controls payments are
     * measured against and the other governs what the model may cite, so
     * neither answer alone describes the institution correctly.
     */
    public function hasActiveControls(): bool
    {
        return FinancePolicyActivation::current($this->organization_id)?->id === $this->finance_policy_activation_id;
    }

    public function hasValidHistory(): bool
    {
        $history = self::query()->where('organization_id', $this->organization_id)->where('id', '<=', $this->id)
            ->orderBy('id')->limit(1001)->get();
        if ($history->isEmpty() || $history->count() > 1000) {
            return false;
        }
        $previous = null;
        foreach ($history as $publication) {
            if (! $publication->hasValidEvidence() || $publication->previous_publication_id !== $previous?->id
                || ($publication->version->content['expected_current_digest'] ?? null) !== $previous?->publication_digest) {
                return false;
            }
            $previous = $publication;
        }

        return $previous?->id === $this->id;
    }

    /** @return BelongsTo<InstitutionPolicyVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(InstitutionPolicyVersion::class, 'institution_policy_version_id');
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $publication): void {
            if (! $publication->hasValidEvidence()) {
                throw new LogicException('Publication requires exact independently reviewed policy.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Policy publications are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Policy publications cannot be deleted.');
        });
    }
}
