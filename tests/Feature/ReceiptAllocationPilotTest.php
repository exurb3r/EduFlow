<?php

declare(strict_types=1);

use App\Actions\ProposeReceiptAllocation;
use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\Jobs\AssessInstitutionPolicy;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\FinancePolicyActivation;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\ReceiptAllocationPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\DefaultInstitutionPolicy;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyInterpreter;
use App\Services\InstitutionPolicyWorkspace;
use App\Settings\AiSettings;
use Database\Seeders\DefaultInstitutionPolicySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Publish the default institution policy an installation is handed, so a new
 * school can exercise allocation immediately without anyone inventing wording.
 *
 * @return array<string, mixed>
 */
function pilotPublishedDefaultPolicy(array $c): array
{
    $template = DefaultInstitutionPolicy::template();
    $template['request_key'] = (string) Str::uuid();
    $workspace = app(InstitutionPolicyWorkspace::class);
    $policy = $workspace->save($c['maker'], $template);

    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;

    // Each category cites its own source clause verbatim, because the
    // interpreter refuses a citation that is not a real excerpt of saved text.
    $clauses = [];
    foreach ($policy->content['clauses'] as $clause) {
        $clauses[$clause['id']] = $clause['text'];
    }
    $clausesByCategory = [];
    foreach ($policy->content['categories'] as $category) {
        $clausesByCategory[$category['id']] = $category['clause_ids'][0];
    }
    $answer = static fn (string $category): array => [
        'category_id' => $category,
        'citations' => [['clause_id' => $clausesByCategory[$category], 'quote' => $clauses[$clausesByCategory[$category]]]],
        'explanation' => 'Matches the institution definition for '.$category.'.',
        'questions' => [], 'missing_evidence' => [], 'priority' => 'high', 'needs_review' => false,
    ];

    // The fake reads the prompt rather than replaying a fixed queue, so it
    // answers the target actually being classified. A positional queue would
    // silently assign council money to a facilities line the moment the
    // enumeration order changed, and the test would pass for the wrong reason.
    $keywordCategory = [
        'council' => 'student-council',
        'club' => 'student-clubs',
        'facilit' => 'department-facilities',
        'robotics' => 'student-clubs',
        'bench' => 'department-facilities',
    ];
    InstitutionPolicyAnalyst::fake(static function (string $prompt) use ($answer, $keywordCategory): array {
        $decoded = json_decode($prompt, true);
        // Match on the transaction evidence only. The prompt also carries the
        // whole policy, whose own wording mentions every category, so scanning
        // the full text would classify everything as supplier invoices.
        $evidence = is_array($decoded)
            ? strtolower((string) ($decoded['untrusted_transaction_evidence']['description'] ?? ''))
            : strtolower($prompt);
        foreach ($keywordCategory as $needle => $category) {
            if (str_contains($evidence, $needle)) {
                return $answer($category);
            }
        }

        return $answer('supplier-invoices');
    });

    foreach (['authoring', 'examples'] as $kind) {
        $assessment = InstitutionPolicyAssessment::query()->create(['organization_id' => $policy->organization_id,
            'institution_policy_version_id' => $policy->id, 'requested_by' => $c['maker']->id, 'request_key' => (string) Str::uuid(),
            'kind' => $kind, 'input' => [], 'input_digest' => PaymentIntent::digest([]), 'policy_digest' => $policy->content_digest]);
        (new AssessInstitutionPolicy($assessment->id))->handle(app(InstitutionPolicyInterpreter::class),
            app(InstallationInstitution::class), app(AiProviderResolver::class));
    }
    $publication = $workspace->publish($c['reviewer'], $policy, $policy->content_digest, null, 'Default institution policy reviewed and published.');

    return ['policy' => $policy, 'publication' => $publication];
}

test('default policy applies to a new institution as an editable draft that nobody publishes', function (): void {
    config(['eduflow.institution_id' => null]);
    Organization::factory()->create();
    $officer = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));

    $this->seed(DefaultInstitutionPolicySeeder::class);

    expect(InstitutionPolicyVersion::query()->count())->toBe(1);
    $version = InstitutionPolicyVersion::query()->sole();
    expect($version->title)->toBe('Institution finance and transaction policy')
        ->and(array_column($version->content['categories'], 'id'))->toBe(['supplier-invoices', 'student-council', 'student-clubs', 'department-facilities'])
        ->and($version->content['controls'])->toBe(['minimum_reserve' => '0', 'max_auto_payment' => '0', 'max_daily_disbursement' => '0', 'max_fee' => '0'])
        ->and(InstitutionPolicyPublication::query()->count())->toBe(0)
        ->and(FinancePolicyActivation::query()->count())->toBe(0)
        ->and($version->created_by)->toBe($officer->id)
        ->and($version->hasValidContent())->toBeTrue();

    // Running again must not overwrite an institution that already has a policy.
    $this->seed(DefaultInstitutionPolicySeeder::class);
    expect(InstitutionPolicyVersion::query()->count())->toBe(1);
});

test('policy classification routes a receipt to supplier invoices before council clubs and facilities', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    pilotPublishedDefaultPolicy($c);
    // The snapshot's own budget stays active: allocation needs live headroom,
    // and an exhausted base would make the proposal meaningless rather than
    // stricter. These three lines are the ones policy routes by category.
    foreach ([['Student Council', 'student_council'], ['Robotics Club', 'student_clubs'], ['Science Facilities', 'department_facilities']] as [$name, $category]) {
        Budget::query()->create(['organization_id' => $c['institution']->id, 'name' => $name, 'category' => $category,
            'allocated_amount' => 1000, 'spent_amount' => 0, 'remaining_amount' => 1000, 'status' => 'active']);
    }
    $snapshot = BudgetSnapshot::query()->firstOrFail();
    // The fixture already holds draft intents, so the assertion is that
    // proposing an allocation adds nothing to either lane.
    $intentsBefore = PaymentIntent::query()->count();
    $reservationsBefore = PaymentReservation::query()->count();
    $plan = app(ProposeReceiptAllocation::class)->handle($c['maker'], $snapshot, (string) Str::uuid());

    expect($plan->hasValidPlan())->toBeTrue()
        ->and($plan->plan['can_execute'])->toBeFalse()->and($plan->plan['funds_reserved'])->toBeFalse()
        ->and($plan->plan['payment_approved'])->toBeFalse();
    $order = array_column(array_column($plan->plan['lines'], 'category_id'), null);
    // Staff category order is the only thing that ranks lines: every approved
    // invoice leads, then council, clubs and facilities in the order the
    // default policy lists them. Assert the ranking, not an exact count.
    $ranked = array_values(array_filter($order, static fn (?string $c): bool => $c !== null));
    expect($ranked[0])->toBe('supplier-invoices')
        ->and(array_slice($ranked, count(array_filter($ranked, static fn (string $c): bool => $c === 'supplier-invoices'))))
        ->toBe(['student-council', 'student-clubs', 'department-facilities'])
        ->and($plan->plan['lines'][0]['decision'])->toBe('propose_human_review')
        ->and($plan->plan['proposals_awaiting_human_review'])->toBeGreaterThan(0);

    // Every proposed figure is bounded by captured headroom and no money moved.
    $headroom = $plan->plan['headroom'];
    foreach ($plan->plan['lines'] as $line) {
        expect($line['can_execute'])->toBeFalse()
            ->and((int) $line['proposed_minor_units'])->toBeLessThanOrEqual((int) $headroom['budget_minor_units'])
            ->and((int) $line['proposed_minor_units'])->toBeLessThanOrEqual((int) $headroom['cash_minor_units']);
    }
    expect($plan->proposed_minor_units)->toBeLessThanOrEqual((int) $headroom['cash_minor_units'])
        ->and(Transaction::query()->count())->toBe(0)
        ->and(PaymentIntent::query()->count())->toBe($intentsBefore)
        ->and(PaymentReservation::query()->count())->toBe($reservationsBefore);
});

test('approved invoices outrank budget lines inside one policy category', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    pilotPublishedDefaultPolicy($c);
    // A budget line classified into the same category as the invoices must
    // not consume the headroom and starve them. Comparing target type strings
    // put "budget_line" first alphabetically, which is how this regressed.
    Budget::query()->create(['organization_id' => $c['institution']->id, 'name' => 'Overhead', 'category' => 'hosting',
        'allocated_amount' => 1000, 'spent_amount' => 0, 'remaining_amount' => 1000, 'status' => 'active']);
    $plan = app(ProposeReceiptAllocation::class)->handle($c['maker'], BudgetSnapshot::query()->firstOrFail(), (string) Str::uuid());

    $lines = $plan->plan['lines'];
    $invoiceIndexes = array_keys(array_filter($lines, static fn (array $l): bool => $l['target_type'] === 'supplier_invoice'));
    $budgetIndexes = array_keys(array_filter($lines, static fn (array $l): bool => $l['target_type'] === 'budget_line'));
    expect($invoiceIndexes)->not->toBeEmpty()->and($budgetIndexes)->not->toBeEmpty()
        ->and(max($invoiceIndexes))->toBeLessThan(min($budgetIndexes));

    // Every funded line is a real obligation, never the open-ended budget line.
    foreach ($invoiceIndexes as $index) {
        expect($lines[$index]['decision'])->toBe('propose_human_review');
    }
    expect($plan->proposed_minor_units)->toBe(array_sum(array_map(static fn (int $i): int => (int) $lines[$i]['proposed_minor_units'], $invoiceIndexes)))
        ->and($plan->plan['funds_reserved'])->toBeFalse();
});

test('unclassifiable receipt yields holds rather than a forced category or invented amount', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    pilotPublishedDefaultPolicy($c);
    $snapshot = BudgetSnapshot::query()->firstOrFail();
    // Provider unavailable after publication: classification degrades to review.
    app(AiSettings::class)->advisory_enabled = false;
    $plan = app(ProposeReceiptAllocation::class)->handle($c['maker'], $snapshot, (string) Str::uuid());

    expect($plan->proposed_minor_units)->toBe(0)
        ->and($plan->plan['proposals_awaiting_human_review'])->toBe(0)
        ->and($plan->unallocated_minor_units)->toBe((int) $plan->plan['headroom']['cash_minor_units'])
        ->and($plan->hasValidPlan())->toBeTrue();
    foreach ($plan->plan['lines'] as $line) {
        expect($line['decision'])->toBe('hold')->and($line['proposed_minor_units'])->toBe('0');
    }
    expect(InstitutionPolicyAssessment::query()->where('state', 'needs_review')->exists())->toBeTrue()
        ->and(Transaction::query()->count())->toBe(0);
});

test('allocation proposal refuses without a published policy and is immutable once stored', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    $snapshot = BudgetSnapshot::query()->firstOrFail();
    expect(fn () => app(ProposeReceiptAllocation::class)->handle($c['maker'], $snapshot, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    config(['queue.connections.database.retry_after' => 360]);
    pilotPublishedDefaultPolicy($c);
    $key = (string) Str::uuid();
    $plan = app(ProposeReceiptAllocation::class)->handle($c['maker'], $snapshot, $key);
    // Same identity replays the stored plan rather than re-proposing.
    expect(app(ProposeReceiptAllocation::class)->handle($c['maker'], $snapshot, $key)->plan_digest)->toBe($plan->plan_digest)
        ->and(fn () => $plan->update(['proposed_minor_units' => 999_999_999]))->toThrow(LogicException::class)
        ->and(fn () => $plan->delete())->toThrow(LogicException::class);
});

test('students and unverified staff cannot propose an allocation plan', function (): void {
    $c = paymentAuthorizationContext(withFunding: false);
    $student = User::factory()->create()->assignRole(Role::findOrCreate('student', 'web'));
    $unverified = User::factory()->create(['email_verified_at' => null])
        ->assignRole(Role::findOrCreate('finance_officer', 'web'));

    foreach ([$student, $unverified] as $outsider) {
        expect(fn () => app(ProposeReceiptAllocation::class)->handle($outsider, BudgetSnapshot::query()->firstOrFail(), (string) Str::uuid()))
            ->toThrow(AuthorizationException::class);
    }
    expect(ReceiptAllocationPlan::query()->count())->toBe(0);
});
