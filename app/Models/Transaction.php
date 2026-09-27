<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
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
}
