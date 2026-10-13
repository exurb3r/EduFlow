<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StudentTuitionPaymentState;
use Database\Factories\TuitionAccountFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $student_id
 * @property int $academic_term_id
 * @property int $total_amount
 * @property int $paid_amount
 * @property-read Student $student
 * @property-read AcademicTerm $academicTerm
 * @property-read Collection<int, StudentTuitionPayment> $payments
 * @property-read Collection<int, StudentTuitionPaymentAttempt> $paymentAttempts
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
            ->get()
            ->filter(function (StudentTuitionPaymentAttempt $attempt): bool {
                if ($attempt->state === 'prepared' && $attempt->submission_started_at === null) {
                    $intent = StudentTuitionPaymentIntent::query()->find($attempt->student_tuition_payment_intent_id);
                    if ($intent instanceof StudentTuitionPaymentIntent
                        && isset($intent->snapshot['expires_at'])
                        && Carbon::parse($intent->snapshot['expires_at'])->lte(now())) {
                        return false;
                    }
                }

                return true;
            })
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

    /** @return HasMany<StudentTuitionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(StudentTuitionPayment::class);
    }

    /** @return HasMany<StudentTuitionPaymentAttempt, $this> */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(StudentTuitionPaymentAttempt::class);
    }

    public function isPaidInFull(): bool
    {
        return $this->total_amount > 0 && $this->paid_amount >= $this->total_amount;
    }

    public function isPartiallyPaid(): bool
    {
        return $this->paid_amount > 0 && $this->paid_amount < $this->total_amount;
    }

    public function isUnpaid(): bool
    {
        return $this->paid_amount === 0;
    }

    public function paymentProgressPercentage(): float
    {
        if ($this->total_amount <= 0) {
            return 100.0;
        }

        return round(min(100.0, ($this->paid_amount / $this->total_amount) * 100), 1);
    }

    public function statusLabel(): string
    {
        if ($this->isPaidInFull()) {
            return 'Paid in full';
        }

        if ($this->isPartiallyPaid()) {
            return 'Partially paid';
        }

        return 'Unpaid';
    }

    public function statusColor(): string
    {
        if ($this->isPaidInFull()) {
            return 'success';
        }

        if ($this->isPartiallyPaid()) {
            return 'warning';
        }

        return 'danger';
    }
}
