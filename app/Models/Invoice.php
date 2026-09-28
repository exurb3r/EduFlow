<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $vendor_id
 * @property int|null $budget_id
 * @property string $reference
 * @property float $amount
 * @property Carbon $due_date
 * @property string $category
 * @property string $status
 * @property array<string, mixed>|null $metadata
 * @property-read Organization $organization
 * @property-read Vendor $vendor
 * @property-read Budget|null $budget
 */
class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'vendor_id',
        'budget_id',
        'reference',
        'amount',
        'due_date',
        'category',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'due_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function decisions(): MorphMany
    {
        return $this->morphMany(AgentDecision::class, 'reference');
    }
}
