<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    /**
     * 0x followed by exactly 40 hex characters. Circle rejects anything else
     * as an invalid destination, so this is checked before any transfer.
     */
    public const PAYOUT_ADDRESS_PATTERN = '/^0x[0-9a-fA-F]{40}$/';

    protected $fillable = [
        'user_id', 'student_number', 'program', 'year_level',
        'enrollment_status', 'academic_status', 'attendance_rate',
        'payout_address',
    ];

    protected $attributes = [
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'year_level' => 'integer',
            'attendance_rate' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TuitionAccount, $this> */
    public function tuitionAccounts(): HasMany
    {
        return $this->hasMany(TuitionAccount::class);
    }

    /** @return HasMany<AssistanceRequest, $this> */
    public function assistanceRequests(): HasMany
    {
        return $this->hasMany(AssistanceRequest::class);
    }

    /**
     * Whether this student can actually receive an assistance payment.
     *
     * The agent must not invent a destination: a fabricated address is either
     * rejected by the Circle CLI or, worse, accepted and irretrievable.
     */
    public function hasValidPayoutAddress(): bool
    {
        return is_string($this->payout_address)
            && preg_match(self::PAYOUT_ADDRESS_PATTERN, $this->payout_address) === 1;
    }
}
