<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $provider
 * @property string $network
 * @property string $address
 * @property float $balance
 * @property string $status
 * @property-read Organization $organization
 * @property-read Collection<int, Transaction> $transactions
 */
class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'provider',
        'network',
        'address',
        'balance',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'balance' => 'float',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
