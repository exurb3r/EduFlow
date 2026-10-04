<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Advisory\QnaAnswer;
use App\Ai\Advisory\QnaGate;
use App\Ai\Advisory\StudentBrief;
use App\Enums\CurrencyCode;
use App\Models\AssistancePolicyVersion;
use Illuminate\Database\Eloquent\Model;

class AskEduFlow
{
    public function __construct(
        private readonly CurrencyConverter $converter,
        private readonly QnaGate $qna,
    ) {}

    /**
     * Deterministic student Q&A. No LLM fund control; pure read-only explanation.
     *
     * @param  array{tuition_balance_base_units?:int, display_currency?:string, student_name?:string}  $context
     */
    public function answer(string $question, array $context = []): string
    {
        $display = CurrencyCode::tryFrom(strtoupper($context['display_currency'] ?? 'PHP')) ?? CurrencyCode::PHP;
        $balanceBase = (int) ($context['tuition_balance_base_units'] ?? 0);

        return match ($this->classify($question)) {
            'split_decision' => $this->explainSplit($display),
            'escalation' => $this->explainEscalation(),
            'tuition_balance' => $this->explainBalance($balanceBase, $display),
            'currency_conversion' => $this->explainRate($display),
            'assistance_policy' => $this->explainPolicy(),
            'attendance' => $this->explainAttendance(),
            'hardship' => $this->explainHardship(),
            'ledger' => $this->explainLedger(),
            default => $this->explainMenu(),
        };
    }

    /**
     * Route a question to a topic.
     *
     * Order is precedence: the most specific reading wins, so a question about a
     * split is not captured by the balance branch.
     *
     * @return list<string>
     */
    private function classify(string $question): string
    {
        $q = strtolower(trim($question));

        if (
            $this->mentions($q, 'why') && (
                $this->mentions($q, 'split')
                || $this->mentions($q, 'send the full')
                || $this->mentions($q, 'not all')
                || $this->mentions($q, 'partial')
                || preg_match('/\b\d+(\.\d+)?\s*usdc\b/', $q) === 1
            )
        ) {
            return 'split_decision';
        }

        if (
            $this->mentions($q, 'remainder')
            || $this->mentionsStem($q, 'escalat')
            || $this->mentions($q, 'when')
            || $this->mentions($q, 'pending 50')
            || $this->mentions($q, 'human review')
        ) {
            return 'escalation';
        }

        if (
            $this->mentions($q, 'balance')
            || $this->mentions($q, 'tuition')
            || $this->mentions($q, 'how much do i owe')
            || $this->mentions($q, 'pay tuition')
        ) {
            return 'tuition_balance';
        }

        if (
            $this->mentions($q, 'assist')
            || $this->mentions($q, 'aid')
            || $this->mentions($q, 'policy')
            || $this->mentionsStem($q, 'guideline')
            || $this->mentionsStem($q, 'eligib')
            || $this->mentionsStem($q, 'qualif')
        ) {
            return 'assistance_policy';
        }

        if ($this->mentions($q, 'attendance')) {
            return 'attendance';
        }

        if (
            $this->mentions($q, 'rate')
            || $this->mentions($q, 'convert')
            || $this->mentions($q, 'php')
            || $this->mentions($q, 'exchange')
            || $this->mentions($q, 'eur')
            || $this->mentions($q, 'usd')
            || $this->mentions($q, 'fiat')
            || $this->mentions($q, 'slippage')
        ) {
            return 'currency_conversion';
        }

        if ($this->mentions($q, 'hardship') || $this->mentions($q, 'reason') || $this->mentions($q, 'emergency')) {
            return 'hardship';
        }

        if ($this->mentions($q, 'juan') || $this->mentions($q, 'account') || $this->mentions($q, 'ledger')) {
            return 'ledger';
        }

        return 'general';
    }

    /**
     * Explain the one-way ratchet behind a split request.
     *
     * The exact per-request split is rendered from the recorded decision
     * elsewhere; restating invented figures here would contradict it.
     */
    private function explainSplit(CurrencyCode $display): string
    {
        $quote = $this->converter->lockQuote($display);
        $autoBase = $this->autoLimitBaseUnits();
        $auto = number_format($autoBase / 1000000, 2);
        $autoFiat = $this->converter->formatDual($autoBase, $display);

        return "Because the autonomous assistance limit is {$auto} USDC. Any request above that is split rather than refused: up to {$auto} USDC ({$autoFiat}) is approved immediately, and the remainder is escalated to a human reviewer so a person stays accountable for the larger amount. The exchange rate was locked via provider {$quote['provider']} at evaluation time, so the split cannot change underneath you.";
    }

    /**
     * Explain what happens to an escalated remainder.
     */
    private function explainEscalation(): string
    {
        $pending = number_format($this->autoLimitBaseUnits() / 1000000, 2);

        return "The remaining {$pending} USDC was escalated to the school Finance Officer. Once reviewed and authorized in the /finance panel, a second Arc USDC transfer will automatically execute to your student wallet.";
    }

    /**
     * Explain the student's own balance, from integer base units.
     */
    private function explainBalance(int $balanceBase, CurrencyCode $display): string
    {
        $fiat = $this->converter->usdcToFiat($balanceBase, $display);

        return 'Your tuition balance is '.number_format($balanceBase / 1000000, 2).' USDC (≈ '.$display->symbol().number_format($fiat / 100, 2).' '.$display->value.'). Payments reduce this balance in 6-decimal integer units.';
    }

    /**
     * Explain the current locked quote.
     */
    private function explainRate(CurrencyCode $display): string
    {
        $quote = $this->converter->lockQuote($display);

        return "Current locked quote: 1 USDC ≈ {$quote['units_per_usdc']} minor {$display->value} (provider: {$quote['provider']}, expires: {$quote['expires_at']}). Rate locking guarantees 15-minute price stability so students and treasury are protected from currency slippage.";
    }

    /**
     * Explain the assistance guidelines from the live policy version.
     */
    private function explainPolicy(): string
    {
        $policy = AssistancePolicyVersion::active();
        $limit = $policy ? number_format($policy->auto_limit_base_units / 1000000, 2) : '10.00';
        $cap = $policy ? number_format($policy->semester_cap_base_units / 1000000, 2) : '50.00';
        $att = $policy ? $policy->min_attendance_rate : 85.00;

        return "Assistance guidelines under Policy v1: Enrolled status required, qualified academic standing, ≥{$att}% attendance rate, and an outstanding tuition balance. Auto-limit is {$limit} USDC (instant Arc transfer); larger amounts up to {$cap} USDC per semester are split for human authorization.";
    }

    /**
     * Explain the attendance requirement from the live policy version.
     */
    private function explainAttendance(): string
    {
        $policy = AssistancePolicyVersion::active();
        $att = $policy ? $policy->min_attendance_rate : 85.00;

        return "Institutional policy requires at least {$att}% attendance to qualify for autonomous emergency aid. Attendance is tracked deterministically in your academic record.";
    }

    /**
     * Explain what counts as a hardship statement.
     */
    private function explainHardship(): string
    {
        return 'EduFlow AI evaluates hardship statements for emergency medical, required academic equipment/books, essential subsistence, or family income shocks. Clear hardship context accelerates administrative review.';
    }

    /**
     * Explain the institution-wide tuition ledger.
     */
    private function explainLedger(): string
    {
        $total = TuitionAccount::query()->sum('total_amount');

        return 'Tuition ledger holds '.number_format($total / 1000000, 2).' USDC across accounts (base units, integer math).';
    }

    /**
     * The fallback for a question about nothing in particular.
     */
    private function explainMenu(): string
    {
        $limit = number_format($this->autoLimitBaseUnits() / 1000000, 2);

        return "I can explain tuition balance, currency conversion rates, and financial assistance guidelines. Try: \"What is my tuition balance?\" or \"Why didn't you send the full {$limit} USDC?\" or \"What are assistance guidelines?\"";
    }

    /**
     * Whether the question mentions any of the given keywords.
     *
     * Word boundaries are not a nicety here. `str_contains` matched "usd"
     * inside "USDC", so every question containing an amount in the currency the
     * system actually transacts in fell through to the exchange-rate branch and
     * was answered about rates. It also matched "aid" inside "paid". Matching
     * whole words is the difference between an answer about the student's
     * request and an answer about currency.
     *
     * @param  list<string>  $keywords
     */
    private function mentions(string $question, string ...$keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $question) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the question contains any of the given word stems.
     *
     * Anchored at the start of a word only. `eligibility` and `escalated` are
     * matched by `eligib` and `escalat`, which whole-word matching would miss.
     * The trailing boundary is deliberately absent so a stem still works, while
     * the leading one is what keeps `aid` out of `paid`.
     *
     * @param  list<string>  $stems
     */
    private function mentionsStem(string $question, string ...$stems): bool
    {
        foreach ($stems as $stem) {
            if (preg_match('/\b'.preg_quote($stem, '/').'\w*/i', $question) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The verified conversational answer, or null to keep the deterministic one.
     *
     * @param  array<string, mixed>  $context
     * @return array{text: string, conversation_id: ?string}|null
     */
    private function conversationalAnswer(
        string $question,
        array $context,
        ?Model $participant,
        ?string $conversationId,
        string $deterministicAnswer,
    ): ?array {
        if (! $participant instanceof Model) {
            return null;
        }

        $student = $participant->student ?? null;

        if ($student === null) {
            // A staff user with no student record has no personal ledger to
            // brief, so there is nothing safe to answer from.
            return null;
        }

        // The deterministic answer goes into the brief as the explanation to
        // restate, so the model never has to derive the reasoning itself. It is
        // also what makes the figure check meaningful: the brief now contains
        // every figure the correct answer needs.
        $brief = StudentBrief::forStudent(
            $student,
            (string) ($context['student_name'] ?? $participant->name ?? ''),
            (int) ($context['tuition_balance_base_units'] ?? 0),
            $this->converter,
            $deterministicAnswer,
        );

        $answer = $this->qna->answer($question, $brief, $participant, $conversationId);

        if (! $answer instanceof QnaAnswer) {
            return null;
        }

        return [
            'text' => $answer->text,
            'conversation_id' => $answer->conversationId,
        ];
    }

    /**
     * The live assistance auto-limit, in base units.
     */
    private function autoLimitBaseUnits(): int
    {
        return (int) (AssistancePolicyVersion::active()?->auto_limit_base_units ?? 10_000000);
    }

    /**
     * Rich structured response for interactive React / Inertia UI.
     *
     * The deterministic explanation is always produced first and is the answer
     * unless the conversational gate both returns a verified response and a
     * participant is supplied. That ordering matters: the model can only
     * replace text that already exists, so a provider outage costs the student
     * nothing and `EduFlowDemo` never depends on a network call.
     *
     * @param  array{tuition_balance_base_units?:int, display_currency?:string, student_name?:string}  $context
     * @param  Model|null  $participant  the authenticated user, required for a conversational answer
     * @return array{question: string, answer: string, topic: string, source: string, conversation_id: ?string, suggestedFollowups: list<string>, context: array<string, mixed>, answered_at: string}
     */
    public function query(string $question, array $context = [], ?Model $participant = null, ?string $conversationId = null): array
    {
        $answer = $this->answer($question, $context);
        $display = CurrencyCode::tryFrom(strtoupper($context['display_currency'] ?? 'PHP')) ?? CurrencyCode::PHP;

        $limit = number_format($this->autoLimitBaseUnits() / 1000000, 2);
        $pending = number_format(2 * $this->autoLimitBaseUnits() / 1000000, 2);

        $topic = $this->classify($question);
        $followups = match ($topic) {
            'split_decision' => [
                "When will the remaining {$pending} USDC be approved?",
                'How does currency rate locking work?',
                'What is my current tuition balance?',
            ],
            'tuition_balance' => [
                'How can I request assistance for tuition?',
                'What is the current exchange rate in PHP?',
                'What are the emergency assistance limits?',
            ],
            'currency_conversion' => [
                'Why was my request split?',
                'What is my tuition balance in PHP?',
                'Are rates locked during evaluation?',
            ],
            'assistance_policy', 'attendance' => [
                'What is the attendance rate requirement?',
                "What happens if my request exceeds {$limit} USDC?",
                'Can I apply more than once per semester?',
            ],
            default => [
                'What is my tuition balance?',
                'Why was my request split?',
                'What are the assistance guidelines?',
            ],
        };

        $conversation = $this->conversationalAnswer($question, $context, $participant, $conversationId, $answer);

        return [
            'question' => $question,
            'answer' => $conversation['text'] ?? $answer,
            'topic' => $topic,
            // Surfaced so the panel can tell a student the reply is model
            // phrasing over a computed record rather than the record itself.
            'source' => $conversation === null ? 'deterministic' : 'assistant',
            'conversation_id' => $conversation['conversation_id'] ?? null,
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
