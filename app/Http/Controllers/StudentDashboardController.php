<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\PreviewStudentEligibility;
use App\DTOs\Money;
use App\DTOs\StudentWalletSnapshot;
use App\Enums\AgentDecisionType;
use App\Enums\CurrencyCode;
use App\Models\AgentDecision;
use App\Models\AssistanceRequest;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\StudentWalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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

        return app(DashboardController::class)->index($request, app(CurrencyConverter::class));
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
            $heldBase = (int) $account->heldAmount();
            $effectiveRemainingBase = (int) $account->effectiveRemainingAmount();

            $tuitionAccountData = [
                'id' => $account->getKey(),
                'term' => $account->academicTerm->name,
                'total_amount' => (string) $account->total_amount,
                'paid_amount' => (string) $account->paid_amount,
                'held_amount' => (string) $heldBase,
                'remaining_amount' => (string) $effectiveRemainingBase,
                'unadjusted_remaining_amount' => (string) $account->remainingAmount(),
                'remaining_amount_fiat' => $converter->formatDual($effectiveRemainingBase, $displayCurrency),
                'total_amount_fiat' => $converter->formatDual($totalBase, $displayCurrency),
                'paid_amount_fiat' => $converter->formatDual($paidBase, $displayCurrency),
                'held_amount_fiat' => $converter->formatDual($heldBase, $displayCurrency),
            ];
        }

        $requests = $student->assistanceRequests()->with('agentDecisions')->latest('id')->get()->map(function (AssistanceRequest $assistance): array {
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
                'has_decision' => $decision instanceof AgentDecision,
                'decision' => $decision?->decision->value,
                'is_split' => $decision?->decision === AgentDecisionType::PARTIAL_APPROVAL,
                'auto_approved_amount' => (string) $auto,
                'pending_amount' => (string) $pending,
            ];
        });

        $walletAddress = app(StudentWalletService::class)->addressFor($student);
        $walletSnapshot = app(StudentWalletService::class)->snapshot($student);

        $recentTransactions = Transaction::query()
            ->paidTo($walletAddress)
            ->whereIn('type', ['student_assistance', 'refund'])
            ->latest('executed_at')
            ->limit(5)
            ->get()
            ->map(fn ($transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'type_label' => $transaction->type->getLabel(),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status->value,
                'status_label' => $transaction->status->getLabel(),
                'tx_hash' => $transaction->provider_tx_hash,
                'network' => $transaction->network,
                'executed_at' => $transaction->executed_at?->format('M d, Y h:i A'),
            ])
            ->values();

        $confirmedTotal = (float) Transaction::query()
            ->paidTo($walletAddress)
            ->whereIn('type', ['student_assistance', 'refund'])
            ->where('status', 'confirmed')
            ->sum('amount');

        return Inertia::render('student/dashboard', [
            'wallet' => [
                'address' => $walletAddress,
                'snapshot' => $walletSnapshot->toArray(),
                'balance_fiat' => $this->walletBalanceFiat($walletSnapshot, $converter, $displayCurrency),
            ],
            'totals' => [
                'confirmed' => $confirmedTotal,
                'currency' => 'USDC',
            ],
            'recentTransactions' => $recentTransactions,
            'eligibility' => app(PreviewStudentEligibility::class)->preview($student),
            'student' => [
                'name' => $request->user()->name,
                'student_number' => $student->student_number,
                'program' => $student->program,
                'year_level' => $student->year_level,
            ],
            'tuitionAccount' => $tuitionAccountData,
            'tuitionAttempts' => StudentTuitionPaymentAttempt::query()->where('student_id', $student->id)->latest('id')->limit(20)->get()
                ->map(fn (StudentTuitionPaymentAttempt $attempt): array => StudentTuitionPaymentController::statusData($attempt)),
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

    /**
     * The wallet balance as a local-currency figure, for a wallet card that
     * reads like the payment apps a student already uses.
     *
     * The on-chain balance is read at Arc's 18 decimals, so it is truncated to
     * USDC's 6 before the quote is applied: a fiat equivalent of sub-cent
     * precision is noise, and Amounts::fromDecimalString() rejects any more.
     */
    private function walletBalanceFiat(StudentWalletSnapshot $snapshot, CurrencyConverter $converter, CurrencyCode $displayCurrency): ?string
    {
        if (! $snapshot->isReadable() || ! is_string($snapshot->balance)) {
            return null;
        }

        $baseUnits = $this->usdcBaseUnitsFrom($snapshot->balance);

        if ($baseUnits === null) {
            return null;
        }

        try {
            $fiat = new Money($converter->usdcToFiat($baseUnits, $displayCurrency), $displayCurrency);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $displayCurrency->symbol().$fiat->format().' '.$displayCurrency->value;
    }

    /**
     * Truncate a decimal string to USDC's 6 decimals and convert to base units
     * with string arithmetic, so an 18-decimal chain value never round-trips
     * through a float. Returns null rather than guessing at an absurd figure.
     */
    private function usdcBaseUnitsFrom(string $decimal): ?int
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $decimal)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        if (strlen($whole) > 12) {
            return null;
        }

        $fraction = str_pad(substr($fraction, 0, 6), 6, '0');

        return ((int) $whole) * 1_000_000 + (int) $fraction;
    }
}
