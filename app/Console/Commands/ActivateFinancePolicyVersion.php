<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ActivateFinancePolicy;
use App\Models\FinancePolicyVersion;
use App\Models\User;
use App\Services\InstallationInstitution;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:activate-finance-policy {policy : Institution policy version ID} {--reviewer= : Separate authorized staff ID attributed to activation} {--expected-activation= : Explicit current activation ID or none for first activation}', aliases: ['eduflow:activate-finance-policy'])]
#[Description('Activate a reviewed institution policy version; does not approve or enable payments.')]
class ActivateFinancePolicyVersion extends Command
{
    public function handle(ActivateFinancePolicy $activate, InstallationInstitution $institutions): int
    {
        $policyId = filter_var($this->argument('policy'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reviewerId = filter_var($this->option('reviewer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $expected = $this->option('expected-activation');
        $expectedId = $expected === 'none' ? null : filter_var($expected, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($policyId === false || $reviewerId === false || $expectedId === false) {
            $this->error('Positive policy and reviewer IDs plus explicit --expected-activation=none or current activation ID are required.');

            return self::FAILURE;
        }

        $this->warn('CLI staff IDs provide operator attribution, not proof of personal authentication. Restrict shell access; policy activation does not approve payments.');

        try {
            $institution = $institutions->require();
            /** @var User $reviewer */
            $reviewer = User::query()->whereKey($reviewerId)->firstOrFail();
            /** @var FinancePolicyVersion $policy */
            $policy = FinancePolicyVersion::query()->where('organization_id', $institution->id)->whereKey($policyId)->firstOrFail();
            $activation = $activate->handle($reviewer, $policy, $expectedId);
            $this->line(json_encode([
                'activation_id' => $activation->id,
                'policy_version_id' => $activation->finance_policy_version_id,
                'institution_id' => $activation->organization_id,
                'approved_by' => $activation->approved_by,
                'previous_activation_id' => $activation->previous_activation_id,
                'activation_digest' => $activation->activation_digest,
                'policy_digest' => $activation->policy_digest,
                'can_execute' => false,
                'payment_approval_granted' => false,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Policy activation refused. Check separate reviewer authority, institution, current activation and evidence integrity; no payments enabled.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
