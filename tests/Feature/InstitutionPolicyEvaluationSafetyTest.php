<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureCollectionBatch;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\ReviewCollectionBatch;
use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use App\Jobs\AssessInstitutionPolicy;
use App\Jobs\EvaluateInstitutionPolicy;
use App\Models\BudgetSnapshot;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\ReceiptAllocationPlan;
use App\Models\Transaction;
use App\Services\DefaultInstitutionPolicy;
use App\Services\InstitutionPolicyEvaluations;
use App\Services\InstitutionPolicyInterpreter;
use App\Services\InstitutionPolicyWorkspace;
use App\Settings\AiSettings;
use Database\Seeders\DefaultInstitutionPolicySeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @return array<string, mixed> */
function evaluationSafetyContext(string $minimumReserve = '0'): array
{
    $context = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $template = DefaultInstitutionPolicy::template();
    $template['controls']['minimum_reserve'] = $minimumReserve;
    $published = pilotPublishedDefaultPolicy($context, $template);
    Bus::fake();

    return $context + $published;
}

/** @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function evaluationSafetyRequest(array $context, BudgetSnapshot $snapshot): array
{
    $source = app(InstitutionPolicyEvaluations::class)->source($context['reviewer'], 'budget_snapshot', $snapshot->id);

    return ['source_type' => 'budget_snapshot', 'source_id' => $snapshot->id, 'source_digest' => $source['source_digest'],
        'expected_publication_digest' => $context['publication']->publication_digest, 'request_key' => (string) Str::uuid(),
        'reason' => 'Safety regression for current institution allocation.'];
}

test('only received five USDC is offered for allocation and restricted portion stays protected', function (): void {
    $context = evaluationSafetyContext();
    $batch = app(CaptureCollectionBatch::class)->handle($context['maker'], [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-fees', 'source_reference' => 'PARTIAL-5-OF-200',
        'source_document_digest' => hash('sha256', 'synthetic-reviewed-five-usdc'), 'currency' => 'USDC',
        'received_amount' => '5', 'restricted_amount' => '1', 'collected_from' => now()->subDays(2)->toIso8601String(),
        'collected_until' => now()->subDay()->toIso8601String(), 'cash_evidence_reference' => 'Synthetic cashier evidence',
        'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);
    $review = app(ReviewCollectionBatch::class)->handle($context['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'Synthetic independent receipt check', 'Five received, one restricted; remaining tuition is not cash.');
    $original = BudgetSnapshot::query()->firstOrFail();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($context['maker'], $context['budget'], [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', ...$original->snapshot['department'],
        'department' => $original->snapshot['department']['name'], 'as_of' => now()->subMinute()->toIso8601String(),
        'valid_until' => now()->addHour()->toIso8601String(), 'bill_ids' => array_column($original->snapshot['bills'], 'id'),
        'allocation' => '200', 'already_spent' => '0', 'other_budget_commitments' => '0', 'opening_funds' => '0',
        'realized_receipts' => '5', 'actual_outflows' => '0', 'restricted_cash' => '1', 'protected_reserve' => '0',
        'other_cash_commitments' => '0', 'budget_evidence' => 'Synthetic approved budget', 'cash_evidence' => 'Only received cash',
        'commitment_evidence' => 'Synthetic current commitments', 'commitments_exclude_selected_bills' => true,
        'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true, 'collection_review_ids' => [$review->id],
    ]);
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], evaluationSafetyRequest($context, $snapshot));
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    $result = $run->refresh()->result;
    expect($run->hasValidResult())->toBeTrue()->and($result['realized_receipts_minor_units'])->toBe('5000000')
        ->and($result['headroom']['cash_minor_units'])->toBe('4000000')
        ->and((int) $result['proposed_minor_units'])->toBeLessThanOrEqual(4_000000)
        ->and($batch->refresh()->received_minor_units)->toBe(5_000000)
        ->and(Transaction::query()->count())->toBe(0);
});

test('active policy reserve tightens older snapshot instead of merely displaying reserve intact', function (): void {
    $context = evaluationSafetyContext('80');
    $snapshot = BudgetSnapshot::query()->firstOrFail();
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], evaluationSafetyRequest($context, $snapshot));
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    expect($run->refresh()->hasValidResult())->toBeTrue()->and($run->result['protected_reserve_minor_units'])->toBe('80000000')
        ->and($run->result['headroom']['cash_minor_units'])->toBe('20000000')
        ->and((int) $run->result['proposed_minor_units'])->toBeLessThanOrEqual(20_000000);
});

test('policy superseded while queued cannot produce advice or call provider', function (): void {
    $context = evaluationSafetyContext();
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], evaluationSafetyRequest($context, BudgetSnapshot::query()->firstOrFail()));
    $next = app(CreateFinancePolicyVersion::class)->handle($context['maker'], 'out-of-band-new-controls', new Money(0, CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC));
    app(ActivateFinancePolicy::class)->handle($context['reviewer'], $next, $context['publication']->finance_policy_activation_id);
    $calls = 0;
    InstitutionPolicyAnalyst::fake(function (string $prompt) use (&$calls): array {
        $calls++;

        return [];
    });
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    expect($run->refresh()->state)->toBe('needs_review')->and($run->result)->toBeNull()->and($calls)->toBe(0)
        ->and(ReceiptAllocationPlan::query()->count())->toBe(0);
});

test('foreign source reads and evaluation status are blocked across institution boundary', function (): void {
    $context = evaluationSafetyContext();
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], evaluationSafetyRequest($context, BudgetSnapshot::query()->firstOrFail()));
    Organization::factory()->create();
    $this->actingAs($context['reviewer'])->getJson(route('finance.policy-evaluations.show', $run))->assertForbidden();
    $this->get(route('finance.policy-evaluations.index'))->assertForbidden();
});

test('read status suppresses damaged result and rejects executable caller payload', function (): void {
    $context = evaluationSafetyContext();
    $input = evaluationSafetyRequest($context, BudgetSnapshot::query()->firstOrFail());
    $this->actingAs($context['reviewer'])->postJson(route('finance.policy-evaluations.store'), [...$input, 'can_execute' => true])->assertUnprocessable();
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], $input);
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    DB::table('institution_policy_evaluations')->where('id', $run->id)->update(['result_digest' => str_repeat('0', 64)]);
    $this->getJson(route('finance.policy-evaluations.show', $run))->assertSuccessful()->assertJsonPath('data.result', null)
        ->assertJsonPath('data.result_valid', false)->assertJsonPath('data.can_execute', false);
});

test('malformed model citations stop classification and preserve source', function (): void {
    $context = evaluationSafetyContext();
    InstitutionPolicyAnalyst::fake(fn (string $prompt): array => ['category_id' => 'supplier-invoices', 'citations' => [['clause_id' => 'obligations', 'quote' => 'Fabricated policy sentence']],
        'explanation' => 'Unsupported answer.', 'questions' => [], 'missing_evidence' => [], 'priority' => 'high', 'needs_review' => false]);
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], evaluationSafetyRequest($context, BudgetSnapshot::query()->firstOrFail()));
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    expect($run->refresh()->state)->toBe('needs_review')->and($run->result['proposed_minor_units'])->toBe('0')
        ->and(Transaction::query()->count())->toBe(0);
});

test('non-USDC context is never silently interpreted as USDC allocation', function (): void {
    $context = evaluationSafetyContext();
    $snapshot = BudgetSnapshot::query()->firstOrFail();
    $content = $snapshot->snapshot;
    $content['currency'] = 'PHP';
    DB::table('budget_snapshots')->where('id', $snapshot->id)->update(['currency' => 'PHP', 'snapshot' => json_encode($content),
        'snapshot_digest' => PaymentIntent::digest($content)]);
    expect(fn () => app(InstitutionPolicyEvaluations::class)->source($context['reviewer'], 'budget_snapshot', $snapshot->id))->toThrow(ValidationException::class);
});

test('source change during provider response stops publication of stale transaction advice', function (): void {
    $context = evaluationSafetyContext();
    Transaction::factory()->create(['organization_id' => $context['institution']->id, 'wallet_id' => $context['wallet']->id]);
    $transaction = Transaction::query()->sole();
    $context['reviewer']->givePermissionTo(Permission::findOrCreate('View:Transaction', 'web'));
    $source = app(InstitutionPolicyEvaluations::class)->source($context['reviewer'], 'transaction', $transaction->id);
    $input = [...evaluationSafetyRequest($context, BudgetSnapshot::query()->firstOrFail()),
        'source_type' => 'transaction', 'source_id' => $transaction->id, 'source_digest' => $source['source_digest']];
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], $input);
    InstitutionPolicyAnalyst::fake(function (string $prompt) use ($context, $transaction): array {
        DB::table('transactions')->where('id', $transaction->id)->update(['status' => 'failed']);

        return ['category_id' => 'supplier-invoices', 'citations' => [['clause_id' => 'obligations', 'quote' => $context['policy']->content['clauses'][0]['text']]],
            'explanation' => 'Answer arrived after evidence changed.', 'questions' => [], 'missing_evidence' => [], 'priority' => 'normal', 'needs_review' => false];
    });
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    expect($run->refresh()->state)->toBe('needs_review')->and($run->result)->toBeNull()
        ->and($run->last_error)->toContain('evidence changed');
});

test('admin panel and finance panel expose policy entries and collection reevaluation opens exact source', function (): void {
    $context = evaluationSafetyContext();
    $context['reviewer']->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($context['reviewer']);
    $panel = Filament::getPanel('admin');
    expect(collect($panel->getNavigationItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())
        ->toContain('Institution policies', 'Policy evaluations');
    $finance = Filament::getPanel('finance');
    expect(collect($finance->getNavigationItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())
        ->toContain('Institution policies', 'Policy evaluations');
    $table = TransactionsTable::configure(
        Table::make(app(ListTransactions::class)));
    expect(array_key_exists('reevaluatePolicy', $table->getFlatActions()))->toBeTrue();
});

test('provider outage can be retried as a fresh evaluation without rewriting concluded assessments', function (): void {
    $context = evaluationSafetyContext();
    $input = evaluationSafetyRequest($context, BudgetSnapshot::query()->firstOrFail());
    $calls = 0;
    InstitutionPolicyAnalyst::fake(function (string $prompt) use (&$calls): array {
        $calls++;
        throw new RuntimeException('synthetic provider outage');
    });
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], $input);
    app()->call([(new EvaluateInstitutionPolicy($run->id)), 'handle']);
    expect($run->refresh()->state)->toBe('needs_review')->and($run->result['proposed_minor_units'])->toBe('0');
    $firstCalls = $calls;
    $next = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], [...$input, 'request_key' => (string) Str::uuid()]);
    app()->call([(new EvaluateInstitutionPolicy($next->id)), 'handle']);
    expect($next->refresh()->hasValidResult())->toBeTrue()->and($calls)->toBeGreaterThan($firstCalls)
        ->and($run->refresh()->hasValidResult())->toBeTrue()->and(Transaction::query()->count())->toBe(0);
});

test('seeded institution policy publishes through normal review and classifies five USDC as incoming tuition', function (): void {
    $context = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $context['institution']->update(['name' => 'Northstar Learning Center']);
    test()->seed(DefaultInstitutionPolicySeeder::class);
    $policy = InstitutionPolicyVersion::query()->sole();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    $clauses = array_column($policy->content['clauses'], 'text', 'id');
    $categories = array_column($policy->content['categories'], null, 'id');
    InstitutionPolicyAnalyst::fake(function (string $prompt) use ($policy, $clauses, $categories): array {
        $payload = json_decode($prompt, true);
        if ($payload['task'] === 'authoring') {
            return ['category_id' => null, 'citations' => [], 'explanation' => 'Draft explicitly separates receipt classification, allocation and spending authority.',
                'questions' => [], 'missing_evidence' => [], 'priority' => 'normal', 'needs_review' => false];
        }
        $description = $payload['untrusted_transaction_evidence']['description'] ?? '';
        $example = collect($policy->content['examples'])->firstWhere('description', $description);
        $category = $example['category_id'] ?? 'tuition-receipts';
        $clauseId = $category === 'needs_review' ? 'review' : $categories[$category]['clause_ids'][0];

        return ['category_id' => $category === 'needs_review' ? null : $category,
            'citations' => [['clause_id' => $clauseId, 'quote' => $clauses[$clauseId]]],
            'explanation' => 'Matched saved institution example; cash and approval boundaries unchanged.',
            'questions' => [], 'missing_evidence' => [], 'priority' => 'normal', 'needs_review' => $category === 'needs_review'];
    });
    foreach (['authoring', 'examples'] as $kind) {
        $assessment = InstitutionPolicyAssessment::query()->create(['organization_id' => $policy->organization_id,
            'institution_policy_version_id' => $policy->id, 'requested_by' => $context['maker']->id, 'request_key' => (string) Str::uuid(),
            'kind' => $kind, 'input' => [], 'input_digest' => PaymentIntent::digest([]), 'policy_digest' => $policy->content_digest]);
        app()->call([(new AssessInstitutionPolicy($assessment->id)), 'handle']);
    }
    $publication = app(InstitutionPolicyWorkspace::class)->publish($context['reviewer'], $policy, $policy->content_digest, null,
        'Seeded Northstar policy and all saved examples independently reviewed.');
    expect($publication->hasValidEvidence())->toBeTrue();
    $answer = app(InstitutionPolicyInterpreter::class)->interpret($policy, 'classification',
        ['description' => 'Verified partial tuition payment: 5 USDC received of 200 USDC; 195 USDC remains owed.']);
    expect($answer['category_id'])->toBe('tuition-receipts')->and($answer['can_execute'])->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0);
});
