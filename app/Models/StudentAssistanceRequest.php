<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'reference_id')
            ->where('reference_type', 'student_assistance_request');
    }

    public function totalPaid(): float
    {
        return (float) $this->transactions()
            ->where('status', 'confirmed')
            ->sum('amount');
    }
}
