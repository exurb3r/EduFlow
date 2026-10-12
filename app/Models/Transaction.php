<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $wallet_id
 * @property TransactionType $type
 * @property string $recipient_address
 * @property float $amount
 * @property string $currency
 * @property TransactionStatus $status
 * @property string|null $provider_tx_hash
 * @property string $network
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $executed_at
 * @property-read Organization $organization
 * @property-read Wallet $wallet
 */
class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'wallet_id',
        'type',
        'recipient_address',
        'amount',
        'currency',
        'status',
        'provider_tx_hash',
        'network',
        'reference_type',
        'reference_id',
        'metadata',
        'executed_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'float',
            'metadata' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Payments settled to a wallet address, matched without regard to case.
     *
     * Circle does not require EIP-55 checksum casing, so the same address can
     * be recorded with different casing in different places. A case-sensitive
     * comparison silently returns nothing, which is how a student ends up
     * looking at an empty payment history for a wallet that was paid.
     *
     * A null or malformed address matches nothing rather than everything.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopePaidTo(Builder $query, ?string $address): void
    {
        if (! is_string($address) || preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) !== 1) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereRaw('lower(recipient_address) = ?', [strtolower($address)]);
    }

    /**
     * Reconciliation proved this receipt's hash is absent from the chain, so
     * the payment never settled and the row has been marked failed.
     */
    public function isReconciledFailed(): bool
    {
        return ($this->metadata['reconciliation'] ?? null) === 'failed';
    }
}
