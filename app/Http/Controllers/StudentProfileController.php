<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\PreviewStudentEligibility;
use App\Enums\CurrencyCode;
use App\Models\AcademicTerm;
use App\Models\AgentDecision;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Services\CurrencyConverter;
use App\Services\InstallationInstitution;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Student profile: academic standing, and what that standing unlocks.
 *
 * Read-only. The eligibility block reuses PreviewStudentEligibility so the
 * profile and the dashboard can never disagree about whether a student passes.
 * It is a preview of the deterministic gates, not an approval: nothing here
 * files a request or records an agent decision.
 */
class StudentProfileController extends Controller
{
    public function show(
        Request $request,
        CurrencyConverter $converter,
        InstallationInstitution $institutions,
    ): Response {
        abort_unless($request->user()->hasRole('student'), 403);

        $user = $request->user();
        $student = $user->student()->firstOrFail();

        $account = $student->tuitionAccounts()
            ->with('academicTerm')
            ->get()
            ->first(fn (TuitionAccount $candidate): bool => $candidate->academicTerm?->isActive() === true);

        $institution = $institutions->current();
        $policy = AssistancePolicyVersion::active($institution?->id);
        $fund = $this->assistanceFund($institution);

        $displayCurrency = CurrencyCode::tryFrom(strtoupper((string) config('eduflow.display_currency', 'PHP')))
            ?? CurrencyCode::PHP;

        $walletAddress = $student->hasValidPayoutAddress() ? $student->payout_address : $user->wallet_address;

        return Inertia::render('student/profile', [
            'student' => [
                'name' => $user->name,
                'email' => $user->email,
                'student_number' => $student->student_number,
                'program' => $student->program,
                'year_level' => $student->year_level,
                'enrolled_at' => $student->created_at?->toIso8601String(),
            ],
            'organization' => [
                'name' => $institution?->name ?? config('app.name'),
            ],
            'term' => $this->term($account?->academicTerm),
            'standing' => $this->standing($student, $policy),
            'tuition' => $this->tuition($account, $converter, $displayCurrency),
            'wallet' => [
                'address' => $walletAddress,
            ],
            'eligibility' => app(PreviewStudentEligibility::class)->preview($student),
            'assistance' => $this->assistance($policy, $fund, $walletAddress, $student->assistanceRequests()->count()),
            'timeline' => $this->timeline($walletAddress, $student->assistanceRequests()->with('agentDecisions')->latest('submitted_at')->limit(8)->get()),
            'currency' => [
                'display' => $displayCurrency->value,
                'symbol' => $displayCurrency->symbol(),
                'rate_description' => sprintf(
                    '1 USDC ≈ %s minor %s',
                    number_format((float) $converter->lockQuote($displayCurrency)['units_per_usdc'], 2, '.', ''),
                    $displayCurrency->value,
                ),
            ],
        ]);
    }

    /**
     * @return array{name: string, starts_on: string, ends_on: string, progress: int}
     */
    private function term(?AcademicTerm $academicTerm): array
    {
        $startsOn = $academicTerm?->starts_on ?? today()->startOfYear();
        $endsOn = $academicTerm?->ends_on ?? today()->endOfYear();

        // Clamp at both ends: a future or missing term must never report
        // negative or over-full progress. Carbon's diffInSeconds is signed, so
        // elapsed is measured from the start forward rather than backwards.
        $span = max(1, $startsOn->diffInSeconds($endsOn));
        $elapsed = min($span, max(0, $startsOn->diffInSeconds(Carbon::now())));

        return [
            'name' => $academicTerm?->name ?? 'No active term',
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'progress' => min(100, (int) round(($elapsed / $span) * 100)),
        ];
    }

    /**
     * @return array{enrollment_status: string, enrollment_label: string, academic_status: string, academic_label: string, academic_note: string, attendance_rate: string, min_attendance_rate: string, credits_registered: int, credits_required: int, gpa: string|null}
     */
    private function standing(Student $student, ?AssistancePolicyVersion $policy): array
    {
        return [
            'enrollment_status' => $student->enrollment_status,
            'enrollment_label' => $this->humanise($student->enrollment_status),
            'academic_status' => $student->academic_status,
            'academic_label' => $this->humanise($student->academic_status),
            'academic_note' => $student->academic_status === 'qualified'
                ? 'Clear to continue into the next term.'
                : 'Your adviser has been notified.',
            'attendance_rate' => number_format((float) ($student->attendance_rate ?? 0), 2, '.', ''),
            'min_attendance_rate' => number_format((float) ($policy?->min_attendance_rate ?? 0), 2, '.', ''),
            // Credit load and GPA are not modelled yet; the page treats an
            // absent value as "not recorded" rather than showing a zero.
            'credits_registered' => 0,
            'credits_required' => 0,
            'gpa' => null,
        ];
    }

    /**
     * @return array{term: string, total_amount: string, paid_amount: string, remaining_amount: string, remaining_amount_fiat: string|null, due_on: string|null}
     */
    private function tuition(?TuitionAccount $account, CurrencyConverter $converter, CurrencyCode $displayCurrency): array
    {
        return [
            'term' => $account?->academicTerm?->name ?? 'No active term',
            'total_amount' => (string) ($account?->total_amount ?? 0),
            'paid_amount' => (string) ($account?->paid_amount ?? 0),
            'remaining_amount' => (string) ($account?->remainingAmount() ?? 0),
            // formatDual already renders the USDC figure alongside the fiat one
            'remaining_amount_fiat' => $account instanceof TuitionAccount
                ? $converter->formatDual($account->remainingAmount(), $displayCurrency)
                : null,
            'due_on' => $account?->academicTerm?->ends_on?->toDateString(),
        ];
    }

    /**
     * @return array{currency: string, auto_limit_base_units: string, semester_cap_base_units: string, received_base_units: string, fund_balance_base_units: string, request_count: int}
     */
    private function assistance(
        ?AssistancePolicyVersion $policy,
        ?AssistanceFund $fund,
        ?string $walletAddress,
        int $requestCount,
    ): array {
        // Transactions store whole USDC as a float; the UI needs base units, so
        // round at the boundary rather than letting a float reach the client.
        $receivedWholeUsdc = (float) Transaction::query()
            ->where('recipient_address', $walletAddress ?? '')
            ->whereIn('type', ['student_assistance', 'refund'])
            ->where('status', 'confirmed')
            ->sum('amount');

        return [
            'currency' => 'USDC',
            'auto_limit_base_units' => (string) ($policy?->auto_limit_base_units ?? 0),
            'semester_cap_base_units' => (string) ($policy?->semester_cap_base_units ?? 0),
            'received_base_units' => (string) ((int) round($receivedWholeUsdc) * 1_000_000),
            'fund_balance_base_units' => (string) ($fund?->balance_base_units ?? 0),
            'request_count' => $requestCount,
        ];
    }

    /**
     * A short reverse-chronological ledger of what actually happened, so the
     * student reads their record rather than a summary written about them.
     *
     * @param  Collection<int, AssistanceRequest>  $requests
     * @return list<array{id: string, at: string, title: string, detail: string, tone: string, kind: string}>
     */
    private function timeline(?string $walletAddress, Collection $requests): array
    {
        $entries = [];

        $payouts = Transaction::query()
            ->where('recipient_address', $walletAddress ?? '')
            ->whereIn('type', ['student_assistance', 'refund'])
            ->where('status', 'confirmed')
            ->latest('executed_at')
            ->limit(5)
            ->get();

        foreach ($payouts as $transaction) {
            $entries[] = [
                'id' => 'tx-'.$transaction->id,
                'at' => ($transaction->executed_at ?? $transaction->created_at)?->toIso8601String() ?? now()->toIso8601String(),
                'title' => $transaction->type->getLabel().' paid',
                'detail' => sprintf(
                    '%s USDC settled on %s and verified against your payout address.',
                    // transactions.amount is whole USDC, unlike every other
                    // monetary column on the student side
                    number_format((float) $transaction->amount, 2, '.', ''),
                    $transaction->network,
                ),
                'tone' => 'good',
                'kind' => 'assistance',
            ];
        }

        foreach ($requests as $request) {
            $decision = $request->latestAgentDecision();
            $escalated = $decision instanceof AgentDecision && $decision->requires_approval;
            $requested = $this->usdc((int) ($request->requested_amount ?? 0));

            $entries[] = [
                'id' => 'req-'.$request->id,
                'at' => ($request->submitted_at ?? $request->created_at)?->toIso8601String() ?? now()->toIso8601String(),
                'title' => $this->humanise((string) ($request->type ?? 'assistance')).' assistance requested',
                'detail' => $escalated
                    ? sprintf('%s USDC requested. The portion above the automatic limit went to a human reviewer.', $requested)
                    : sprintf('%s USDC requested. EduFlow evaluated it against the assistance policy.', $requested),
                'tone' => $escalated ? 'watch' : 'neutral',
                'kind' => 'assistance',
            ];
        }

        usort($entries, fn (array $left, array $right): int => strcmp($right['at'], $left['at']));

        return $entries;
    }

    private function assistanceFund(?Organization $institution): ?AssistanceFund
    {
        if (! $institution instanceof Organization) {
            return null;
        }

        return AssistanceFund::query()
            ->where('organization_id', $institution->id)
            ->orderBy('id')
            ->first();
    }

    private function humanise(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    /** Format USDC base units for the timeline detail lines. */
    private function usdc(int $baseUnits): string
    {
        return number_format($baseUnits / 1_000_000, 2, '.', '');
    }
}
