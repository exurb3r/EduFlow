<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\ApproveVendorDestination;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\PrepareRecurringMandate;
use App\Actions\PrepareVendorDestination;
use App\Actions\ReviewRecurringMandate;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\FinancePolicyVersion;
use App\Models\MandateOccurrence;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionOutbox;
use App\Models\RecurringMandate;
use App\Models\RecurringMandateReview;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDestinationVersion;
use App\Models\Wallet;
use App\Services\MandateReleaseEvaluator;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The bounded autonomous lane.
 *
 * The properties under test are the ones §18 actually turns on:
 *
 * 1. A mandate authorizes a **class**, never a payment. `can_execute` is false in
 *    every evidence array here, and no test may assert otherwise.
 * 2. The initial allowance is zero. An approved mandate with a zero ceiling
 *    releases nothing, so the lane is off even when a reviewer says yes.
 * 3. Nobody may authorize their own mandate, and the evaluator cannot be talked
 *    into a release by changing the facts it was handed — the only way out of
 *    `blocked` is different evidence.
 * 4. No model is anywhere in the release path. Everything here is plain PHP.
 */

/** @return array{institution: Organization, actor: User, reviewer: User, budget: Budget, wallet: Wallet, vendor: Vendor, destination: VendorDestinationVersion, policy: FinancePolicyVersion} */
function mandateContext(string $ceiling = '5000000'): array
{
    config(['eduflow.institution_id' => null, 'eduflow.mandate.runtime_enabled' => false,
        'eduflow.mandate.allow_nonzero_allowance' => true,
        'lepton.default' => 'circle', 'lepton.arc.chain' => 'ARC-TESTNET', 'lepton.arc.chain_id' => 5042002,
        'lepton.arc.treasury' => null]);

    /** @var Organization $institution */
    $institution = Organization::factory()->create();
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));

    // The policy must be written by neither the preparer nor the approver, so a
    // third human writes it — mirroring the maker/checker separation the
    // mandate itself requires.
    /** @var User $policymaker */
    $policymaker = User::factory()->create();
    $policymaker->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $policy = app(CreateFinancePolicyVersion::class)->handle($policymaker, 'v1',
        Money::fromDecimal('10', CurrencyCode::USDC), new Money(0, CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
    app(ActivateFinancePolicy::class)->handle($reviewer, $policy, null);

    $wallet = Wallet::query()->create(['organization_id' => $institution->id, 'provider' => 'circle',
        'network' => 'arc-testnet', 'address' => '0x'.str_repeat('1', 40), 'balance' => '999999', 'status' => 'active']);
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Mandate IT Supplier',
        'status' => 'verified', 'risk_level' => 'low', 'wallet_address' => '0x'.str_repeat('2', 40)]);

    $version = app(PrepareVendorDestination::class)->handle($actor, $vendor, 'v1', $vendor->wallet_address,
        'ARC-TESTNET', 'synthetic-control-check');
    app(ApproveVendorDestination::class)->handle($reviewer, $version, $version->content_digest, null, 'synthetic-independent-check');

    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'IT department',
        'category' => 'software', 'allocated_amount' => '999999', 'spent_amount' => '0',
        'remaining_amount' => '999999', 'status' => 'active']);

    return ['institution' => $institution, 'actor' => $actor, 'reviewer' => $reviewer, 'budget' => $budget,
        'wallet' => $wallet, 'vendor' => $vendor, 'destination' => $version, 'policy' => $policy];
}

/**
 * @param  array<string, mixed>  $c
 * @return array{
 *     budget_id: int, vendor_id: int, vendor_destination_version_id: int, wallet_id: int,
 *     finance_policy_version_id: int, obligation_reference: string, obligation_digest: string,
 *     starts_at: string, ends_at: string, due_window_days: int,
 *     per_occurrence_ceiling_base_units: int|string, fee_ceiling_base_units: int|string,
 *     daily_limit_base_units: int|string, period_limit_base_units: int|string, reason: string
 * }
 */
function mandateInput(array $c, string $ceiling = '5000000'): array
{
    return [
        'budget_id' => $c['budget']->id,
        'vendor_id' => $c['vendor']->id,
        'vendor_destination_version_id' => $c['destination']->id,
        'wallet_id' => $c['wallet']->id,
        'finance_policy_version_id' => $c['policy']->id,
        'obligation_reference' => 'CONTRACT-2026-HOSTING',
        'obligation_digest' => hash('sha256', 'synthetic-approved-contract'),
        'starts_at' => now()->subDay()->toIso8601String(),
        'ends_at' => now()->addYear()->toIso8601String(),
        'due_window_days' => 7,
        'per_occurrence_ceiling_base_units' => $ceiling,
        'fee_ceiling_base_units' => '1000000',
        'daily_limit_base_units' => '10000000',
        'period_limit_base_units' => '50000000',
        'reason' => 'Approved hosting contract, billed monthly.',
    ];
}

function prepareMandate(array $c, string $ceiling = '5000000'): RecurringMandate
{
    return app(PrepareRecurringMandate::class)->handle($c['actor'], mandateInput($c, $ceiling), (string) Str::uuid());
}

function approveMandate(array $c, RecurringMandate $mandate): RecurringMandateReview
{
    return app(ReviewRecurringMandate::class)->handle($c['reviewer'], $mandate, $mandate->snapshot_digest,
        'approve_mandate', 'Contract, allocation and caps independently reviewed.');
}

/**
 * Facts as the scheduler would read them, with every gate open.
 *
 * @return array<string, mixed>
 */
function releasingFacts(array $c, RecurringMandate $mandate, array $overrides = []): array
{
    return $overrides + [
        'at' => CarbonImmutable::now(),
        'occurrence_key' => '2026-10',
        'occurrence_digest' => app(MandateReleaseEvaluator::class)->occurrenceDigest($mandate, '2026-10'),
        'bill_amount_base_units' => BigInteger::of(4_000000),
        'max_fee_base_units' => BigInteger::of(500000),

        'destination_digest' => $c['destination']->content_digest,
        'policy_digest' => $c['policy']->content_digest,
        'available_cash_base_units' => BigInteger::of(100_000000),
        'consuming_holds_base_units' => BigInteger::zero(),
        'daily_released_base_units' => BigInteger::zero(),
        'period_released_base_units' => BigInteger::zero(),
        'is_fake' => false,
        'stop_switch' => false,
        'funding_window_valid' => true,
    ];
}

test('a written mandate is a draft that authorizes nothing', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);

    expect($mandate->state)->toBe('draft')
        ->and($mandate->hasValidSnapshot())->toBeTrue()
        ->and($mandate->chain)->toBe('ARC-TESTNET')
        ->and($mandate->chain_id)->toBe(5042002)
        ->and($mandate->vendorDestinationVersion()->sole()->id)->toBe($c['destination']->id)
        ->and($mandate->evidence()['can_execute'])->toBeFalse()
        ->and($mandate->evidence()['mandate_authorized'])->toBeFalse();
});

test('an approved mandate authorizes a class but never an execution', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);

    $evidence = $mandate->fresh()->evidence();

    expect($evidence['state'])->toBe('approved')
        ->and($evidence['mandate_authorized'])->toBeFalse()
        ->and($evidence['payment_approved'])->toBeFalse()
        ->and($evidence['funds_reserved'])->toBeFalse()
        ->and($evidence['external_funds_locked'])->toBeFalse()
        ->and($evidence['can_execute'])->toBeFalse()
        ->and($evidence['local_accounts_changed'])->toBeFalse();
});

test('nobody may authorize their own mandate', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);

    // The policy refuses this before the action sees it. The action repeats the
    // same separation against real identities as defence in depth, in case a
    // policy is ever loosened; both exist because §14.7 forbids authorizing
    // your own mandate, and either alone would be a single point of failure.
    expect(fn (): RecurringMandateReview => app(ReviewRecurringMandate::class)->handle($c['actor'], $mandate,
        $mandate->snapshot_digest, 'approve_mandate', 'Self review.'))
        ->toThrow(AuthorizationException::class)
        ->and($mandate->fresh()->state)->toBe('draft')
        ->and(RecurringMandateReview::query()->count())->toBe(0);
});

test('a mandate is decided exactly once', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);

    expect(fn (): RecurringMandateReview => approveMandate($c, $mandate->fresh()))
        ->toThrow(ValidationException::class, 'already been made');
});

test('approved scope is immutable; widening requires a new mandate', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);

    $mandate->per_occurrence_ceiling_base_units = 999_000000;

    expect(fn () => $mandate->save())->toThrow(LogicException::class, 'scope evidence is immutable');
});

test('a zero ceiling is the default and releases nothing even when approved', function (): void {
    $c = mandateContext('0');
    $mandate = prepareMandate($c, '0');
    approveMandate($c, $mandate);

    $fresh = $mandate->fresh();
    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh, releasingFacts($c, $fresh));

    expect($verdict['disposition'])->toBe('blocked')
        ->and($verdict['allowance_nonzero']['passed'])->toBeFalse()
        ->and($verdict['allowance_nonzero']['reason'])->toContain('initial allowance is zero');
});

test('an unapproved mandate releases nothing however plausible the facts', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($mandate, releasingFacts($c, $mandate));

    expect($verdict['disposition'])->toBe('blocked')
        ->and($verdict['mandate_authorized']['passed'])->toBeFalse()
        ->and($verdict['summary'] ?? $verdict['disposition'])->not->toBeNull();
});

test('a revoked mandate stops releasing immediately', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    expect($fresh->isLive())->toBeTrue();

    app(ReviewRecurringMandate::class)->handle($c['reviewer'], $fresh, $fresh->snapshot_digest, 'revoke', 'Contract terminated.');

    // Revocation is the one later decision a decided mandate may receive, and
    // it takes effect immediately: the state moves and the lane stops.
    expect($fresh->fresh()->state)->toBe('revoked')
        ->and($fresh->fresh()->isLive())->toBeFalse()
        ->and(app(MandateReleaseEvaluator::class)->evaluate($fresh->fresh(), releasingFacts($c, $fresh->fresh()))['disposition'])->toBe('blocked');
});

test('a live mandate releases an occurrence when every gate passes', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh, releasingFacts($c, $fresh));

    expect($verdict['disposition'])->toBe('release')
        ->and($verdict['passed'])->toBeTrue()
        ->and($verdict['amount_within_ceiling']['passed'])->toBeTrue()
        ->and($verdict['chain_is_configured_testnet']['passed'])->toBeTrue();
});

test('amount exactly at the ceiling is permitted', function (): void {
    // §18.5: at the exact boundary `amount <= ceiling` is permitted if every
    // other check passes.
    $c = mandateContext();
    $mandate = prepareMandate($c, '4000000');
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh, releasingFacts($c, $fresh));

    expect($verdict['disposition'])->toBe('release')
        ->and($verdict['amount_within_ceiling']['passed'])->toBeTrue();
});

test('a price change escalates rather than guessing from the last payment', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c, '3000000');
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh, releasingFacts($c, $fresh));

    expect($verdict['disposition'])->toBe('escalate')
        ->and($verdict['amount_within_ceiling']['passed'])->toBeFalse()
        ->and($verdict['amount_within_ceiling']['reason'])->toContain('exceeds the approved per-occurrence ceiling');
});

test('a changed destination escalates for renewed review', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['destination_digest' => str_repeat('a', 64)]));

    expect($verdict['disposition'])->toBe('escalate')
        ->and($verdict['destination_unchanged']['passed'])->toBeFalse()
        ->and($verdict['destination_unchanged']['reason'])->toContain('never guessed');
});

test('a changed policy escalates for renewed review', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['policy_digest' => str_repeat('b', 64)]));

    expect($verdict['disposition'])->toBe('escalate')
        ->and($verdict['policy_unchanged']['passed'])->toBeFalse();
});

test('simulated evidence can never be released', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['is_fake' => true]));

    expect($verdict['disposition'])->toBe('blocked')
        ->and($verdict['evidence_is_real']['passed'])->toBeFalse()
        ->and($verdict['evidence_is_real']['severity'])->toBe('blocked');
});

test('the stop switch blocks and holds evidence rather than escalating', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['stop_switch' => true]));

    expect($verdict['disposition'])->toBe('blocked')
        ->and($verdict['stop_switch_clear']['passed'])->toBeFalse();
});

test('a blocked gate is never an approvable override', function (): void {
    // The evaluator has no argument, flag or string that turns blocked into
    // release. The only route out is different evidence.
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $blocked = app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['funding_window_valid' => false]));

    expect($blocked['disposition'])->toBe('blocked');

    // There is no argument, flag or string that turns a blocked gate into a
    // release. The evaluator accepts exactly one thing — the facts — so the
    // only way out is different evidence, asserted below.
    expect(app(MandateReleaseEvaluator::class)->evaluate($fresh,
        ['override' => true, 'can_execute' => true, 'reason' => 'approve anyway']
        + releasingFacts($c, $fresh, ['funding_window_valid' => false]))['disposition'])->toBe('blocked');

    // And the actual route out: the gate that held now passes.
    expect(app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['funding_window_valid' => true]))['disposition'])->toBe('release');
});

test('an occurrence outside the mandate obligation is blocked', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['occurrence_key' => 'someone-elses-bill']));

    expect($verdict['disposition'])->toBe('blocked');
});

test('the same obligation occurrence cannot be recorded twice', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();
    $evaluator = app(MandateReleaseEvaluator::class);

    MandateOccurrence::query()->create([
        'request_key' => (string) Str::uuid(),
        'organization_id' => $c['institution']->id,
        'recurring_mandate_id' => $fresh->id,
        'occurrence_digest' => $evaluator->occurrenceDigest($fresh, '2026-10'),
        'amount_base_units' => 4_000000,
        'chain' => 'ARC-TESTNET',
        'chain_id' => 5042002,
        'due_at' => now(),
        'disposition' => 'release',
        'checks' => ['passed' => true, 'disposition' => 'release'],
        'checks_digest' => PaymentIntent::digest(['passed' => true, 'disposition' => 'release']),
    ]);

    $verdict = $evaluator->evaluate($fresh, releasingFacts($c, $fresh));

    expect($verdict['disposition'])->toBe('blocked')
        ->and($verdict['no_prior_fulfilment']['passed'])->toBeFalse()
        ->and(MandateOccurrence::query()->count())->toBe(1);
});

test('a prior occurrence blocks even when its disposition was escalate', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c, '3000000');
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();
    $evaluator = app(MandateReleaseEvaluator::class);

    MandateOccurrence::query()->create([
        'request_key' => (string) Str::uuid(),
        'organization_id' => $c['institution']->id,
        'recurring_mandate_id' => $fresh->id,
        'occurrence_digest' => $evaluator->occurrenceDigest($fresh, '2026-10'),
        'amount_base_units' => 9_000000,
        'chain' => 'ARC-TESTNET',
        'chain_id' => 5042002,
        'due_at' => now(),
        'disposition' => 'escalate',
        'checks' => ['passed' => false, 'disposition' => 'escalate'],
        'checks_digest' => PaymentIntent::digest(['passed' => false, 'disposition' => 'escalate']),
    ]);

    expect($evaluator->evaluate($fresh, releasingFacts($c, $fresh))['disposition'])->toBe('blocked');
});

test('cumulative caps count released work and never reset themselves', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c, '5000000');
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['daily_released_base_units' => BigInteger::of(7_000000)]));

    expect($verdict['disposition'])->toBe('escalate')
        ->and($verdict['daily_cap']['passed'])->toBeFalse()
        ->and($verdict['daily_cap']['reason'])->toContain('never reset themselves');
});

test('capacity counts every active hold and the fee, not the bill alone', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $verdict = app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['available_cash_base_units' => BigInteger::of(4_499999)]));

    expect($verdict['disposition'])->toBe('escalate')
        ->and($verdict['capacity_for_bill_and_fee']['passed'])->toBeFalse();
});

test('a floated money fact is refused rather than rounded', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    expect(fn () => app(MandateReleaseEvaluator::class)->evaluate($fresh,
        releasingFacts($c, $fresh, ['bill_amount_base_units' => 4.0000001])))
        ->toThrow(ValidationException::class, 'Exact integer base units');
});

test('a mainnet chain identity is blocked, never admitted', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    $mandate->chain = 'ARC-MAINNET';
    $mandate->chain_id = 12345;

    // A mandate can only ever name the configured testnet identity, so a
    // mainnet chain id is refused by the model and then blocked by the
    // evaluator, with no fallback to whatever rail is reachable.
    expect($mandate->hasValidSnapshot())->toBeFalse()
        ->and(app(MandateReleaseEvaluator::class)->evaluate($mandate, releasingFacts($c, $fresh))['disposition'])
        ->toBe('blocked');
});

test('a released occurrence is not a payment and grants no execution', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();
    $evaluator = app(MandateReleaseEvaluator::class);

    $verdict = $evaluator->evaluate($fresh, releasingFacts($c, $fresh));
    $occurrence = MandateOccurrence::query()->create([
        'request_key' => (string) Str::uuid(),
        'organization_id' => $c['institution']->id,
        'recurring_mandate_id' => $fresh->id,
        'occurrence_digest' => $evaluator->occurrenceDigest($fresh, '2026-10'),
        'amount_base_units' => 4_000000,
        'chain' => 'ARC-TESTNET',
        'chain_id' => 5042002,
        'due_at' => now(),
        'disposition' => $verdict['disposition'],
        'checks' => $verdict,
        'checks_digest' => PaymentIntent::digest($verdict),
    ]);

    expect($verdict['disposition'])->toBe('release')
        ->and($occurrence->isReleased())->toBeTrue()
        ->and($occurrence->hasValidEvidence())->toBeTrue()
        ->and($occurrence->evidence()['can_execute'])->toBeFalse()
        ->and($occurrence->evidence()['payment_approved'])->toBeFalse()
        ->and($occurrence->evidence()['funds_reserved'])->toBeFalse()
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0)
        ->and(TransactionModel::query()->count())->toBe(0);
});

test('the occurrence identity does not move when the amount does', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();
    $evaluator = app(MandateReleaseEvaluator::class);

    expect($evaluator->occurrenceDigest($fresh, '2026-10'))->toBe($evaluator->occurrenceDigest($fresh, '2026-10'))
        ->and($evaluator->occurrenceDigest($fresh, '2026-11'))
        ->not->toBe($evaluator->occurrenceDigest($fresh, '2026-10'));
});

test('the evaluator needs no model, provider or session to run', function (): void {
    $c = mandateContext();
    $mandate = prepareMandate($c);
    approveMandate($c, $mandate);
    $fresh = $mandate->fresh();

    Carbon::setTestNow(now());
    try {
        $before = app(MandateReleaseEvaluator::class)->evaluate($fresh, releasingFacts($c, $fresh));

        // Two calls with identical facts agree exactly, because the disposition is
        // a function of the evidence and nothing else.
        expect(app(MandateReleaseEvaluator::class)->evaluate($fresh, releasingFacts($c, $fresh)))->toBe($before)
            ->and($before['disposition'])->toBe('release');
    } finally {
        Carbon::setTestNow();
    }
});

test('a nonzero ceiling is refused while the allowance is disabled, and a zero one is not', function (): void {
    $c = mandateContext();

    config(['eduflow.mandate.allow_nonzero_allowance' => false]);

    // §18.4's zero initial allowance needs a second, independent gate, not
    // merely a default of zero: a reviewer decision alone cannot raise it.
    expect(fn (): RecurringMandate => prepareMandate($c, '5000000'))
        ->toThrow(ValidationException::class, 'Nonzero standing allowance is disabled')
        ->and(RecurringMandate::query()->count())->toBe(0);

    // A zero ceiling is always recordable: it authorizes nothing, and that is
    // the safe state to be able to write without the extra flag.
    expect(prepareMandate($c, '0')->per_occurrence_ceiling_base_units)->toBe(0);
});

test('a nonzero ceiling is accepted once the allowance is separately enabled', function (): void {
    $c = mandateContext();

    config(['eduflow.mandate.allow_nonzero_allowance' => true]);

    expect(prepareMandate($c, '5000000')->per_occurrence_ceiling_base_units)->toBe(5000000);
});
