<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property int $balance_base_units
 * @property int $reserve_threshold_base_units
 * @property int $daily_budget_base_units
 * @property string $status
 */
class AssistanceFund extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'balance_base_units',
        'reserve_threshold_base_units',
        'daily_budget_base_units',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'balance_base_units' => 'integer',
            'reserve_threshold_base_units' => 'integer',
            'daily_budget_base_units' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function canAfford(int $amountBaseUnits): bool
    {
        return $this->balance_base_units >= $amountBaseUnits;
    }

    public function reserveAfter(int $amountBaseUnits): int
    {
        return $this->balance_base_units - $amountBaseUnits;
    }

    public function isReserveProtected(int $amountBaseUnits): bool
    {
        return $this->reserveAfter($amountBaseUnits) >= $this->reserve_threshold_base_units;
    }

    public function recordDisbursement(int $amountBaseUnits): void
    {
        $this->balance_base_units = max(0, $this->balance_base_units - $amountBaseUnits);
        $this->save();
    }
}
