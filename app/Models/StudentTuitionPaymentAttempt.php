<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StudentTuitionPaymentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to collect a student's tuition through the network.
 *
 * The record is written before a signer is asked to do anything, so a retry
 * after a lost response finds it instead of starting a new payment. A
 * `provider_handle` belonging to an accepted asynchronous transfer is not a
 * transaction hash, and a `provider_reference` is not evidence that money
 * moved: only `settlement_verdict` holds a defensible answer.
 *
 * @property int $id
 * @property int $student_id
 * @property int $tuition_account_id
 * @property int $student_wallet_id
 * @property string $request_key
 * @property int $amount_base_units
 * @property string $quoted_network_fee
 * @property int $fee_ceiling_base_units
 * @property int $available_base_units
 * @property int $total_base_units
 * @property string $payer_address
 * @property string $recipient_address
 * @property string $chain
 * @property int $chain_id
 * @property string $state
 * @property string|null $stage
 * @property string|null $provider_handle
 * @property string|null $provider_reference
 * @property Carbon|null $provider_accepted_at
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $settlement_verdict
 * @property string|null $settlement_reason
 * @property Carbon|null $settlement_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Student $student
 * @property-read TuitionAccount $tuitionAccount
 * @property-read StudentWallet $studentWallet
 */
class StudentTuitionPaymentAttempt extends Model
{
    protected $fillable = [
        'student_id', 'tuition_account_id', 'student_wallet_id', 'request_key', 'amount_base_units',
        'quoted_network_fee', 'fee_ceiling_base_units', 'available_base_units', 'total_base_units',
        'payer_address', 'recipient_address', 'chain', 'chain_id', 'state', 'stage', 'provider_handle',
        'provider_reference', 'provider_accepted_at', 'attempts', 'last_error', 'settlement_verdict',
        'settlement_reason', 'settlement_verified_at',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'tuition_account_id' => 'integer',
            'student_wallet_id' => 'integer',
            'amount_base_units' => 'integer',
            'fee_ceiling_base_units' => 'integer',
            'available_base_units' => 'integer',
            'total_base_units' => 'integer',
            'chain_id' => 'integer',
            'attempts' => 'integer',
            'provider_accepted_at' => 'datetime',
            'settlement_verified_at' => 'datetime',
        ];
    }

    public function state(): StudentTuitionPaymentState
    {
        return StudentTuitionPaymentState::from($this->state);
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
     * Whether movement was actually proven on chain.
     *
     * Deliberately narrower than "has a reference": the reference is a claim
     * until a receipt confirms it, and a rejected receipt settles nothing.
     */
    public function isVerified(): bool
    {
        return $this->settlement_verdict === 'verified'
            && $this->state === StudentTuitionPaymentState::VERIFIED->value;
    }
}
