<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StudentTuitionPaymentState;
use Database\Factories\TuitionAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $student_id
 * @property int $academic_term_id
 * @property int $total_amount
 * @property int $paid_amount
 * @property-read Student $student
 * @property-read AcademicTerm $academicTerm
 */
class TuitionAccount extends Model
{
    /** @use HasFactory<TuitionAccountFactory> */
    use HasFactory;

    protected $fillable = ['student_id', 'academic_term_id', 'total_amount', 'paid_amount'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'academic_term_id' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
        ];
    }

    public function remainingAmount(): int
    {
        return $this->total_amount - $this->paid_amount;
    }

    public function heldAmount(): int
    {
        return (int) StudentTuitionPaymentAttempt::query()
            ->where('tuition_account_id', $this->id)
            ->where(function ($q): void {
                $q->whereIn('state', StudentTuitionPaymentState::openStates())
                    ->orWhere(function ($q): void {
                        $q->where('state', 'verified')->whereNull('posted_at');
                    });
            })
            ->whereNull('posted_at')
            ->sum('amount_base_units');
    }

    public function effectiveRemainingAmount(): int
    {
        return max(0, $this->remainingAmount() - $this->heldAmount());
    }

    /** @return HasMany<StudentTuitionPaymentAttempt, $this> */
    public function tuitionPaymentAttempts(): HasMany
    {
        return $this->hasMany(StudentTuitionPaymentAttempt::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
