<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AssistanceRequest;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\Wallet;
use InvalidArgumentException;

class TuitionSettlementService
{
    /**
     * Off-ramp rail: offset a tuition account ledger when aid is earmarked for tuition.
     * Integer base-unit math only; USDC -> local ledger at locked rate is recorded, not floated.
     */
    public function settleToTuition(
        AssistanceRequest $request,
        TuitionAccount $account,
        int $usdcBaseUnits,
        Wallet $treasury,
        array $quote,
    ): Transaction {
        if ($usdcBaseUnits <= 0) {
            throw new InvalidArgumentException('Settlement amount must be positive.');
        }

        if ($account->remainingAmount() <= 0) {
            throw new InvalidArgumentException('Tuition account has no outstanding balance.');
        }

        $apply = min($usdcBaseUnits, $account->remainingAmount());
        $account->paid_amount += $apply;
        $account->save();

        return Transaction::create([
            'organization_id' => $treasury->organization_id,
            'wallet_id' => $treasury->id,
            'type' => TransactionType::STUDENT_ASSISTANCE,
            'recipient_address' => 'tuition-ledger:'.$account->id,
            'amount' => round($apply / 1000000, 2),
            'currency' => 'USDC',
            'status' => TransactionStatus::CONFIRMED,
            'provider_tx_hash' => null,
            'network' => 'arc',
            'reference_type' => AssistanceRequest::class,
            'reference_id' => $request->id,
            'metadata' => [
                'rail' => 'tuition-offset',
                'locked_quote' => $quote,
                'applied_base_units' => $apply,
                'tuition_account_id' => $account->id,
            ],
            'executed_at' => now(),
        ]);
    }
}
