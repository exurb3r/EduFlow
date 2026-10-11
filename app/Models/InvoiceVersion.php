<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Services\CurrencyValuationCalculator;
use Database\Factories\InvoiceVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $capture_key
 * @property int $organization_id
 * @property int $invoice_id
 * @property int $budget_id
 * @property int $vendor_id
 * @property int $prepared_by
 * @property string $source_currency
 * @property int $source_minor_units
 * @property int $valuation_base_units
 * @property string $status
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property-read Invoice|null $invoice
 */
class InvoiceVersion extends Model
{
    /** @use HasFactory<InvoiceVersionFactory> */
    use HasFactory;

    protected $fillable = ['capture_key', 'organization_id', 'invoice_id', 'budget_id', 'vendor_id', 'prepared_by', 'source_currency', 'source_minor_units', 'valuation_base_units', 'snapshot', 'snapshot_digest'];

    protected $attributes = ['status' => 'captured'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $bill): void {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->whereKey($bill->invoice_id)->first();
            /** @var Budget|null $budget */
            $budget = Budget::query()->whereKey($bill->budget_id)->first();
            /** @var Vendor|null $vendor */
            $vendor = Vendor::query()->whereKey($bill->vendor_id)->first();
            if ($invoice === null || $budget === null || $vendor === null
                || $invoice->organization_id !== $bill->organization_id || $budget->organization_id !== $bill->organization_id
                || $vendor->organization_id !== $bill->organization_id || $invoice->budget_id !== $bill->budget_id
                || $invoice->vendor_id !== $bill->vendor_id || ! $bill->hasValidSnapshot()) {
                throw new LogicException('Invoice evidence requires exact source and reference valuations with intact document context.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Invoice version evidence is immutable; replacement needs a reviewed successor workflow.');
        });
        static::deleting(function (): never {
            throw new LogicException('Invoice version evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'invoice_id' => 'integer', 'budget_id' => 'integer', 'vendor_id' => 'integer', 'prepared_by' => 'integer', 'source_minor_units' => 'integer', 'valuation_base_units' => 'integer', 'snapshot' => 'array'];
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $snapshot = $this->getAttribute('snapshot');
            if (! is_array($snapshot) || array_is_list($snapshot)) {
                return false;
            }
            foreach (['source', 'mapping', 'document'] as $section) {
                if (! isset($snapshot[$section]) || ! is_array($snapshot[$section]) || array_is_list($snapshot[$section])) {
                    return false;
                }
            }
            $source = $snapshot['source'];
            $mapping = $snapshot['mapping'];
            if (! is_string($source['amount'] ?? null) || ! is_string($mapping['source_per_usdc'] ?? null)
                || ! is_string($mapping['rounding'] ?? null) || ! is_string($source['currency'] ?? null)) {
                return false;
            }
            $calculated = (new CurrencyValuationCalculator)->calculate($source['amount'], CurrencyCode::from($source['currency']), $mapping['source_per_usdc'], $mapping['rounding']);

            return $this->status === 'captured' && $this->organization_id > 0 && $this->prepared_by > 0
                && Str::isUuid($this->capture_key) && $this->capture_key === strtolower($this->capture_key)
                && ($snapshot['schema_version'] ?? null) === 1 && ($snapshot['purpose'] ?? null) === 'invoice_source_evidence'
                && ($snapshot['capture_key'] ?? null) === $this->capture_key
                && ($snapshot['institution_id'] ?? null) === $this->organization_id
                && ($snapshot['prepared_by'] ?? null) === $this->prepared_by
                && ($snapshot['document']['invoice_id'] ?? null) === $this->invoice_id
                && ($snapshot['document']['budget_id'] ?? null) === $this->budget_id
                && ($snapshot['document']['vendor_id'] ?? null) === $this->vendor_id
                && $source['currency'] === $this->source_currency
                && ($source['minor_units'] ?? null) === (string) $this->source_minor_units
                && ($mapping['valuation_base_units'] ?? null) === (string) $this->valuation_base_units
                && $calculated['source_minor_units'] === (string) $this->source_minor_units
                && $calculated['valuation_base_units'] === (string) $this->valuation_base_units
                && $calculated['source_per_usdc'] === $mapping['source_per_usdc']
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($snapshot));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        /** @var InvoiceVersionReview|null $review */
        $review = InvoiceVersionReview::query()->where('invoice_version_id', $this->id)->first();

        return [
            'id' => $this->id, 'capture_key' => $this->capture_key, 'institution_id' => $this->organization_id,
            'invoice_id' => $this->invoice_id, 'snapshot_digest' => $this->snapshot_digest,
            'snapshot' => $this->snapshot, 'snapshot_valid' => $this->hasValidSnapshot(),
            'evidence_review' => $review?->decision, 'review_valid' => $review?->hasValidEvidence($this) ?? false,
            'payment_state_changed' => false, 'payment_approved' => false, 'funds_reserved' => false,
            'can_execute' => false, 'executable_fx' => false,
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
