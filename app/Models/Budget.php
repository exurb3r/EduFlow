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
 * @property string $name
 * @property string $category
 * @property float $allocated_amount
 * @property float $spent_amount
 * @property float $remaining_amount
 * @property string $status
 * @property-read Organization $organization
 * @property-read Collection<int, Invoice> $invoices
 */
class Budget extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'category',
        'allocated_amount',
        'spent_amount',
        'remaining_amount',
        'status',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'allocated_amount' => 'float',
            'spent_amount' => 'float',
            'remaining_amount' => 'float',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function canAfford(float $amount): bool
    {
        return $this->remaining_amount >= $amount;
    }

    public function recordExpense(float $amount): void
    {
        $this->spent_amount += $amount;
        $this->remaining_amount = max(0.00, $this->allocated_amount - $this->spent_amount);
        if ($this->remaining_amount <= 0) {
            $this->status = 'exhausted';
        }
        $this->save();
    }
}
