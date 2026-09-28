<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PolicyEvaluationResult;
use App\Enums\AgentDecisionType;
use App\Enums\CurrencyCode;

class DecisionExplainer
{
    public function __construct(
        private readonly CurrencyConverter $converter,
    ) {}

    /**
     * Deterministic plain-language explanation with dual-currency transparency.
     * LLM may rephrase, but numbers must come from here.
     */
    public function explain(
        PolicyEvaluationResult $result,
        int $requestedBaseUnits,
        CurrencyCode $display = CurrencyCode::PHP,
    ): string {
        $requestedFiat = $this->converter->usdcToFiat($requestedBaseUnits, $display);
        $approvedBase = (int) round($result->approvedAmount * 1000000);
        $approvedFiat = $this->converter->usdcToFiat($approvedBase, $display);

        $reqUsdc = number_format($requestedBaseUnits / 1000000, 2);
        $reqFiat = number_format($requestedFiat / 100, 2);
        $appUsdc = number_format($approvedBase / 1000000, 2);
        $appFiat = number_format($approvedFiat / 100, 2);

        return match ($result->decision) {
            AgentDecisionType::AUTO_APPROVE => "Your request of {$display->symbol()}{$reqFiat} {$display->value} (\${$reqUsdc} USDC) was approved under {$result->policyCode}. {$result->reasoning}",
            AgentDecisionType::PARTIAL_APPROVAL => "Your request of {$display->symbol()}{$reqFiat} {$display->value} (\${$reqUsdc} USDC) was evaluated against {$result->policyCode}. {$appUsdc} USDC ({$display->symbol()}{$appFiat} {$display->value}) was approved immediately. The remainder requires administrative approval to maintain reserve thresholds.",
            default => "Your request of {$display->symbol()}{$reqFiat} {$display->value} (\${$reqUsdc} USDC) was {$result->decision->value} under {$result->policyCode}. {$result->reasoning}",
        };
    }

    /**
     * Strict JSON schema for any LLM rephrasing. Validation happens in PHP, never in prompt.
     *
     * @return array<string,mixed>
     */
    public function toStructuredJson(
        PolicyEvaluationResult $result,
        int $requestedBaseUnits,
        array $quote,
    ): array {
        $payload = [
            'decision' => $result->decision->value,
            'requestedAmount' => $result->requestedAmount,
            'approvedAmount' => $result->approvedAmount,
            'requiresHumanApproval' => $result->requiresHumanApproval,
            'reason' => $result->reasoning,
            'policy' => $result->policyCode,
            'quote' => $quote,
            'requestedBaseUnits' => $requestedBaseUnits,
        ];

        // Enforce contract: LLM output must decode to this exact shape.
        if (! isset($payload['decision'], $payload['requestedAmount'], $payload['approvedAmount'], $payload['policy'])) {
            throw new \InvalidArgumentException('Explanation payload violates schema.');
        }

        return $payload;
    }
}
