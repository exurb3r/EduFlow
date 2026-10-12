<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\StudentTuitionPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * A tuition payment the student's own wallet made to the institution.
 *
 * @property int $id
 * @property int $student_id
 * @property int $tuition_account_id
 * @property int $student_wallet_id
 * @property string $tx_hash
 * @property int $amount_base_units
 * @property string $network_fee
 * @property string $payer_address
 * @property string $recipient_address
 * @property string $chain
 * @property bool $tx_verified_on_chain
 * @property CarbonInterface $executed_at
 * @property-read Student $student
 * @property-read TuitionAccount $tuitionAccount
 * @property-read StudentWallet $studentWallet
 */
class StudentTuitionPayment extends Model
{
    /** @use HasFactory<StudentTuitionPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id', 'tuition_account_id', 'student_wallet_id', 'tx_hash',
        'amount_base_units', 'network_fee', 'payer_address', 'recipient_address',
        'chain', 'tx_verified_on_chain', 'executed_at',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'amount_base_units' => 'integer',
            'tx_verified_on_chain' => 'boolean',
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
}
