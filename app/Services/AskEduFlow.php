<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CurrencyCode;
use App\Models\AssistancePolicyVersion;
use App\Models\TuitionAccount;

class AskEduFlow
{
    public function __construct(
        private readonly CurrencyConverter $converter,
    ) {}

    /**
     * Deterministic student Q&A. No LLM fund control; pure read-only explanation.
     *
     * @param  array{tuition_balance_base_units?:int, display_currency?:string, student_name?:string}  $context
     */
    public function answer(string $question, array $context = []): string
    {
        $q = strtolower(trim($question));
        $display = CurrencyCode::tryFrom(strtoupper($context['display_currency'] ?? 'PHP')) ?? CurrencyCode::PHP;
        $balanceBase = (int) ($context['tuition_balance_base_units'] ?? 0);

        if (str_contains($q, 'why') && (str_contains($q, '150') || str_contains($q, 'split') || str_contains($q, 'send the full') || str_contains($q, 'not all') || str_contains($q, 'partial'))) {
            $quote = $this->converter->lockQuote($display);
            $autoFiat = $this->converter->formatDual(100_000000, $display);
            $pendingFiat = $this->converter->formatDual(50_000000, $display);

            return "Because the autonomous limit is 100 USDC. A 150 USDC request is split into 100 USDC auto-approved ({$autoFiat}) and 50 USDC ({$pendingFiat}) escalated for human review to protect institutional reserves. The rate was locked via provider {$quote['provider']}.";
        }

        if (str_contains($q, 'remainder') || str_contains($q, 'escalat') || str_contains($q, 'when') || str_contains($q, 'pending 50') || str_contains($q, 'human review')) {
            return 'The remaining 50 USDC was escalated to the school Finance Officer. Once reviewed and authorized in the /finance panel, a second Arc USDC transfer will automatically execute to your student wallet.';
        }

        if (str_contains($q, 'balance') || str_contains($q, 'tuition') || str_contains($q, 'how much do i owe') || str_contains($q, 'pay tuition')) {
            $fiat = $this->converter->usdcToFiat($balanceBase, $display);

            return 'Your tuition balance is '.number_format($balanceBase / 1000000, 2).' USDC (≈ '.$display->symbol().number_format($fiat / 100, 2).' '.$display->value.'). Payments reduce this balance in 6-decimal integer units.';
        }

        if (str_contains($q, 'rate') || str_contains($q, 'convert') || str_contains($q, 'php') || str_contains($q, 'exchange') || str_contains($q, 'eur') || str_contains($q, 'usd') || str_contains($q, 'fiat') || str_contains($q, 'slippage')) {
            $quote = $this->converter->lockQuote($display);

            return "Current locked quote: 1 USDC ≈ {$quote['units_per_usdc']} minor {$display->value} (provider: {$quote['provider']}, expires: {$quote['expires_at']}). Rate locking guarantees 15-minute price stability so students and treasury are protected from currency slippage.";
        }

        if (str_contains($q, 'assist') || str_contains($q, 'aid') || str_contains($q, 'policy') || str_contains($q, 'guideline') || str_contains($q, 'eligib') || str_contains($q, 'qualif')) {
            $policy = AssistancePolicyVersion::active();
            $limit = $policy ? number_format($policy->auto_limit_base_units / 1000000, 2) : '100.00';
            $cap = $policy ? number_format($policy->semester_cap_base_units / 1000000, 2) : '500.00';
            $att = $policy ? $policy->min_attendance_rate : 85.00;

            return "Assistance guidelines under Policy v1: Enrolled status required, qualified academic standing, ≥{$att}% attendance rate, and an outstanding tuition balance. Auto-limit is {$limit} USDC (instant Arc transfer); larger amounts up to {$cap} USDC per semester are split for human authorization.";
        }

        if (str_contains($q, 'attendance')) {
            $policy = AssistancePolicyVersion::active();
            $att = $policy ? $policy->min_attendance_rate : 85.00;

            return "Institutional policy requires at least {$att}% attendance to qualify for autonomous emergency aid. Attendance is tracked deterministically in your academic record.";
        }

        if (str_contains($q, 'hardship') || str_contains($q, 'reason') || str_contains($q, 'emergency')) {
            return 'EduFlow AI evaluates hardship statements for emergency medical, required academic equipment/books, essential subsistence, or family income shocks. Clear hardship context accelerates administrative review.';
        }

        if (str_contains($q, 'juan') || str_contains($q, 'account') || str_contains($q, 'ledger')) {
            $total = TuitionAccount::query()->sum('total_amount');

            return 'Tuition ledger holds '.number_format($total / 1000000, 2).' USDC across accounts (base units, integer math).';
        }

        return 'I can explain tuition balance, currency conversion rates, and financial assistance guidelines. Try: "What is my tuition balance?" or "Why didn\'t you send the full 150 USDC?" or "What are assistance guidelines?"';
    }

    /**
     * Rich structured response for interactive React / Inertia UI.
     *
     * @param  array{tuition_balance_base_units?:int, display_currency?:string, student_name?:string}  $context
     * @return array{question: string, answer: string, topic: string, suggestedFollowups: list<string>, context: array<string, mixed>, answered_at: string}
     */
    public function query(string $question, array $context = []): array
    {
        $answer = $this->answer($question, $context);
        $q = strtolower($question);
        $display = CurrencyCode::tryFrom(strtoupper($context['display_currency'] ?? 'PHP')) ?? CurrencyCode::PHP;

        $topic = 'general';
        $followups = [
            'What is my tuition balance?',
            'Why was my 150 USDC request split?',
            'What are the assistance guidelines?',
        ];

        if (str_contains($q, '150') || str_contains($q, 'split') || str_contains($q, 'why')) {
            $topic = 'split_decision';
            $followups = [
                'When will the remaining 50 USDC be approved?',
                'How does currency rate locking work?',
                'What is my current tuition balance?',
            ];
        } elseif (str_contains($q, 'balance') || str_contains($q, 'tuition')) {
            $topic = 'tuition_balance';
            $followups = [
                'How can I request assistance for tuition?',
                'What is the current exchange rate in PHP?',
                'What are the emergency assistance limits?',
            ];
        } elseif (str_contains($q, 'rate') || str_contains($q, 'convert') || str_contains($q, 'php') || str_contains($q, 'exchange')) {
            $topic = 'currency_conversion';
            $followups = [
                'Why was my 150 USDC request split?',
                'What is my tuition balance in PHP?',
                'Are rates locked during evaluation?',
            ];
        } elseif (str_contains($q, 'assist') || str_contains($q, 'aid') || str_contains($q, 'guideline') || str_contains($q, 'attendance')) {
            $topic = 'assistance_policy';
            $followups = [
                'What is the attendance rate requirement?',
                'What happens if my request exceeds 100 USDC?',
                'Can I apply more than once per semester?',
            ];
        }

        return [
            'question' => $question,
            'answer' => $answer,
            'topic' => $topic,
            'suggestedFollowups' => $followups,
            'context' => [
                'display_currency' => $display->value,
                'currency_symbol' => $display->symbol(),
                'units_per_usdc' => $this->converter->unitsPerUsdc($display),
            ],
            'answered_at' => now()->toIso8601String(),
        ];
    }
}
