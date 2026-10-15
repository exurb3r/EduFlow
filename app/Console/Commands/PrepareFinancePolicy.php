<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateFinancePolicyVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:prepare-finance-policy {version : Stable policy version identifier} {--actor= : Authorized staff ID attributed to preparation} {--reserve= : Required exact USDC minimum reserve} {--auto-limit=0 : Exact USDC automatic payment limit} {--daily-limit=0 : Exact USDC daily disbursement limit} {--max-fee=0 : Exact USDC fee ceiling}', aliases: ['eduflow:prepare-finance-policy'])]
#[Description('Prepare an immutable inactive institution finance policy; does not enable payments.')]
class PrepareFinancePolicy extends Command
{
    public function handle(CreateFinancePolicyVersion $prepare): int
    {
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reserve = $this->option('reserve');
        if ($actorId === false || ! is_string($reserve) || $reserve === '') {
            $this->error('Positive authorized staff ID and explicit exact USDC reserve are required.');

            return self::FAILURE;
        }

        $this->warn('CLI staff IDs provide operator attribution, not proof of personal authentication. Restrict shell access; policy activation does not approve payments.');

        try {
            /** @var User $actor */
            $actor = User::query()->whereKey($actorId)->firstOrFail();
            $policy = $prepare->handle(
                $actor, (string) $this->argument('version'), Money::fromDecimal($reserve, CurrencyCode::USDC),
                Money::fromDecimal((string) $this->option('auto-limit'), CurrencyCode::USDC),
                Money::fromDecimal((string) $this->option('daily-limit'), CurrencyCode::USDC),
                Money::fromDecimal((string) $this->option('max-fee'), CurrencyCode::USDC),
            );
            $this->line(json_encode([
                'policy_version_id' => $policy->id,
                'institution_id' => $policy->organization_id,
                'content_digest' => $policy->content_digest,
                'content' => $policy->content(),
                'can_execute' => false,
                'payment_approval_granted' => false,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Policy preparation refused. Check staff authority, institution, version and exact USDC bounds; no payments enabled.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
