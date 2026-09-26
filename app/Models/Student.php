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

    protected $fillable = [
        'user_id', 'student_number', 'program', 'year_level',
        'enrollment_status', 'academic_status', 'attendance_rate',
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
}
