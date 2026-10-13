<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StudentBillCategory;
use Database\Factories\StudentBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A non-tuition charge a student owes the institution — the college's
 * "miscellaneous fee", held here in exact 6-decimal USDC base units.
 *
 * Recording a bill is bookkeeping, not an arrival: it says a debt exists, and
 * it deliberately writes no `Transaction` row and moves no wallet balance.
 * `paid_base_units` only ever moves through an explicit payment path, so this
 * row may never be read as proof that money arrived.
 *
 * @property int $id
 * @property int $student_id
 * @property int|null $academic_term_id
 * @property StudentBillCategory $category
 * @property string $description
 * @property int $amount_base_units
 * @property int $paid_base_units
 * @property Carbon|null $due_date
 * @property string $status
 * @property-read Student $student
 * @property-read AcademicTerm|null $academicTerm
 */
class StudentBill extends Model
{
    /** @use HasFactory<StudentBillFactory> */
    use HasFactory;

    /** Nothing has been applied against the bill yet. */
    public const STATUS_OPEN = 'open';

    /** Some of the charge is covered; an exact balance is still owed. */
    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    /** The charge is covered in full. */
    public const STATUS_PAID = 'paid';

    /** Withdrawn by the institution. Not the same as settled, and never derived. */
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'student_id',
        'academic_term_id',
        'category',
        'description',
        'amount_base_units',
        'paid_base_units',
        'due_date',
        'status',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'paid_base_units' => 0,
        'status' => self::STATUS_OPEN,
    ];

    #[\Override]
    protected static function booted(): void
    {
        /**
         * A bill's amount is the fact it asserts, so it is fixed as soon as
         * money has been applied against it.
         *
         * A bill whose amount moves after a payment is a different bill: the
         * student has paid part of a figure that no longer exists, the receipt
         * they hold no longer explains the balance, and every downstream total
         * computed from the old amount silently becomes wrong. The correction
         * is a void plus a fresh bill, which leaves the original claim on the
         * record instead of overwriting it.
         *
         * The check reads both sides — the payment already stored and any being
         * written in the same save — because applying money and moving the
         * amount together is exactly the rewrite being refused.
         */
        static::saving(function (self $bill): void {
            if (! $bill->exists || ! $bill->isDirty('amount_base_units')) {
                return;
            }

            if ((int) $bill->paid_base_units > 0 || (int) $bill->getRawOriginal('paid_base_units') > 0) {
                throw new LogicException(
                    'A student bill amount cannot be rewritten once money has been applied against it; void this bill and issue a replacement.'
                );
            }
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'academic_term_id' => 'integer',
            'category' => StudentBillCategory::class,
            'amount_base_units' => 'integer',
            'paid_base_units' => 'integer',
            'due_date' => 'date',
        ];
    }

    /**
     * What is still owed, in base units.
     *
     * Clamped at zero because an overpayment is a liability held for the
     * student, not a negative debt — reporting it as negative remaining would
     * invite a second collection of a bill that is already covered.
     */
    public function remainingAmount(): int
    {
        return max(0, $this->amount_base_units - $this->paid_base_units);
    }

    /**
     * Re-derive `status` from the two amounts and persist it.
     *
     * NOT automatic: `status` is derived state, and derivation belongs next to
     * the write that made it stale, so this must be called after every change
     * to `paid_base_units`. A caller that changes the payment and forgets this
     * leaves a covered bill reading as open, which is how a student is asked
     * for money they already paid.
     */
    public function syncStatus(): static
    {
        if ($this->status === self::STATUS_VOID) {
            return $this;
        }

        $this->status = match (true) {
            $this->paid_base_units > 0 && $this->remainingAmount() === 0 => self::STATUS_PAID,
            $this->paid_base_units > 0 => self::STATUS_PARTIALLY_PAID,
            default => self::STATUS_OPEN,
        };

        if ($this->exists) {
            $this->save();
        }

        return $this;
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
