<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PolicyEvaluationResult;
use App\Enums\AgentDecisionType;
use App\Enums\CurrencyCode;
use InvalidArgumentException;

class DecisionExplainer
{
    public function __construct(
        private readonly CurrencyConverter $converter,
    ) {}

    /**
     * Deterministic plain-language explanation with dual-currency transparency.
     * LLM may rephrase, but numbers and checks must come from here.
     */
    public function explain(
        PolicyEvaluationResult $result,
        int $requestedBaseUnits,
        CurrencyCode $display = CurrencyCode::PHP,
    ): string {
        $requestedFiat = $this->converter->usdcToFiat($requestedBaseUnits, $display);
        $approvedBase = (int) round($result->approvedAmount * 1000000);
        $approvedFiat = $this->converter->usdcToFiat($approvedBase, $display);
        $pendingBase = max(0, $requestedBaseUnits - $approvedBase);
        $pendingFiat = $this->converter->usdcToFiat($pendingBase, $display);

        $reqUsdc = number_format($requestedBaseUnits / 1000000, 2);
        $reqFiat = number_format($requestedFiat / 100, 2);
        $appUsdc = number_format($approvedBase / 1000000, 2);
        $appFiat = number_format($approvedFiat / 100, 2);
        $penUsdc = number_format($pendingBase / 1000000, 2);
        $penFiat = number_format($pendingFiat / 100, 2);

        return match ($result->decision) {
            AgentDecisionType::AUTO_APPROVE => "Your request of {$display->symbol()}{$reqFiat} {$display->value} (\${$reqUsdc} USDC) was evaluated against {$result->policyCode}. Because you meet all academic and attendance criteria, the full amount of \${$appUsdc} USDC ({$display->symbol()}{$appFiat} {$display->value}) was approved immediately.",
            AgentDecisionType::PARTIAL_APPROVAL => "Your request of {$display->symbol()}{$reqFiat} {$display->value} (\${$reqUsdc} USDC) was evaluated against {$result->policyCode}. Because you meet all academic and attendance criteria, the autonomous financial limit of \${$appUsdc} USDC ({$display->symbol()}{$appFiat} {$display->value}) was approved immediately. The remaining \${$penUsdc} USDC ({$display->symbol()}{$penFiat} {$display->value}) requires administrative approval to maintain institutional reserve thresholds.",
            AgentDecisionType::HOLD => "Your request of {$display->symbol()}{$reqFiat} {$display->value} (\${$reqUsdc} USDC) is currently held under {$result->policyCode}. {$result->reasoning}",
            AgentDecisionType::ESCALATE => "Your request of {$display->symbol()}{$reqFiat} {$display->value} (\${$reqUsdc} USDC) exceeds autonomous thresholds under {$result->policyCode} and has been forwarded for administrative review.",
            AgentDecisionType::REJECT => "Your request of {$display->symbol()}{$reqFiat} {$display->value} (\${$reqUsdc} USDC) was not approved under {$result->policyCode}. {$result->reasoning}",
        };
    }

    /**
     * Synthesize qualitative student hardship statement into structured context.
     *
     * @return array{category: string, urgency: string, synthesis: string, extracted_topics: list<string>}
     */
    public function synthesizeHardship(string $statement): array
    {
        $normalized = strtolower(trim($statement));
        $topics = [];

        if (str_contains($normalized, 'med') || str_contains($normalized, 'health') || str_contains($normalized, 'doctor') || str_contains($normalized, 'hospital')) {
            $topics[] = 'medical_expense';
        }
        if (str_contains($normalized, 'book') || str_contains($normalized, 'supplies') || str_contains($normalized, 'laptop') || str_contains($normalized, 'equipment') || str_contains($normalized, 'tools')) {
            $topics[] = 'academic_materials';
        }
        if (str_contains($normalized, 'tuition') || str_contains($normalized, 'fee') || str_contains($normalized, 'balance') || str_contains($normalized, 'payment')) {
            $topics[] = 'tuition_shortfall';
        }
        if (str_contains($normalized, 'food') || str_contains($normalized, 'rent') || str_contains($normalized, 'housing') || str_contains($normalized, 'living') || str_contains($normalized, 'transport')) {
            $topics[] = 'living_costs';
        }
        if (str_contains($normalized, 'family') || str_contains($normalized, 'parent') || str_contains($normalized, 'job') || str_contains($normalized, 'income') || str_contains($normalized, 'unemploy')) {
            $topics[] = 'family_income_shock';
        }

        $isUrgent = str_contains($normalized, 'urgent')
            || str_contains($normalized, 'emergency')
            || str_contains($normalized, 'immediately')
            || str_contains($normalized, 'critical');

        $primaryCategory = $topics[0] ?? 'general_emergency_aid';
        $urgency = $isUrgent ? 'high' : 'standard';

        $readableCategory = match ($primaryCategory) {
            'medical_expense' => 'Emergency Medical & Health Need',
            'academic_materials' => 'Required Academic Supplies & Equipment',
            'tuition_shortfall' => 'Tuition Account Settlement Support',
            'living_costs' => 'Essential Living & Housing Subsistence',
            'family_income_shock' => 'Household Financial Hardship',
            default => 'Student Emergency Assistance',
        };

        $summary = "Applicant cites {$readableCategory}. Statement indicates {$urgency} urgency requirement for educational continuity.";

        return [
            'category' => $readableCategory,
            'urgency' => $urgency,
            'synthesis' => $summary,
            'extracted_topics' => $topics === [] ? ['general'] : $topics,
        ];
    }

    /**
     * Build the complete dual-currency explainability package with hardship synthesis.
     *
     * @return array<string, mixed>
     */
    public function explainWithHardship(
        PolicyEvaluationResult $result,
        int $requestedBaseUnits,
        ?string $hardshipStatement = null,
        CurrencyCode $display = CurrencyCode::PHP,
        array $quote = [],
    ): array {
        $hardship = $this->synthesizeHardship($hardshipStatement ?? 'Emergency aid assistance.');
        $plainExplanation = $this->explain($result, $requestedBaseUnits, $display);

        $requestedFiat = $this->converter->usdcToFiat($requestedBaseUnits, $display);
        $approvedBase = (int) round($result->approvedAmount * 1000000);
        $approvedFiat = $this->converter->usdcToFiat($approvedBase, $display);
        $pendingBase = max(0, $requestedBaseUnits - $approvedBase);
        $pendingFiat = $this->converter->usdcToFiat($pendingBase, $display);

        $payload = [
            'decision' => $result->decision->value,
            'decision_label' => $result->decision->getLabel(),
            'policy' => $result->policyCode,
            'explanation' => $plainExplanation,
            'reason' => $result->reasoning,
            'hardship_context' => $hardship,
            'requiresHumanApproval' => $result->requiresHumanApproval,
            'requestedAmount' => $result->requestedAmount,
            'approvedAmount' => $result->approvedAmount,
            'pendingAmount' => round($pendingBase / 1000000, 2),
            'requestedBaseUnits' => $requestedBaseUnits,
            'approvedBaseUnits' => $approvedBase,
            'pendingBaseUnits' => $pendingBase,
            'dual_currency' => [
                'currency' => $display->value,
                'currency_symbol' => $display->symbol(),
                'requested_usdc' => number_format($requestedBaseUnits / 1000000, 2).' USDC',
                'requested_fiat' => $display->symbol().number_format($requestedFiat / 100, 2).' '.$display->value,
                'approved_usdc' => number_format($approvedBase / 1000000, 2).' USDC',
                'approved_fiat' => $display->symbol().number_format($approvedFiat / 100, 2).' '.$display->value,
                'pending_usdc' => number_format($pendingBase / 1000000, 2).' USDC',
                'pending_fiat' => $display->symbol().number_format($pendingFiat / 100, 2).' '.$display->value,
                'rate_description' => '1 USDC = '.$this->converter->unitsPerUsdc($display).' minor '.$display->value,
            ],
            'checks' => $result->checks,
            'violations' => $result->violations,
            'quote' => $quote !== [] ? $quote : $this->converter->lockQuote($display),
            'schema_version' => 'eduflow.explainability.v1',
        ];

        $this->validateSchema($payload);

        return $payload;
    }

    /**
     * Strict JSON schema validation. Rejects any missing contract fields.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateSchema(array $payload): bool
    {
        $required = [
            'decision',
            'policy',
            'explanation',
            'requiresHumanApproval',
            'requestedAmount',
            'approvedAmount',
            'dual_currency',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Explanation payload missing required schema field: [{$field}].");
            }
        }

        if (! is_numeric($payload['requestedAmount']) || ! is_numeric($payload['approvedAmount'])) {
            throw new InvalidArgumentException('Amounts must be numeric in explanation payload.');
        }

        if ($payload['approvedAmount'] > $payload['requestedAmount']) {
            throw new InvalidArgumentException('Approved amount cannot exceed requested amount.');
        }

        return true;
    }

    /**
     * Strict JSON schema for any LLM rephrasing. Validation happens in PHP, never in prompt.
     *
     * @return array<string, mixed>
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
            'explanation' => $this->explain($result, $requestedBaseUnits),
            'dual_currency' => [
                'requested' => number_format($requestedBaseUnits / 1000000, 2).' USDC',
                'approved' => number_format($result->approvedAmount, 2).' USDC',
            ],
        ];

        $this->validateSchema($payload);

        return $payload;
    }
}
