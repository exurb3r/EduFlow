<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $organization_id
 * @property string $request_type
 * @property string $reason
 * @property float $requested_amount
 * @property float|null $approved_amount
 * @property string $status
 * @property string $reference_number
 * @property Carbon|null $created_at
 */
class StudentAssistanceRequest extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'request_type',
        'reason',
        'requested_amount',
        'approved_amount',
        'status',
        'reference_number',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'reference');
    }

    public function totalPaid(): float
    {
        return (float) $this->transactions()
            ->where('status', 'confirmed')
            ->sum('amount');
    }
}
