<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\EvaluateAssistancePolicy;
use App\Agents\EduFlowAgent;
use App\Enums\CurrencyCode;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Services\AskEduFlow;
use App\Services\CurrencyConverter;
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
        EvaluateAssistancePolicy $evaluator,
        CurrencyConverter $converter,
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

        $fund = AssistanceFund::where('organization_id', $org->id)->first();
        $policy = AssistancePolicyVersion::active();

        $request = AssistanceRequest::query()
            ->whereHas('student.user', fn ($q) => $q->where('email', $this->option('student-email')))
            ->orderByDesc('id')
            ->first();

        if ($request && $fund && $policy) {
            $out = $evaluator->handle($request, $fund, $policy, CurrencyCode::PHP);
            $this->line($explainer->explain($out['result'], (int) $request->requested_amount, CurrencyCode::PHP));
            $this->line('Explorer: '.($out['decision']->input_snapshot['locked_quote']['quote_id'] ?? 'n/a'));
        }

        $this->line($ask->answer("Why didn't you send the full 150 USDC initially?"));

        return self::SUCCESS;
    }
}
