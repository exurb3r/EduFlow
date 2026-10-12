<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StudentWalletFundingStatus;
use Carbon\CarbonInterface;
use Database\Factories\StudentWalletFundingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * A single faucet drip against a student wallet.
 *
 * @property int $id
 * @property int $student_wallet_id
 * @property string $chain
 * @property int $amount_base_units
 * @property StudentWalletFundingStatus $status
 * @property string|null $tx_hash
 * @property string|null $detail
 * @property CarbonInterface $funded_at
 * @property-read StudentWallet $studentWallet
 */
class StudentWalletFunding extends Model
{
    /** @use HasFactory<StudentWalletFundingFactory> */
    use HasFactory;

    protected $fillable = [
        'student_wallet_id', 'chain', 'amount_base_units', 'status', 'tx_hash', 'detail', 'funded_at',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'amount_base_units' => 'integer',
            'status' => StudentWalletFundingStatus::class,
            'funded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StudentWallet, $this> */
    public function studentWallet(): BelongsTo
    {
        return $this->belongsTo(StudentWallet::class);
    }

    /**
     * USDC is 6 decimals wherever the Circle CLI reports it, so the amount is
     * formatted at that precision regardless of the chain's native decimals.
     */
    public function amount(): string
    {
        return Amounts::toDecimalString($this->amount_base_units, 6).' USDC';
    }
}
