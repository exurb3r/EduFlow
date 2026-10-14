<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentAssistanceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\FundsReceivedNotification;
use Illuminate\Support\Str;

/**
 * Simulated payout executor. Stands in for the Circle/USDC-on-Arc
 * integration: it debits the demo treasury wallet, writes a Transaction
 * row with a fake tx hash, and confirms immediately so the student
 * ledger shows real movement without touching a live network.
 */
final class PaymentService
{
    public function disburse(StudentAssistanceRequest $request, User $student, Organization $organization, float $amount): ?Transaction
    {
        $wallet = $organization->primaryWallet();

        if (! $wallet instanceof Wallet || $amount <= 0.0) {
            return null;
        }

        // A recipient must come from a real record. The wallet address is the
        // payout address on the student record, and without one there is no
        // destination to record a payment to.
        $studentRecord = $student->student()->first();

        if (! $studentRecord instanceof Student) {
            return null;
        }

        $recipientAddress = app(StudentWalletService::class)->addressFor($studentRecord);

        if ($recipientAddress === null) {
            return null;
        }

        $wallet->balance = (float) $wallet->balance - $amount;
        $wallet->save();

        if ($wallet->balance < 0) {
            $wallet->update(['balance' => 0]);
        }

        $tx = Transaction::create([
            'organization_id' => $organization->id,
            'wallet_id' => $wallet->id,
            'type' => 'student_assistance',
            'recipient_address' => $recipientAddress,
            'amount' => $amount,
            'currency' => $organization->currency,
            'status' => 'confirmed',
            'provider_tx_hash' => '0x'.Str::random(40),
            'network' => 'arc-testnet',
            'reference_type' => 'student_assistance_request',
            'reference_id' => $request->id,
            'metadata' => [
                'policy' => PolicyEngineService::POLICY_NAME,
                'simulated' => true,
                'reference_number' => $request->reference_number,
            ],
            'executed_at' => now(),
        ]);

        $amountUsdc = number_format($amount, 2);
        $studentRecord->user->notify(new FundsReceivedNotification(
            amount: $amountUsdc,
            description: "Financial assistance grant of {$amountUsdc} USDC received.",
            txHash: $tx->provider_tx_hash,
            source: 'student_assistance',
        ));

        return $tx;
    }
}
