<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $funding_window_id
 * @property int $approved_by
 * @property string $verification_reference
 * @property string $window_digest
 * @property string $approval_digest
 * @property Carbon|null $superseded_at
 * @property int|null $superseded_by_approval_id
 * @property-read FundingWindowApproval|null $supersededBy
 */
class FundingWindowApproval extends Model
{
    protected $fillable = ['organization_id', 'funding_window_id', 'approved_by', 'verification_reference', 'window_digest', 'approval_digest'];

    /**
     * The only attributes an update may ever move.
     *
     * An approval's evidence is append-only: who reviewed what, against which
     * window, never changes once written. Retirement is the single exception,
     * and it is a state marker deliberately outside `content()` so retiring an
     * approval cannot invalidate a payment that was authorized while it was
     * live.
     *
     * @var array<int, string>
     */
    private const array RETIREMENT_COLUMNS = ['superseded_at', 'superseded_by_approval_id', 'updated_at'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            /** @var FundingWindow|null $window */
            $window = FundingWindow::query()->find($approval->funding_window_id);
            if ($window === null || ! $approval->hasValidEvidence($window)) {
                throw new LogicException('Funding approval requires independent review of intact exact evidence.');
            }
        });
        static::updating(function (self $approval): void {
            // Anything outside the retirement columns is a rewrite of evidence,
            // and a retirement that records no timestamp is not one either.
            if (array_diff(array_keys($approval->getChanges()), self::RETIREMENT_COLUMNS) !== []
                || ! $approval->superseded_at instanceof DateTimeInterface) {
                throw new LogicException('Funding approval evidence is append-only; only retirement may be recorded.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Funding approval evidence cannot be deleted.');
        });
    }

    /** The approval that replaced this one, when this window was rolled over. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_approval_id');
    }

    /**
     * A retired approval no longer grants capacity for a *new* hold.
     *
     * It stays fully valid evidence of what the reviewer approved, because
     * payments already authorized under it must remain authorized. Only
     * admission of fresh work checks this.
     */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function retire(FundingWindowApproval $successor): void
    {
        if ($this->id === $successor->id || $successor->superseded_at !== null) {
            throw new LogicException('An approval may only be retired by its own live successor.');
        }

        $this->superseded_at = Carbon::now();
        $this->superseded_by_approval_id = $successor->id;
        $this->save();
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'funding_window_id' => 'integer', 'approved_by' => 'integer',
            'superseded_by_approval_id' => 'integer', 'superseded_at' => 'datetime'];
    }

    /** @return array<string, int|string> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'funding_window_id' => $this->funding_window_id,
            'approved_by' => $this->approved_by, 'verification_reference' => $this->verification_reference, 'window_digest' => $this->window_digest];
    }

    public function hasValidEvidence(FundingWindow $window): bool
    {
        /** @var BudgetSnapshot|null $budget */
        $budget = BudgetSnapshot::query()->find($window->budget_snapshot_id);

        return $window->exists && $window->hasValidSnapshot() && $window->id === $this->funding_window_id
            && $window->organization_id === $this->organization_id && $budget !== null && $budget->hasValidSnapshot()
            && $budget->organization_id === $this->organization_id && $budget->budget_id === $window->budget_id
            && ($window->snapshot['budget_snapshot_digest'] ?? null) === $budget->snapshot_digest && $this->approved_by > 0
            && $this->approved_by !== $window->prepared_by && $this->approved_by !== $budget->prepared_by
            && trim($this->verification_reference) !== '' && mb_strlen($this->verification_reference) <= 255
            && hash_equals($window->snapshot_digest, $this->window_digest)
            && hash_equals($this->approval_digest, PaymentIntent::digest($this->content()));
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return ['id' => $this->id, 'funding_window_id' => $this->funding_window_id,
            'approval_digest' => $this->approval_digest, 'funds_reserved' => false, 'can_execute' => false,
            'local_accounts_changed' => false, 'superseded' => $this->isSuperseded()];
    }
}
