<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agents\EduFlowAgent;
use App\DTOs\PolicyEvaluationResult;
use App\Enums\CurrencyCode;
use App\Models\AgentDecision;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Transaction;
use App\Services\AskEduFlow;
use App\Services\DecisionExplainer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eduflow:demo {--student-email=juan@eduflow.test}')]
#[Description('End-to-end EduFlow demo: revenue, forecast, 450 auto-pay, 2500 escalate, 150 split, audit.')]
class EduFlowDemo extends Command
{
    public function handle(
        EduFlowAgent $agent,
        DecisionExplainer $explainer,
        AskEduFlow $ask,
    ): int {
        $org = Organization::where('name', 'Northstar Learning Center')->first();

        if (! $org) {
            $this->error('Run seeders first: EduFlowFinancialSeeder, EducationDemoSeeder, EduFlowPlanSeeder.');

            return self::FAILURE;
        }

        $result = $agent->runAutonomousCycle($org);
        $this->info("Cycle: auto-paid {$result['stats']['auto_paid']}, escalated {$result['stats']['escalated']}, held {$result['stats']['held']}, disbursed {$result['stats']['total_disbursed_usdc']} USDC.");

        $request = AssistanceRequest::query()
            ->whereHas('student.user', fn ($q) => $q->where('email', $this->option('student-email')))
            ->orderByDesc('id')
            ->first();

        // Read-only: replay the cycle's recorded decision, never re-evaluate.
        $stored = $request ? AgentDecision::where('reference_type', AssistanceRequest::class)
            ->where('reference_id', $request->id)
            ->latest()
            ->first() : null;

        if ($request && $stored) {
            $snapshot = $stored->input_snapshot ?? [];

            $replay = new PolicyEvaluationResult(
                decision: $stored->decision,
                requestedAmount: (float) $stored->requested_amount,
                approvedAmount: (float) $stored->approved_amount,
                requiresHumanApproval: (bool) $stored->requires_approval,
                policyCode: $stored->policy_checked,
                reasoning: $stored->reasoning_summary,
                violations: [],
                checks: $snapshot['checks'] ?? [],
            );

            $this->line($explainer->explain($replay, (int) $request->requested_amount, CurrencyCode::PHP));

            $receipt = Transaction::where('reference_type', AssistanceRequest::class)
                ->where('reference_id', $request->id)
                ->latest()
                ->first();

            $this->line('Explorer: '.($receipt?->metadata['explorer_url'] ?? $receipt?->provider_tx_hash ?? 'n/a'));
        }

        $this->line($ask->answer("Why didn't you send the full request initially?"));

        return self::SUCCESS;
    }
}
