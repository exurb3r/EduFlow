<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AssistanceCategory;
use App\Enums\AssistanceStatus;
use App\Enums\TransactionType;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistanceRequest;
use App\Models\User;
use App\Services\CircleWalletService;
use App\Services\TuitionSettlementService;
use InvalidArgumentException;

class ApproveEscalatedRequest
{
    public function __construct(
        private readonly CircleWalletService $walletService,
        private readonly TuitionSettlementService $tuitionService,
    ) {}

    public function handle(
        AssistanceRequest $request,
        AgentDecision $decision,
        User $approver,
        AssistanceFund $fund,
        ?string $comment = null,
        bool $forceTuitionOffset = false,
    ): Approval {
        $requestedBase = (int) ($request->requested_amount ?? 0);
        $approvedBase = isset($decision->input_snapshot['approved_base_units'])
            ? (int) $decision->input_snapshot['approved_base_units']
            : (int) round((float) $decision->approved_amount * 1_000_000);

        $remainingBase = max(0, $requestedBase - $approvedBase);

        if ($remainingBase <= 0) {
            throw new InvalidArgumentException('No escalated remainder to approve.');
        }

        $org = $fund->organization;
        $wallet = $org->primaryWallet();

        if (! $wallet) {
            throw new InvalidArgumentException('Primary wallet missing.');
        }

        $remainingFloat = round($remainingBase / 1_000_000, 2);

        $student = $request->student;
        $hasPayoutAddress = $student?->hasValidPayoutAddress() === true;
        $tuitionAccount = $student?->tuitionAccounts()->where('status', 'active')->first()
            ?? $student?->tuitionAccounts()->first();
        $hasTuitionBalance = $tuitionAccount && $tuitionAccount->remainingAmount() > 0;

        $settleViaTuition = ($forceTuitionOffset || (! $hasPayoutAddress && $request->category === AssistanceCategory::FINANCIAL))
            && $hasTuitionBalance;

        if (! $hasPayoutAddress && ! $settleViaTuition) {
            throw new InvalidArgumentException('Student does not have a valid payout address on file. Cannot disburse funds.');
        }

        if ($settleViaTuition && $tuitionAccount !== null) {
            $quote = $decision->input_snapshot['locked_quote'] ?? [];
            $tx = $this->tuitionService->settleToTuition(
                request: $request,
                account: $tuitionAccount,
                usdcBaseUnits: $remainingBase,
                treasury: $wallet,
                quote: $quote,
            );

            $noteMessage = "Escalated {$remainingFloat} USDC applied to tuition ledger ({$tuitionAccount->account_number}) by {$approver->name}.";
        } else {
            $recipient = $this->resolveRecipient($request);
            $tx = $this->walletService->executePaymentBaseUnits(
                wallet: $wallet,
                recipientAddress: $recipient,
                baseUnits: $remainingBase,
                type: TransactionType::STUDENT_ASSISTANCE,
                referenceType: AssistanceRequest::class,
                referenceId: $request->id,
                metadata: [
                    'ticket' => $request->ticket_number,
                    'approved_by' => $approver->name,
                    'human_override' => true,
                    'escalated_remainder_base_units' => $remainingBase,
                ],
            );

            $noteMessage = "Escalated {$remainingFloat} USDC approved by {$approver->name}. Tx: {$tx->provider_tx_hash}";
        }

        $fund->recordDisbursement($remainingBase);

        $approval = Approval::updateOrCreate(
            ['agent_decision_id' => $decision->id],
            [
                'organization_id' => $fund->organization_id,
                'approver_id' => $approver->id,
                'status' => 'approved',
                'comment' => $comment ?? 'Escalated remainder approved.',
                'approved_at' => now(),
            ]
        );

        $decision->update([
            'status' => 'executed',
            'approved_amount' => round($requestedBase / 1_000_000, 2),
            'input_snapshot' => array_merge($decision->input_snapshot ?? [], [
                'approved_base_units' => $requestedBase,
            ]),
        ]);

        $request->update([
            'status' => AssistanceStatus::RESOLVED->value,
            'admin_notes' => trim(($request->admin_notes ?? '')."\n".$noteMessage),
            'resolved_at' => now(),
        ]);

        return $approval;
    }

    private function resolveRecipient(AssistanceRequest $request): string
    {
        $student = $request->student;
        $address = $student?->payout_address;

        if (! is_string($address) || ! $student->hasValidPayoutAddress()) {
            throw new InvalidArgumentException('Student does not have a valid payout address on file. Cannot disburse funds.');
        }

        return $address;
    }
}
