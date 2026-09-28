<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AssistanceStatus;
use App\Enums\TransactionType;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistanceRequest;
use App\Models\User;
use App\Services\CircleWalletService;
use InvalidArgumentException;

class ApproveEscalatedRequest
{
    public function __construct(
        private readonly CircleWalletService $walletService,
    ) {}

    public function handle(
        AssistanceRequest $request,
        AgentDecision $decision,
        User $approver,
        AssistanceFund $fund,
        ?string $comment = null,
    ): Approval {
        $requestedBase = (int) ($request->requested_amount ?? 0);
        $approvedBase = (int) round((float) $decision->approved_amount * 1000000);
        $remainingBase = max(0, $requestedBase - $approvedBase);

        if ($remainingBase <= 0) {
            throw new InvalidArgumentException('No escalated remainder to approve.');
        }

        $org = $fund->organization;
        $wallet = $org->primaryWallet();

        if (! $wallet) {
            throw new InvalidArgumentException('Primary wallet missing.');
        }

        $remainingFloat = round($remainingBase / 1000000, 2);
        $recipient = $this->resolveRecipient($request);

        $tx = $this->walletService->executePayment(
            wallet: $wallet,
            recipientAddress: $recipient,
            amount: $remainingFloat,
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
            'approved_amount' => round($requestedBase / 1000000, 2),
        ]);

        $request->update([
            'status' => AssistanceStatus::RESOLVED->value,
            'admin_notes' => trim(($request->admin_notes ?? '')."\nEscalated {$remainingFloat} USDC approved by {$approver->name}. Tx: {$tx->provider_tx_hash}"),
            'resolved_at' => now(),
        ]);

        return $approval;
    }

    private function resolveRecipient(AssistanceRequest $request): string
    {
        $userId = $request->user_id ?? $request->student?->user_id ?? 0;

        return '0xstudent_'.substr(md5((string) $userId), 0, 16);
    }
}
