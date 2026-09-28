<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AgentDecisionType;
use App\Enums\CurrencyCode;
use App\Models\AssistanceRequest;
use App\Services\CurrencyConverter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentDashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasRole('student')) {
            return to_route('student.dashboard');
        }

        if ($request->user()->hasAnyRole(['finance_officer', 'admin', 'super_admin'])) {
            return redirect()->route('filament.finance.resources.assistance-requests.index');
        }

        return app(DashboardController::class)->index($request);
    }

    public function show(Request $request, CurrencyConverter $converter): Response
    {
        abort_unless($request->user()->hasRole('student'), 403);
        $student = $request->user()->student()->firstOrFail();
        $accounts = $student->tuitionAccounts()->with('academicTerm')
            ->whereHas('academicTerm', fn ($query) => $query->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today()))->get();
        $account = $accounts->count() === 1 ? $accounts->first() : null;

        $displayCurrency = CurrencyCode::tryFrom(strtoupper((string) config('eduflow.display_currency', 'PHP'))) ?? CurrencyCode::PHP;
        $quote = $converter->lockQuote($displayCurrency);

        $tuitionAccountData = null;
        if ($account) {
            $totalBase = (int) $account->total_amount;
            $paidBase = (int) $account->paid_amount;
            $remainingBase = (int) $account->remainingAmount();

            $tuitionAccountData = [
                'term' => $account->academicTerm->name,
                'total_amount' => (string) $account->total_amount,
                'paid_amount' => (string) $account->paid_amount,
                'remaining_amount' => (string) $account->remainingAmount(),
                'remaining_amount_fiat' => $converter->formatDual($remainingBase, $displayCurrency),
                'total_amount_fiat' => $converter->formatDual($totalBase, $displayCurrency),
                'paid_amount_fiat' => $converter->formatDual($paidBase, $displayCurrency),
            ];
        }

        $requests = $student->assistanceRequests()->with('agentDecisions')->latest('id')->get()->map(function (AssistanceRequest $assistance) {
            $decision = $assistance->latestAgentDecision();
            $pending = $assistance->pendingReviewBaseUnits();
            $requested = (int) ($assistance->requested_amount ?? 0);
            $auto = max(0, $requested - $pending);

            return [
                'id' => $assistance->id,
                'type' => $assistance->type,
                'requested_amount' => (string) $assistance->requested_amount,
                'status' => $assistance->status instanceof \BackedEnum ? $assistance->status->value : (string) $assistance->status,
                'submitted_at' => $assistance->submitted_at?->toIso8601String() ?? $assistance->created_at?->toIso8601String(),
                'admin_notes' => $assistance->admin_notes,
                'has_decision' => $decision !== null,
                'decision' => $decision?->decision->value,
                'is_split' => $decision?->decision === AgentDecisionType::PARTIAL_APPROVAL,
                'auto_approved_amount' => (string) $auto,
                'pending_amount' => (string) $pending,
            ];
        });

        return Inertia::render('student/dashboard', [
            'student' => [
                'name' => $request->user()->name,
                'student_number' => $student->student_number,
                'program' => $student->program,
                'year_level' => $student->year_level,
            ],
            'tuitionAccount' => $tuitionAccountData,
            'canRequest' => $account !== null,
            'requests' => $requests,
            'currency' => [
                'display' => $displayCurrency->value,
                'symbol' => $displayCurrency->symbol(),
                'rate_description' => "1 USDC ≈ {$quote['units_per_usdc']} minor {$quote['quote']}",
            ],
            'suggestedQuestions' => [
                'Why was my 150 USDC request split?',
                "What is my tuition balance in {$displayCurrency->value}?",
                'What are the assistance guidelines?',
                'How does currency rate locking work?',
            ],
        ]);
    }
}
