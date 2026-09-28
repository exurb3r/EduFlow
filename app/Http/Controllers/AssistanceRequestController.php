<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitAssistanceRequest;
use App\DTOs\PolicyEvaluationResult;
use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Enums\CurrencyCode;
use App\Http\Requests\StoreAssistanceRequest;
use App\Http\Requests\StoreAssistanceRequestRequest;
use App\Models\AssistanceRequest;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\DecisionExplainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AssistanceRequestController extends Controller
{
    /**
     * Store a newly created assistance ticket from the user dashboard.
     */
    public function store(StoreAssistanceRequestRequest $request): RedirectResponse
    {
        $assistanceRequest = $request->user()->assistanceRequests()->create([
            'category' => $request->enum('category', AssistanceCategory::class),
            'priority' => $request->enum('priority', AssistancePriority::class),
            'subject' => $request->string('subject')->trim()->toString(),
            'description' => $request->string('description')->trim()->toString(),
            'status' => AssistanceStatus::PENDING->value,
        ]);

        return back()->with('success', "Assistance ticket {$assistanceRequest->ticket_number} submitted successfully! Our support team will review it shortly.");
    }

    public function create(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', AssistanceRequest::class);
        $accounts = $request->user()->student->tuitionAccounts()->with('academicTerm')
            ->whereHas('academicTerm', fn ($query) => $query->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))->get();

        if ($accounts->count() !== 1) {
            return to_route('student.dashboard');
        }

        return Inertia::render('assistance/create', [
            'submissionKey' => (string) Str::uuid(),
            'term' => $accounts->first()->academicTerm->name,
        ]);
    }

    public function storeIntake(StoreAssistanceRequest $request, SubmitAssistanceRequest $submit): RedirectResponse
    {
        $assistance = $submit->handle($request->user(), $request->validated());

        return to_route('assistance.show', $assistance);
    }

    public function show(
        AssistanceRequest $assistanceRequest,
        DecisionExplainer $explainer,
        CurrencyConverter $converter,
    ): Response {
        Gate::authorize('view', $assistanceRequest);

        $decision = $assistanceRequest->latestAgentDecision();
        $aiExplanation = null;

        if ($decision) {
            $displayCurrency = CurrencyCode::tryFrom(strtoupper((string) config('eduflow.display_currency', 'PHP'))) ?? CurrencyCode::PHP;
            $requestedBase = (int) ($assistanceRequest->requested_amount ?? 0);
            $quote = $decision->input_snapshot['locked_quote'] ?? $converter->lockQuote($displayCurrency);
            $hardship = $explainer->synthesizeHardship($assistanceRequest->reason ?? $assistanceRequest->description ?? '');

            $evalResult = new PolicyEvaluationResult(
                decision: $decision->decision,
                requestedAmount: (float) $decision->requested_amount,
                approvedAmount: (float) $decision->approved_amount,
                requiresHumanApproval: (bool) $decision->requires_approval,
                policyCode: $decision->policy_checked,
                reasoning: $decision->reasoning_summary,
                violations: [],
                checks: $decision->input_snapshot['checks'] ?? [],
            );

            $transactions = Transaction::where('reference_type', AssistanceRequest::class)
                ->where('reference_id', $assistanceRequest->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (Transaction $t) => [
                    'id' => $t->id,
                    'tx_hash' => $t->provider_tx_hash,
                    'amount' => number_format((float) $t->amount, 2).' USDC',
                    'status' => $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status,
                    'network' => $t->network,
                    'explorer_url' => $t->metadata['explorer_url'] ?? ($t->provider_tx_hash ? 'https://testnet.arcscan.app/tx/'.$t->provider_tx_hash : null),
                    'executed_at' => $t->executed_at?->toIso8601String(),
                ])
                ->values()
                ->all();

            $pendingBase = $assistanceRequest->pendingReviewBaseUnits();

            $aiExplanation = [
                'has_decision' => true,
                'decision' => $decision->decision->value,
                'decision_label' => $decision->decision->getLabel(),
                'decision_color' => $decision->decision->getColor(),
                'policy_code' => $decision->policy_checked,
                'explanation' => $explainer->explain($evalResult, $requestedBase, $displayCurrency),
                'hardship_synthesis' => $hardship['synthesis'],
                'hardship_category' => $hardship['category'],
                'hardship_urgency' => $hardship['urgency'],
                'split' => [
                    'requested_usdc' => number_format($requestedBase / 1000000, 2),
                    'requested_fiat' => $converter->formatDual($requestedBase, $displayCurrency),
                    'auto_approved_usdc' => number_format((float) $decision->approved_amount, 2),
                    'auto_approved_fiat' => $converter->formatDual((int) round($decision->approved_amount * 1000000), $displayCurrency),
                    'pending_usdc' => number_format($pendingBase / 1000000, 2),
                    'pending_fiat' => $converter->formatDual($pendingBase, $displayCurrency),
                ],
                'checks' => collect($decision->input_snapshot['checks'] ?? [])->map(fn ($passed, $name) => [
                    'name' => str_replace('_', ' ', (string) $name),
                    'passed' => (bool) $passed,
                ])->values()->all(),
                'locked_quote' => [
                    'rate_description' => "1 USDC ≈ {$quote['units_per_usdc']} minor {$quote['quote']}",
                    'provider' => $quote['provider'] ?? 'fallback',
                    'quoted_at' => $quote['quoted_at'] ?? now()->toIso8601String(),
                    'expires_at' => $quote['expires_at'] ?? null,
                ],
                'transactions' => $transactions,
            ];
        }

        return Inertia::render('assistance/show', [
            'assistanceRequest' => [
                'id' => $assistanceRequest->id,
                'type' => $assistanceRequest->type,
                'requested_amount' => (string) $assistanceRequest->requested_amount,
                'status' => $assistanceRequest->status instanceof \BackedEnum ? $assistanceRequest->status->value : (string) $assistanceRequest->status,
                'submitted_at' => $assistanceRequest->submitted_at?->toIso8601String() ?? $assistanceRequest->created_at?->toIso8601String(),
                'reason' => $assistanceRequest->reason ?? $assistanceRequest->description,
                'term' => $assistanceRequest->academicTerm?->name ?? 'General Term',
                'admin_notes' => $assistanceRequest->admin_notes,
            ],
            'aiExplanation' => $aiExplanation,
        ]);
    }
}
