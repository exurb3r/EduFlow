<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * A human-authorized class of recurring payment.
 *
 * This is the document that lets a bill pay itself without a further approval.
 * It is emphatically **not** an agent's decision. §18.1 requires the opposite:
 * a standing human mandate decides the class of payment, deterministic PHP
 * decides each individual occurrence, and no model is in the release path at
 * all. An LLM cannot infer that a bill is recurring, so a human names the
 * obligation and its reference.
 *
 * The allowance starts at zero by construction — the ceilings below are records
 * of what a reviewer approved, not defaults that happen to be nonzero. An
 * approved mandate with a zero per-occurrence ceiling releases nothing.
 *
 * This model grants no execution authority. Its `evidence()` says so in every
 * array it returns, by design, and nothing may override that.
 *
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $budget_id
 * @property int $vendor_id
 * @property int $vendor_destination_version_id
 * @property int $wallet_id
 * @property int $finance_policy_version_id
 * @property string $obligation_reference
 * @property string $obligation_digest
 * @property string $chain
 * @property int $chain_id
 * @property string $currency
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $due_window_days
 * @property int $per_occurrence_ceiling_base_units
 * @property int $fee_ceiling_base_units
 * @property int $daily_limit_base_units
 * @property int $period_limit_base_units
 * @property string $state
 * @property int $prepared_by
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property-read Vendor $vendor
 * @property-read RecurringMandateReview|null $review
 * @property-read Collection<int, MandateOccurrence> $occurrences
 */
class RecurringMandate extends Model
{
    protected $fillable = ['request_key', 'organization_id', 'budget_id', 'vendor_id', 'vendor_destination_version_id',
        'wallet_id', 'finance_policy_version_id', 'obligation_reference', 'obligation_digest', 'chain',
        'chain_id', 'currency', 'starts_at', 'ends_at', 'due_window_days', 'per_occurrence_ceiling_base_units',
        'fee_ceiling_base_units', 'daily_limit_base_units', 'period_limit_base_units', 'state',
        'prepared_by', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $mandate): void {
            if ($mandate->state !== 'draft' || ! $mandate->hasValidSnapshot()) {
                throw new LogicException('Recurring mandates are born as drafts carrying intact signed scope evidence.');
            }
        });
        // Approved scope never moves. Widening a mandate is a new mandate with
        // its own review; editing this one would retroactively change what the
        // reviewer approved.
        static::updating(function (self $mandate): void {
            self::forbidEvidenceRewrite($mandate);
        });
        static::deleting(function (): never {
            throw new LogicException('Recurring mandate evidence cannot be deleted.');
        });
    }

    /**
     * Only the lifecycle state may move, and only forward.
     *
     * `draft` -> `approved` -> `revoked`, or `approved` -> `expired`. Every other
     * transition is refused, so an approved mandate cannot quietly revert to a
     * draft and be re-pointed at different evidence.
     */
    private const array SCOPE_COLUMNS = ['request_key', 'organization_id', 'budget_id', 'vendor_id',
        'vendor_destination_version_id', 'wallet_id', 'finance_policy_version_id', 'obligation_reference',
        'obligation_digest', 'chain', 'chain_id', 'currency', 'starts_at', 'ends_at', 'due_window_days',
        'per_occurrence_ceiling_base_units', 'fee_ceiling_base_units', 'daily_limit_base_units',
        'period_limit_base_units', 'prepared_by'];

    /**
     * `snapshot` is deliberately absent from SCOPE_COLUMNS.
     *
     * `snapshot_digest` is its integrity check: it is computed over the whole
     * structure, so any edit to any part of it changes the digest, and a
     * mismatched digest already fails `hasValidSnapshot()`. Comparing the raw
     * array as well would be both redundant and brittle — arrays compare by key
     * order and type, so a JSON round-trip that reorders keys would look like a
     * rewrite of evidence when nothing was rewritten.
     */

    /**
     * Only the lifecycle state may move, and only forward.
     *
     * `draft` -> `approved` -> `revoked`, or `approved` -> `expired`. A no-op is
     * permitted so the review action can stamp a digest it can only compute once
     * its row exists. Every other transition is refused, so an approved mandate
     * cannot quietly revert to a draft and be re-pointed at different evidence.
     */
    private static function forbidEvidenceRewrite(self $mandate): void
    {
        $from = $mandate->getOriginal('state');
        $to = $mandate->state;

        if ($from !== $to) {
            $permitted = [
                'draft' => ['approved', 'revoked'],
                'approved' => ['revoked', 'expired'],
            ];

            if (! in_array($to, $permitted[$from] ?? [], true)) {
                throw new LogicException('A recurring mandate may only move to a later lifecycle state; scope evidence never changes.');
            }
        }

        foreach (self::SCOPE_COLUMNS as $column) {
            if (self::attributeChanged($mandate, $column)) {
                throw new LogicException('Recurring mandate scope evidence is immutable once recorded: '.$column.'.');
            }
        }
    }

    /**
     * Has this column actually changed?
     *
     * Dates are compared by instant, not by storage format. The insert stores
     * the ISO string exactly as given and later reads normalize it, so a pair
     * of strings can differ while the moment is identical; comparing them
     * strictly would make an approved mandate impossible to transition.
     */
    private static function attributeChanged(self $mandate, string $column): bool
    {
        $now = $mandate->rawAttribute($column);
        $was = $mandate->getOriginal($column);

        if (in_array($column, ['starts_at', 'ends_at'], true)) {
            try {
                return Carbon::parse((string) $now)->getTimestamp() !== Carbon::parse((string) $was)->getTimestamp();
            } catch (Throwable) {
                return true;
            }
        }

        return $now !== $was;
    }

    private function rawAttribute(string $column): mixed
    {
        return $this->getAttributes()[$column] ?? null;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'budget_id' => 'integer', 'vendor_id' => 'integer',
            'vendor_destination_version_id' => 'integer', 'wallet_id' => 'integer', 'finance_policy_version_id' => 'integer',
            'chain_id' => 'integer', 'due_window_days' => 'integer', 'per_occurrence_ceiling_base_units' => 'integer',
            'fee_ceiling_base_units' => 'integer', 'daily_limit_base_units' => 'integer',
            'period_limit_base_units' => 'integer', 'prepared_by' => 'integer', 'snapshot' => 'array',
            'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $snapshot = $this->getAttribute('snapshot');
            $institution = $this->organization_id;

            return is_array($snapshot)
                && ($snapshot['schema_version'] ?? null) === 1
                && ($snapshot['purpose'] ?? null) === 'recurring_vendor_payment_mandate'
                && Str::isUuid($this->request_key) && $this->request_key === strtolower($this->request_key)
                && ($snapshot['request_key'] ?? null) === $this->request_key
                && ($snapshot['institution_id'] ?? null) === $institution
                && $institution > 0 && $this->prepared_by > 0
                && $this->budget_id > 0 && $this->vendor_id > 0 && $this->vendor_destination_version_id > 0
                && $this->wallet_id > 0 && $this->finance_policy_version_id > 0
                && $this->obligation_reference !== '' && mb_strlen($this->obligation_reference) <= 255
                && ($snapshot['obligation_reference'] ?? null) === $this->obligation_reference
                && ($snapshot['obligation_digest'] ?? null) === $this->obligation_digest
                && $this->chain === 'ARC-TESTNET' && $this->chain_id === 5042002
                && ($snapshot['chain'] ?? null) === $this->chain && ($snapshot['chain_id'] ?? null) === $this->chain_id
                && $this->currency === 'USDC'
                && ($snapshot['currency'] ?? null) === 'USDC'
                // The silver ceiling is the one that matters: without it, an
                // approved mandate must release nothing at all.
                && $this->per_occurrence_ceiling_base_units >= 0
                && $this->fee_ceiling_base_units >= 0
                && $this->daily_limit_base_units >= 0
                && $this->period_limit_base_units >= 0
                && $this->per_occurrence_ceiling_base_units <= $this->period_limit_base_units
                && $this->daily_limit_base_units <= $this->period_limit_base_units
                && $this->due_window_days > 0 && $this->due_window_days <= 365
                && $this->starts_at->lt($this->ends_at)
                && ($snapshot['starts_at'] ?? null) === $this->starts_at->toIso8601String()
                && ($snapshot['ends_at'] ?? null) === $this->ends_at->toIso8601String()
                && ($snapshot['due_window_days'] ?? null) === $this->due_window_days
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($snapshot));
        } catch (Throwable) {
            return false;
        }
    }

    /** Current, approved, inside its own dates, and not revoked. */
    public function isLive(?DateTimeInterface $at = null): bool
    {
        $at = $at instanceof DateTimeInterface ? Carbon::parse($at->format('Y-m-d H:i:s')) : now();

        return $this->state === 'approved' && $this->starts_at->lte($at) && $this->ends_at->gt($at);
    }

    /** An approved mandate with a zero ceiling authorizes nothing at all. */
    public function releasesNothing(): bool
    {
        return $this->per_occurrence_ceiling_base_units <= 0 || $this->state !== 'approved';
    }

    public function obligationIdentity(): string
    {
        return PaymentIntent::digest([
            'organization_id' => $this->organization_id,
            'vendor_id' => $this->vendor_id,
            'obligation_reference' => $this->obligation_reference,
            'obligation_digest' => $this->obligation_digest,
        ]);
    }

    /** @return HasOne<RecurringMandateReview, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(RecurringMandateReview::class);
    }

    /** @return HasMany<MandateOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(MandateOccurrence::class);
    }

    /** @return BelongsTo<Budget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<VendorDestinationVersion, $this> */
    public function vendorDestinationVersion(): BelongsTo
    {
        return $this->belongsTo(VendorDestinationVersion::class, 'vendor_destination_version_id');
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        $review = $this->review;

        return [
            'id' => $this->id,
            'request_key' => $this->request_key,
            'obligation_reference' => $this->obligation_reference,
            'state' => $this->state,
            'snapshot_digest' => $this->snapshot_digest,
            'snapshot_valid' => $this->hasValidSnapshot(),
            'per_occurrence_ceiling_base_units' => (string) $this->per_occurrence_ceiling_base_units,
            'fee_ceiling_base_units' => (string) $this->fee_ceiling_base_units,
            'daily_limit_base_units' => (string) $this->daily_limit_base_units,
            'period_limit_base_units' => (string) $this->period_limit_base_units,
            'review' => $review?->decision,
            'reviewed_by' => $review?->reviewed_by,
            'releases_nothing' => $this->releasesNothing(),
            'mandate_authorized' => false,
            'payment_approved' => false,
            'funds_reserved' => false,
            'external_funds_locked' => false,
            'can_execute' => false,
            'local_accounts_changed' => false,
        ];
    }
}
