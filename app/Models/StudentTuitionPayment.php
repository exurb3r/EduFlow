<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\StudentTuitionPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * A tuition payment the student's own wallet made to the institution.
 *
 * @property int $id
 * @property string|null $request_key
 * @property int|null $student_tuition_payment_attempt_id
 * @property string|null $movement_key
 * @property int $student_id
 * @property int $tuition_account_id
 * @property int $student_wallet_id
 * @property string $tx_hash
 * @property int $amount_base_units
 * @property int $applied_base_units
 * @property int $overpayment_base_units
 * @property string $network_fee
 * @property bool $simulated
 * @property string $payer_address
 * @property string $recipient_address
 * @property string $chain
 * @property bool $tx_verified_on_chain
 * @property CarbonInterface $executed_at
 * @property-read Student $student
 * @property-read TuitionAccount $tuitionAccount
 * @property-read StudentWallet $studentWallet
 * @property-read StudentTuitionPaymentAttempt|null $attempt
 */
class StudentTuitionPayment extends Model
{
    /** @use HasFactory<StudentTuitionPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'request_key', 'student_id', 'tuition_account_id', 'student_wallet_id', 'tx_hash',
        'amount_base_units', 'applied_base_units', 'overpayment_base_units', 'network_fee',
        'simulated', 'payer_address', 'recipient_address', 'chain', 'tx_verified_on_chain',
        'executed_at',
        'student_tuition_payment_attempt_id', 'movement_key',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'amount_base_units' => 'integer',
            'applied_base_units' => 'integer',
            'overpayment_base_units' => 'integer',
            'tx_verified_on_chain' => 'boolean',
            'simulated' => 'boolean',
            'executed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<TuitionAccount, $this> */
    public function tuitionAccount(): BelongsTo
    {
        return $this->belongsTo(TuitionAccount::class);
    }

    /** @return BelongsTo<StudentWallet, $this> */
    public function studentWallet(): BelongsTo
    {
        return $this->belongsTo(StudentWallet::class);
    }

    /**
     * The attempt whose verified movement this receipt records.
     *
     * @return BelongsTo<StudentTuitionPaymentAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(StudentTuitionPaymentAttempt::class, 'request_key', 'request_key');
    }

    /**
     * The Collections receipts that reconcile this payment.
     *
     * At most one, enforced by a unique index on the receipt's link. The money
     * arrived when the transfer settled, so a second receipt would be the same
     * cash counted twice rather than a new arrival.
     *
     * @return HasMany<CollectionBatch, $this>
     */
    public function collections(): HasMany
    {
        return $this->hasMany(CollectionBatch::class);
    }

    /**
     * The amount the institution received, in USDC's 6 decimals.
     */
    public function amount(): string
    {
        return Amounts::toDecimalString($this->amount_base_units, 6).' USDC';
    }

    /**
     * The network fee the student's wallet also paid, which goes to the Arc
     * network and not to the institution. Kept as the exact string the chain
     * reported, because it is denominated at 18 decimals.
     */
    public function fee(): string
    {
        return $this->network_fee.' USDC';
    }

    /**
     * Whether this receipt is evidence that money moved.
     *
     * A rehearsal under the fake driver is recorded so the journey can be
     * exercised, but it is never evidence of receipts: nothing was allocated to
     * tuition and nothing was credited to the treasury from it, because
     * nothing settled.
     */
    public function isReal(): bool
    {
        return ! $this->simulated && $this->tx_verified_on_chain;
    }

    /**
     * The amount that actually settled this account's debt.
     *
     * An overpayment is recorded separately: an amount larger than what is owed
     * is a liability to the student, and counting it as settled debt would be
     * planning money that may have to be given back.
     */
    public function applied(): string
    {
        return Amounts::toDecimalString($this->applied_base_units, 6).' USDC';
    }

    public function overpayment(): ?string
    {
        if ($this->overpayment_base_units <= 0) {
            return null;
        }

        return Amounts::toDecimalString($this->overpayment_base_units, 6).' USDC';
    }
}
