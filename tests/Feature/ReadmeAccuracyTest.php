<?php

declare(strict_types=1);

use App\Enums\AgentDecisionType;
use App\Models\AssistancePolicyVersion;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\FinancialPolicyEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;

/**
 * The README is the first thing a newcomer reads, so its factual claims have to
 * hold. These tests assert the specific numbers and commands it advertises, and
 * fail if the code drifts away from the documentation.
 */
beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->org = Organization::firstOrFail();
});

it('quotes the real seeded scenario figures in the readme', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));

    foreach ($this->org->invoices as $invoice) {
        expect($readme)->toContain($invoice->reference);
    }

    // The thresholds the readme tells people to reason about.
    expect($readme)->toContain('120 USDC wallet')
        ->and($readme)->toContain('20 USDC reserve')
        ->and($readme)->toContain('50 USDC autonomous limit');
});

it('only documents artisan commands that exist', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));

    preg_match_all('/php artisan ([a-z][a-z0-9:-]*)/', $readme, $matches);

    expect($matches[1])->not->toBeEmpty();

    $available = array_keys(app(Kernel::class)->all());

    $missing = array_values(array_unique(array_filter(
        $matches[1],
        fn (string $command): bool => ! in_array($command, $available, true),
    )));

    expect($missing)->toBe([], 'README documents commands that do not exist: '.implode(', ', $missing));
});

it('only documents seeded demo accounts that exist', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));

    // Rows of the demo accounts table: | Role | `email` | `password` |
    preg_match_all('/\|\s*`([a-z0-9._-]+@[a-z0-9.-]+)`\s*\|\s*`([a-z0-9]+)`\s*\|/i', $readme, $matches, PREG_SET_ORDER);

    // Guard against the pattern silently matching nothing and passing vacuously.
    expect($matches)->toHaveCount(2);

    foreach ($matches as [, $email, $password]) {
        expect(User::where('email', $email)->exists())
            ->toBeTrue("README lists {$email}, but the seeder does not create it.");
        expect($password)->toBe('password');
    }
});

it('states the aid auto-limit the policy actually uses', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));
    $policy = AssistancePolicyVersion::active();

    expect($readme)->toContain(
        '**'.number_format($policy->auto_limit_base_units / 1000000, 0).' USDC** auto-limit'
    );
});

it('matches the documented disbursement total to what the cycle spends', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));
    $engine = app(FinancialPolicyEngine::class);

    $total = (float) $this->org->invoices
        ->filter(fn (Invoice $i): bool => $engine->evaluateInvoice($i, $this->org->primaryWallet())
            ->decision === AgentDecisionType::AUTO_APPROVE)
        ->sum('amount');

    // The seeded 15 USDC aid request is partially approved at the 10 USDC limit,
    // so the cycle also moves that much.
    $aidApproved = (float) AssistancePolicyVersion::active()->auto_limit_base_units / 1000000;

    expect($total)->toBe(75.00)
        ->and($aidApproved)->toBe(10.0)
        ->and($total + $aidApproved)->toBe(85.0)
        ->and($readme)->toContain('**85 USDC**')
        ->and($readme)->toContain('(30 + 45 + the 10 USDC aid');
});

it('warns about the traps the codebase actually hit', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));

    expect($readme)->toContain('20 USDC per call')       // the faucet drip size
        ->and($readme)->toContain('429')                 // the rate limit
        ->and($readme)->toContain('hexdec')              // the overflow
        ->and($readme)->toContain('USDC is the gas token')
        ->and($readme)->toContain('not allowed by the proxy')
        ->and($readme)->toContain('not proof')           // a hash is a claim
        ->and($readme)->toContain('independently');      // mainnet vs testnet
});

it('never claims a fake-driver run settles real money', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));

    expect($readme)->toContain('LEPTON_DRIVER=fake')
        ->and($readme)->toMatch('/not a simulation|never mistake a fake run/i');
});
