<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AssistanceDecisionStatus;
use App\Models\AgentDecision;
use App\Models\Organization;
use App\Models\StudentAssistanceRequest;
use App\Services\AssistanceAgent;
use App\Services\PaymentService;
use App\Services\PolicyEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StudentAssistanceController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = Organization::query()->first();

        $requests = $user->studentAssistanceRequests()
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (StudentAssistanceRequest $assistanceRequest): array => $this->presentRequest($assistanceRequest));

        return Inertia::render('financial-assistance', [
            'requests' => $requests,
            'wallet' => [
                'address' => $user->wallet_address,
            ],
            'policy' => $this->policyView($organization),
            'highlightId' => (int) $request->session()->get('highlight_request_id', 0),
        ]);
    }

    public function store(Request $request, AssistanceAgent $agent, PaymentService $payments): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:5000'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        if (blank($user->wallet_address)) {
            return back()
                ->withErrors(['wallet_address' => 'Link a payout wallet before requesting assistance.'])
                ->withInput();
        }

        $organization = Organization::query()->first();

        $assistanceRequest = StudentAssistanceRequest::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'request_type' => 'emergency_assistance',
            'reason' => trim((string) $validated['reason']),
            'requested_amount' => round((float) $validated['amount'], 2),
            'status' => AssistanceDecisionStatus::AUTO_APPROVED->value,
            'reference_number' => 'FIN-'.strtoupper(Str::random(6)),
        ]);

        $evaluation = $agent->review($assistanceRequest, $organization);
        $agent->record($assistanceRequest, $organization, $evaluation);

        $assistanceRequest->update([
            'approved_amount' => $evaluation['approved_amount'],
            'status' => match ($evaluation['decision']) {
                'auto_approve' => AssistanceDecisionStatus::PAID->value,
                'partial_approval' => AssistanceDecisionStatus::ESCALATED->value,
                default => AssistanceDecisionStatus::ESCALATED->value,
            },
        ]);

        if ($evaluation['approved_amount'] > 0.0) {
            $payments->disburse($assistanceRequest, $user, $organization, $evaluation['approved_amount']);
        }

        return to_route('financial-assistance.index')
            ->with('highlight_request_id', $assistanceRequest->id);
    }

    /**
     * @return array{auto_limit: float, currency: string, budget_remaining: float, minimum_reserve: float}
     */
    private function policyView(?Organization $organization): array
    {
        if (! $organization instanceof Organization) {
            return [
                'auto_limit' => PolicyEngineService::MAX_AUTO_ASSISTANCE,
                'currency' => 'USDC',
                'budget_remaining' => 0.0,
                'minimum_reserve' => 0.0,
            ];
        }

        $budget = $organization->budgets()->where('category', 'scholarships')->first();

        return [
            'auto_limit' => min(PolicyEngineService::MAX_AUTO_ASSISTANCE, $organization->max_auto_payment),
            'currency' => $organization->currency,
            'budget_remaining' => $budget !== null ? (float) $budget->remaining_amount : 0.0,
            'minimum_reserve' => (float) $organization->minimum_reserve,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRequest(StudentAssistanceRequest $assistanceRequest): array
    {
        $decision = AgentDecision::query()
            ->where('reference_type', 'student_assistance_request')
            ->where('reference_id', $assistanceRequest->id)
            ->latest()
            ->first();

        $status = AssistanceDecisionStatus::from($assistanceRequest->status);

        return [
            'id' => $assistanceRequest->id,
            'reference_number' => $assistanceRequest->reference_number,
            'reason' => $assistanceRequest->reason,
            'requested_amount' => (float) $assistanceRequest->requested_amount,
            'approved_amount' => (float) $assistanceRequest->approved_amount,
            'status' => $status->value,
            'status_label' => $status->getLabel(),
            'created_at' => $assistanceRequest->created_at?->format('M d, Y h:i A'),
            'decision' => $decision !== null ? [
                'decision' => $decision->decision,
                'approved_amount' => (float) $decision->approved_amount,
                'requested_amount' => (float) $decision->requested_amount,
                'requires_human_approval' => $decision->requires_approval,
                'reason' => $decision->reasoning_summary,
                'policy' => $decision->policy_checked,
                'checks' => is_array($decision->input_snapshot['checks'] ?? null)
                    ? $decision->input_snapshot['checks']
                    : [],
                'agent' => 'EduFlow AssistanceAgent (heuristic v0 — LLM pending)',
            ] : null,
        ];
    }
}
