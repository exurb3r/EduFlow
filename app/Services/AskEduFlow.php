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
     * @param  array{tuition_balance_base_units?:int, display_currency?:string}  $context
     */
    public function answer(string $question, array $context = []): string
    {
        $q = strtolower($question);
        $display = CurrencyCode::tryFrom(strtoupper($context['display_currency'] ?? 'PHP')) ?? CurrencyCode::PHP;
        $balanceBase = (int) ($context['tuition_balance_base_units'] ?? 0);

        if (str_contains($q, 'why') && str_contains($q, '150')) {
            return 'Because the autonomous limit is 100 USDC. A 150 USDC request is split into 100 USDC auto-approved and 50 USDC escalated for human review to protect reserves.';
        }

        if (str_contains($q, 'balance') || str_contains($q, 'tuition')) {
            $fiat = $this->converter->usdcToFiat($balanceBase, $display);

            return 'Your tuition balance is '.number_format($balanceBase / 1000000, 2).' USDC (≈ '.$display->symbol().number_format($fiat / 100, 2).' '.$display->value.').';
        }

        if (str_contains($q, 'rate') || str_contains($q, 'convert') || str_contains($q, 'php') || str_contains($q, 'exchange')) {
            $quote = $this->converter->lockQuote($display);

            return "Current locked quote: 1 USDC ≈ {$quote['units_per_usdc']} minor {$display->value} (provider {$quote['provider']}, expires {$quote['expires_at']}).";
        }

        if (str_contains($q, 'assist') || str_contains($q, 'aid') || str_contains($q, 'policy') || str_contains($q, 'guideline')) {
            $policy = AssistancePolicyVersion::active();

            $limit = $policy ? number_format($policy->auto_limit_base_units / 1000000, 2) : '100.00';

            return "Assistance guidelines: enrolled + qualified + ≥85% attendance + outstanding tuition required. Auto-limit {$limit} USDC; remainder escalated. Semester cap applies.";
        }

        // Tuition account lookup hint
        if (str_contains($q, 'juan') || str_contains($q, 'account')) {
            $total = TuitionAccount::query()->sum('total_amount');

            return 'Tuition ledger holds '.number_format($total / 1000000, 2).' USDC across accounts (base units, integer math).';
        }

        return 'I can explain tuition balance, conversion rates, and assistance guidelines. Try: "What is my tuition balance?" or "Why didn\'t you send the full 150 USDC?"';
    }
}
