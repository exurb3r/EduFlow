<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

/**
 * One recurring obligation the evaluator looked at, and what it decided.
 *
 * The identity is the obligation *plus* its occurrence, and it is unique, which
 * is what makes "no prior fulfilment" enforceable rather than aspirational: the
 * same pair recorded twice is rejected by the database rather than paid twice.
 *
 * A `release` disposition is still not a payment. It says the deterministic
 * evaluator found every §18.5 check passing; the occurrence then travels the
 * same reviewed chain as anything else, with its own funding window, reservation
 * and authorization. What this record grants is: no *further human approval is
 * needed for this occurrence*, because a human already authorized the class.
 *
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $recurring_mandate_id
 * @property int|null $payment_intent_id
 * @property string $occurrence_digest
 * @property int $amount_base_units
 * @property string $chain
 * @property int $chain_id
 * @property Carbon $due_at
 * @property string $disposition
 * @property string|null $reason
 * @property array<string, mixed>|null $checks
 * @property string|null $checks_digest
 * @property-read RecurringMandate|null $mandate
 * @property-read PaymentIntent|null $intent
 */
class MandateOccurrence extends Model
{
    protected $fillable = ['request_key', 'organization_id', 'recurring_mandate_id', 'payment_intent_id',
        'occurrence_digest', 'amount_base_units', 'chain', 'chain_id', 'due_at', 'disposition',
        'reason', 'checks', 'checks_digest'];

    #[\Override]
    protected static function booted(): void
    {
        // The unique index on (mandate, occurrence) is the real guard; this
        // hook exists so the failure is legible rather than a raw constraint
        // violation, and so the draft's own identity must be intact.
        static::creating(function (self $occurrence): void {
            if ($occurrence->disposition === 'release' && ! $occurrence->hasValidEvidence()) {
                throw new LogicException('A released occurrence must carry its exact checks and identity.');
            }
        });
        static::updating(function (self $occurrence): void {
            throw new LogicException('Mandate occurrences are append-only; a decided occurrence cannot be re-decided.');
        });
        static::deleting(function (): never {
            throw new LogicException('Mandate occurrence evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'recurring_mandate_id' => 'integer', 'payment_intent_id' => 'integer',
            'amount_base_units' => 'integer', 'chain_id' => 'integer', 'checks' => 'array', 'due_at' => 'datetime'];
    }

    /**
     * The identity is the obligation plus the occurrence, bound to the mandate.
     *
     * A repeat of the same pair is the same occurrence, never a second payment,
     * which is why the database constrains it rather than a call site.
     */
    public function identity(RecurringMandate $mandate, string $occurrenceKey): string
    {
        return $mandate->obligationIdentity().':'.$occurrenceKey;
    }

    public function hasValidEvidence(): bool
    {
        try {
            return is_array($this->checks)
                && $this->organization_id > 0
                && $this->recurring_mandate_id > 0
                && $this->amount_base_units > 0
                && $this->chain === 'ARC-TESTNET'
                && $this->chain_id === 5042002
                && in_array($this->disposition, ['release', 'escalate', 'blocked'], true)
                && is_string($this->checks_digest)
                && hash_equals($this->checks_digest, PaymentIntent::digest($this->checks));
        } catch (Throwable) {
            return false;
        }
    }

    /** Does this occurrence permit work without a further human approval? */
    public function isReleased(): bool
    {
        return $this->disposition === 'release';
    }

    /** @return BelongsTo<RecurringMandate, $this> */
    public function mandate(): BelongsTo
    {
        return $this->belongsTo(RecurringMandate::class);
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'id' => $this->id,
            'request_key' => $this->request_key,
            'recurring_mandate_id' => $this->recurring_mandate_id,
            'payment_intent_id' => $this->payment_intent_id,
            'occurrence_digest' => $this->occurrence_digest,
            'disposition' => $this->disposition,
            'due_at' => $this->due_at->toIso8601String(),
            'amount_base_units' => (string) $this->amount_base_units,
            'chain' => $this->chain,
            'chain_id' => $this->chain_id,
            'checks' => $this->checks,
            'checks_digest' => $this->checks_digest,
            'checks_valid' => $this->hasValidEvidence(),
            'released' => $this->isReleased(),
            // A release is authority to skip one approval, not to move money.
            'payment_approved' => false,
            'funds_reserved' => false,
            'external_funds_locked' => false,
            'can_execute' => false,
            'local_accounts_changed' => false,
        ];
    }
}
