<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $user_id
 * @property string $student_number
 * @property string $program
 * @property int $year_level
 * @property string $enrollment_status
 * @property string $academic_status
 * @property string|null $attendance_rate
 * @property string|null $payout_address
 * @property-read User $user
 * @property-read Collection<int, TuitionAccount> $tuitionAccounts
 * @property-read Collection<int, AssistanceRequest> $assistanceRequests
 * @property-read Collection<int, StudentBill> $bills
 * @property-read StudentWallet|null $wallet
 * @property-read Collection<int, StudentWalletFunding> $walletFundings
 */
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    use LogsActivity;

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
     * Non-tuition charges: miscellaneous, laboratory, registration, examination.
     *
     * Separate from `tuitionAccounts` on purpose. An account is a term-scoped
     * tuition ledger that the finance lane already reconciles, plans against and
     * imports into Collections; a bill is a distinct claim with its own category,
     * its own due date and its own status. Collapsing them would make every
     * tuition total silently include charges that are not tuition.
     *
     * @return HasMany<StudentBill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(StudentBill::class);
    }

    /** @return HasOne<StudentWallet, $this> */
    public function wallet(): HasOne
    {
        return $this->hasOne(StudentWallet::class);
    }

    /**
     * The wallet is minted once per student, so this reads through it rather
     * than keeping a second address on the student row.
     *
     * @return HasManyThrough<StudentWalletFunding, StudentWallet, $this>
     */
    public function walletFundings(): HasManyThrough
    {
        return $this->hasManyThrough(StudentWalletFunding::class, StudentWallet::class);
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

    public function enrollmentStatusColor(): string
    {
        return match ($this->enrollment_status) {
            'enrolled' => 'success',
            'on_leave' => 'warning',
            'graduated' => 'info',
            default => 'gray',
        };
    }

    public function academicStatusColor(): string
    {
        return match ($this->academic_status) {
            'qualified' => 'success',
            'probation' => 'warning',
            'suspended', 'dismissed' => 'danger',
            default => 'gray',
        };
    }

    public function totalTuitionBalance(): int
    {
        return (int) $this->tuitionAccounts->sum(fn (TuitionAccount $account): int => $account->remainingAmount());
    }

    public function totalTuitionPaid(): int
    {
        return (int) $this->tuitionAccounts->sum(fn (TuitionAccount $account): int => $account->paid_amount);
    }

    public function totalTuitionOwed(): int
    {
        return (int) $this->tuitionAccounts->sum(fn (TuitionAccount $account): int => $account->total_amount);
    }

    /**
     * The payout address decides where money goes, so every change to it is
     * logged with who made it. A re-pointed destination is the one edit this
     * application must always be able to explain after the fact.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['payout_address'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Student {$this->student_number} payout address {$eventName}");
    }

    /**
     * Why this student cannot be deleted, or null when they can be.
     *
     * Tuition accounts and assistance requests hold financial history and are
     * linked without a cascade, so the database refuses the delete. That is the
     * correct outcome — the history must not disappear silently — but a raw
     * foreign-key error is not an explanation, so the reason is spelled out
     * before anything is attempted.
     */
    public function deletionBlockedReason(): ?string
    {
        $tuition = $this->tuitionAccounts()->count();
        $assistance = $this->assistanceRequests()->count();
        $bills = $this->bills()->count();

        $parts = [];

        if ($tuition > 0) {
            $parts[] = $tuition === 1 ? '1 tuition account' : "{$tuition} tuition accounts";
        }

        if ($assistance > 0) {
            $parts[] = $assistance === 1 ? '1 assistance request' : "{$assistance} assistance requests";
        }

        if ($bills > 0) {
            $parts[] = $bills === 1 ? '1 student bill' : "{$bills} student bills";
        }

        if ($parts === []) {
            return null;
        }

        return 'This student has '.implode(' and ', $parts).' on file. Delete those records first — deleting a student must not erase financial history.';
    }
}
