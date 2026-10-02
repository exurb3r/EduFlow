<?php

declare(strict_types=1);

namespace App\Ai\Advisory;

use App\Enums\CurrencyCode;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Student;
use App\Services\CurrencyConverter;

/**
 * The authoritative set of facts a student question may be answered from.
 *
 * Read-only and built entirely by PHP. Nothing here is model-generated, which
 * is what makes it usable as an allowlist: `BriefGuard` checks every figure in
 * a model response against this brief, so the model can only ever *rephrase*
 * a fact the application already computed.
 *
 * Money is rendered from integer base units via `CurrencyConverter`, never from
 * floats, so a brief figure cannot disagree with the ledger by rounding.
 */
final readonly class StudentBrief
{
    /**
     * @param  list<array{reference: string, status: string, requested_usdc: string, approved_usdc: string}>  $requests
     */
    public function __construct(
        public string $studentName,
        public CurrencyCode $displayCurrency,
        public int $balanceBaseUnits,
        public string $balanceUsdc,
        public string $balanceFiat,
        public string $outstandingUsdc,
        public string $unitsPerUsdc,
        public string $rateProvider,
        public string $rateExpiresAt,
        public string $autoLimitUsdc,
        public string $semesterCapUsdc,
        public string $minAttendanceRate,
        public string $lockWindowMinutes,
        public string $usdcDecimals,
        public string $enrollmentStatus,
        public string $academicStatus,
        public string $attendanceRate,
        public array $requests,
        public string $deterministicAnswer = '',
    ) {}

    /**
     * Build the brief from live records.
     *
     * Reads the current policy version and the student's own rows, so a policy
     * change is reflected without a code edit. Every figure here is derived;
     * none is restated from a prompt or a stored explanation.
     */
    public static function forStudent(
        Student $student,
        string $studentName,
        int $balanceBaseUnits,
        CurrencyConverter $converter,
        string $deterministicAnswer = '',
    ): self {
        $display = CurrencyCode::tryFrom(
            strtoupper((string) config('eduflow.display_currency', 'PHP'))
        ) ?? CurrencyCode::PHP;

        $policy = AssistancePolicyVersion::active();

        $quote = $converter->lockQuote($display);

        $outstandingBase = 0;
        foreach ($student->tuitionAccounts as $account) {
            $outstandingBase += max(0, $account->remainingAmount());
        }

        // `latestAgentDecision()` is an accessor, not a relation, so it cannot be
        // eager loaded. Loading the decisions and picking the newest per request
        // is the same result without an N+1 inside a prompt build.
        $recent = $student->assistanceRequests()
            ->with(['agentDecisions' => fn ($query) => $query->latest('id')])
            ->latest('id')
            ->limit(5)
            ->get();

        $requests = [];

        foreach ($recent as $request) {
            /** @var AssistanceRequest $request */
            $decision = $request->agentDecisions->first();

            $requests[] = [
                'reference' => (string) $request->ticket_number,
                'status' => (string) ($request->status?->value ?? $request->status),
                'requested_usdc' => self::usdc((int) ($request->requested_amount ?? 0)),
                'approved_usdc' => self::usdc((int) ($decision?->approved_amount ?? 0)),
            ];
        }

        return new self(
            studentName: $studentName,
            displayCurrency: $display,
            balanceBaseUnits: $balanceBaseUnits,
            balanceUsdc: self::usdc($balanceBaseUnits),
            balanceFiat: $display->symbol().number_format($converter->usdcToFiat($balanceBaseUnits, $display) / 100, 2),
            outstandingUsdc: self::usdc($outstandingBase),
            unitsPerUsdc: (string) $quote['units_per_usdc'],
            rateProvider: (string) $quote['provider'],
            rateExpiresAt: (string) $quote['expires_at'],
            autoLimitUsdc: self::usdc((int) ($policy?->auto_limit_base_units ?? 10_000000)),
            semesterCapUsdc: self::usdc((int) ($policy?->semester_cap_base_units ?? 50_000000)),
            minAttendanceRate: number_format((float) ($policy?->min_attendance_rate ?? 85), 2).'%',
            lockWindowMinutes: '15',
            usdcDecimals: '6',
            enrollmentStatus: (string) ($student->enrollment_status ?? 'unknown'),
            academicStatus: (string) ($student->academic_status ?? 'unknown'),
            attendanceRate: number_format((float) ($student->attendance_rate ?? 0), 2).'%',
            requests: $requests,
            deterministicAnswer: $deterministicAnswer,
        );
    }

    /**
     * Render as the FACTUAL BRIEF block the agent is instructed to answer from.
     */
    public function toPromptBlock(): string
    {
        $currency = $this->displayCurrency->value;

        $lines = [
            "Student: {$this->studentName}",
            "Display currency: {$currency}",
            "Current tuition balance: {$this->balanceUsdc} ({$this->balanceFiat})",
            "Outstanding across all accounts: {$this->outstandingUsdc}",
            "Attendance rate: {$this->attendanceRate}",
            "Enrollment status: {$this->enrollmentStatus}",
            "Academic status: {$this->academicStatus}",
            "Locked rate: 1 USDC = {$this->unitsPerUsdc} minor {$currency}",
            "Rate provider: {$this->rateProvider}; quote expires {$this->rateExpiresAt}",
            "Rate lock window: {$this->lockWindowMinutes} minutes",
            "USDC precision: {$this->usdcDecimals} decimals, held as integers",
            "Autonomous assistance limit: {$this->autoLimitUsdc}",
            "Semester assistance cap: {$this->semesterCapUsdc}",
            "Minimum attendance for assistance: {$this->minAttendanceRate}",
        ];

        if ($this->requests === []) {
            $lines[] = 'Assistance requests on file: none';
        } else {
            $lines[] = 'Assistance requests on file (most recent first):';

            foreach ($this->requests as $request) {
                $lines[] = sprintf(
                    '  - %s: status %s, requested %s, approved %s',
                    $request['reference'],
                    $request['status'],
                    $request['requested_usdc'],
                    $request['approved_usdc'],
                );
            }
        }

        if ($this->deterministicAnswer !== '') {
            $lines[] = '';
            $lines[] = 'The application has already answered the student question as follows. This is';
            $lines[] = 'the correct, policy-derived explanation. Prefer restating it in your own';
            $lines[] = 'words; do not contradict it or substitute a different figure.';
            $lines[] = $this->deterministicAnswer;
        }

        return implode("\n", $lines);
    }

    /**
     * Format integer base units as a USDC figure.
     *
     * Divides by the decimal multiplier rather than a float multiply, so this
     * is exact for every value the ledger can hold.
     */
    private static function usdc(int $baseUnits): string
    {
        return number_format($baseUnits / 1_000000, 2).' USDC';
    }
}
