<?php

declare(strict_types=1);

use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureCollectionBatch;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\ReviewCollectionBatch;
use App\Actions\ReviewInvoiceVersion;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DepartmentBudgetPlanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/** @return array{institution: Organization, actor: User, reviewer: User, budget: Budget, bill: InvoiceVersion} */
function collectionsContext(string $currency = 'PHP'): array
{
    config(['eduflow.institution_id' => null]);
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create(['currency' => $currency]);
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'Teaching department', 'category' => 'services',
        'allocated_amount' => '500', 'spent_amount' => '0', 'remaining_amount' => '500', 'status' => 'active']);
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Teaching services alias',
        'wallet_address' => '0x'.str_repeat('2', 40), 'status' => 'verified', 'risk_level' => 'low']);
    $invoice = Invoice::query()->create(['organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'LMS-'.Str::uuid(), 'amount' => '25', 'due_date' => now()->addDay(), 'category' => 'services', 'status' => 'pending']);
    $bill = app(CaptureInvoiceVersion::class)->handle($actor, $invoice, ['capture_key' => (string) Str::uuid(), 'source_amount' => '25',
        'source_currency' => $currency, 'source_evidence' => 'synthetic-approved-service-bill', 'business_approval_reference' => 'synthetic-business-approval',
        'department' => 'Teaching department', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => $currency === 'PHP' ? '50' : '1', 'rate_source' => 'staff-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down']);
    app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $bill->snapshot_digest, 'approve_evidence', 'Source checked.');

    return ['institution' => $institution, 'actor' => $actor, 'reviewer' => $reviewer, 'budget' => $budget, 'bill' => $bill];
}

/** @return array<string, mixed> */
function collectionInput(string $received = '100.01', string $restricted = '20.01', string $currency = 'PHP'): array
{
    return ['capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-fees', 'source_reference' => 'cashier-report-01',
        'source_document_digest' => hash('sha256', 'synthetic-fee-report-01'), 'currency' => $currency,
        'received_amount' => $received, 'restricted_amount' => $restricted,
        'collected_from' => now()->subDays(2)->toIso8601String(), 'collected_until' => now()->subDay()->toIso8601String(),
        'cash_evidence_reference' => 'synthetic-reconciled-cashier-statement', 'source_stream_disjoint' => true, 'received_not_forecast' => true];
}

/** @param array{institution: Organization, actor: User, reviewer: User, budget: Budget, bill: InvoiceVersion} $c
 * @return array<string, mixed>
 */
function collectionPlanInput(array $c, ?CollectionBatchReview $review = null): array
{
    return ['capture_key' => (string) Str::uuid(), 'currency' => $c['bill']->source_currency, 'department' => 'Teaching department',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => [$c['bill']->id], 'allocation' => '200', 'already_spent' => '0', 'other_budget_commitments' => '0',
        'opening_funds' => '0', 'realized_receipts' => $review instanceof CollectionBatchReview ? '100.01' : '0', 'actual_outflows' => '5',
        'restricted_cash' => $review instanceof CollectionBatchReview ? '20.01' : '0', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
        'budget_evidence' => 'synthetic-approved-department-allocation', 'cash_evidence' => 'synthetic-opening-reconciliation',
        'commitment_evidence' => 'synthetic-obligation-register', 'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true,
        'opening_funds_exclude_collections' => true, 'collection_review_ids' => $review instanceof CollectionBatchReview ? [$review->id] : []];
}

/** @param array{institution: Organization, actor: User, reviewer: User, budget: Budget, bill: InvoiceVersion} $c */
function approveCollection(array $c, CollectionBatch $batch, string $decision = 'approve_receipts'): CollectionBatchReview
{
    return app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest, $decision,
        'synthetic-independent-cashier-check', 'Received funds, restrictions and source coverage checked.');
}

test('aggregate collection capture and review preserve exact money and require no students wallets or gateways', function (): void {
    $c = collectionsContext();
    $data = collectionInput();
    $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], $data);
    expect($batch->hasValidSnapshot())->toBeTrue()->and($batch->received_minor_units)->toBe(10001)->and($batch->restricted_minor_units)->toBe(2001)
        ->and($batch->evidence()['receipts_reviewed'])->toBeFalse()->and($batch->evidence()['unrestricted_minor_units'])->toBe('0');
    $data['capture_key'] = strtoupper($data['capture_key']);
    expect(app(CaptureCollectionBatch::class)->handle($c['actor'], $data)->id)->toBe($batch->id);
    $review = approveCollection($c, $batch);
    expect(approveCollection($c, $batch)->id)->toBe($review->id)->and($batch->evidence()['receipts_reviewed'])->toBeTrue()
        ->and($batch->evidence()['unrestricted_minor_units'])->toBe('8000')->and($batch->evidence()['bank_balance_verified'])->toBeFalse()
        ->and($batch->evidence()['arc_funding_verified'])->toBeFalse()->and($batch->evidence()['accounts_changed'])->toBeFalse()
        ->and(Activity::query()->where('event', 'collection_batch_captured')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'collection_batch_reviewed')->count())->toBe(1)
        ->and(Student::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
});

test('reviewed fees fund local departmental suggestions but never become Arc cash or bill settlement', function (): void {
    $c = collectionsContext();
    $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput());
    $review = approveCollection($c, $batch);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], collectionPlanInput($c, $review));
    $plan = app(DepartmentBudgetPlanner::class)->handle($c['actor'], $snapshot);
    expect($snapshot->snapshot['schema_version'])->toBe(3)->and($snapshot->collectionReviews()->count())->toBe(1)
        ->and($plan['headroom']['cash_minor_units'])->toBe('6500')->and($plan['headroom']['budget_minor_units'])->toBe('20000')
        ->and($plan['bills'][0]['decision'])->toBe('propose_human_review')->and($plan['remaining_cash_minor_units'])->toBe('4000')
        ->and($plan['collection_evidence']['mode'])->toBe('reviewed_batches')->and($plan['collection_evidence']['review_ids'])->toBe([$review->id])
        ->and($plan['settlement_funding_verified'])->toBeFalse()->and($plan['can_execute'])->toBeFalse()
        ->and($c['budget']->fresh()->remaining_amount)->toBe(500.0)->and(Transaction::query()->count())->toBe(0)
        ->and(Invoice::query()->first()->status)->toBe('pending');
});

test('unreviewed rejected and held receipts cannot supply planning cash', function (string $case): void {
    $c = collectionsContext();
    $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput());
    $review = $case === 'pending' ? null : approveCollection($c, $batch, $case);
    $data = collectionPlanInput($c, $review);
    if (! $review instanceof CollectionBatchReview) {
        $data['realized_receipts'] = '100.01';
    }
    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $data))->toThrow(ValidationException::class)
        ->and($batch->evidence()['unrestricted_minor_units'])->toBe('0')->and(BudgetSnapshot::query()->count())->toBe(0);
})->with(['pending', 'reject', 'hold']);

test('collection capture rejects fractional fiat floats negatives forecast future and missing attestations', function (string $case): void {
    $c = collectionsContext();
    $data = collectionInput();
    match ($case) {
        'precision' => $data['received_amount'] = '1.001', 'float' => $data['received_amount'] = 1.01,
        'negative' => $data['received_amount'] = '-1', 'zero' => $data['received_amount'] = '0',
        'restriction' => $data['restricted_amount'] = '101', 'forecast' => $data['received_amount'] = 'expected fees',
        'future' => $data['collected_until'] = now()->addHour()->toIso8601String(),
        'interval' => $data['collected_from'] = $data['collected_until'], 'stream' => $data['source_stream'] = 'Cashier with spaces',
        'document' => $data['source_document_digest'] = 'not-a-digest', 'disjoint' => $data['source_stream_disjoint'] = false,
        'received' => $data['received_not_forecast'] = false,
        default => throw new LogicException('Unknown collection input case.'),
    };
    expect(fn () => app(CaptureCollectionBatch::class)->handle($c['actor'], $data))->toThrow(ValidationException::class)
        ->and(CollectionBatch::query()->count())->toBe(0);
})->with(['precision', 'float', 'negative', 'zero', 'restriction', 'forecast', 'future', 'interval', 'stream', 'document', 'disjoint', 'received']);

test('duplicate source document reference or interval cannot be counted again under a new key', function (string $case): void {
    $c = collectionsContext();
    $data = collectionInput();
    app(CaptureCollectionBatch::class)->handle($c['actor'], $data);
    $data['capture_key'] = (string) Str::uuid();
    if ($case !== 'document') {
        $data['source_document_digest'] = hash('sha256', 'different-document');
    }
    if ($case === 'document') {
        $data['source_stream'] = 'other-stream';
        $data['source_reference'] = 'other-reference';
    } elseif ($case === 'reference') {
        $data['collected_from'] = now()->subDays(4)->toIso8601String();
        $data['collected_until'] = now()->subDays(3)->toIso8601String();
    } else {
        $data['source_reference'] = 'overlapping-report';
        $data['collected_from'] = now()->subHours(36)->toIso8601String();
        $data['collected_until'] = now()->subHours(12)->toIso8601String();
    }
    expect(fn () => app(CaptureCollectionBatch::class)->handle($c['actor'], $data))->toThrow(ValidationException::class)
        ->and(CollectionBatch::query()->count())->toBe(1);
})->with(['document', 'reference', 'interval']);

test('adjacent source intervals allow distinct nonoverlapping batches and planning totals are exact', function (): void {
    $c = collectionsContext('USDC');
    $firstData = collectionInput('0.000001', '0', 'USDC');
    $first = app(CaptureCollectionBatch::class)->handle($c['actor'], $firstData);
    $secondData = collectionInput('0.000002', '0', 'USDC');
    $secondData['source_reference'] = 'cashier-report-02';
    $secondData['source_document_digest'] = hash('sha256', 'synthetic-fee-report-02');
    $secondData['collected_from'] = $firstData['collected_until'];
    $secondData['collected_until'] = now()->subHours(12)->toIso8601String();
    $second = app(CaptureCollectionBatch::class)->handle($c['actor'], $secondData);
    $firstReview = approveCollection($c, $first);
    $secondReview = approveCollection($c, $second);
    $data = collectionPlanInput($c);
    $data['realized_receipts'] = '0.000003';
    $data['collection_review_ids'] = [$secondReview->id, $firstReview->id];
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $data);
    expect($snapshot->snapshot['amounts']['realized_receipts'])->toBe('3')
        ->and(array_column($snapshot->snapshot['collections'], 'review_id'))->toBe([$firstReview->id, $secondReview->id]);
});

test('receipt amount restriction currency time and opening exclusions must match reviewed source', function (string $case): void {
    $c = collectionsContext();
    $review = approveCollection($c, app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput()));
    $data = collectionPlanInput($c, $review);
    match ($case) {
        'amount' => $data['realized_receipts'] = '100.02', 'restriction' => $data['restricted_cash'] = '20',
        'duplicate' => $data['collection_review_ids'] = [$review->id, $review->id], 'currency' => $data['currency'] = 'USD',
        'time' => $data['as_of'] = now()->subDays(3)->toIso8601String(), 'opening-overlap' => $data['opening_funds_exclude_collections'] = false,
        default => throw new LogicException('Unknown binding case.'),
    };
    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $data))->toThrow(ValidationException::class)
        ->and(BudgetSnapshot::query()->count())->toBe(0);
})->with(['amount', 'restriction', 'duplicate', 'currency', 'time', 'opening-overlap']);

test('self review is forbidden even for super admin and conflicting evidence reviews cannot change prior decision', function (): void {
    $c = collectionsContext();
    $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput());
    $c['actor']->assignRole(Role::findOrCreate('super_admin', 'web'));
    expect(fn () => app(ReviewCollectionBatch::class)->handle($c['actor'], $batch, $batch->snapshot_digest, 'approve_receipts', 'Self review.', 'Approve.'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, str_repeat('0', 64), 'approve_receipts', 'Wrong digest.', 'Approve.'))->toThrow(ValidationException::class);
    approveCollection($c, $batch, 'hold');
    expect(fn (): CollectionBatchReview => approveCollection($c, $batch))->toThrow(ValidationException::class)
        ->and(CollectionBatchReview::query()->count())->toBe(1);
});

test('collection or review tampering and missing bindings block whole plan rather than inventing available cash', function (string $case): void {
    $c = collectionsContext();
    $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput());
    $review = approveCollection($c, $batch);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], collectionPlanInput($c, $review));
    if ($case === 'batch') {
        DB::table((new CollectionBatch)->getTable())->where('id', $batch->id)->update(['received_minor_units' => 9000]);
    } elseif ($case === 'review') {
        DB::table((new CollectionBatchReview)->getTable())->where('id', $review->id)->update(['reason' => 'Changed review.']);
    } else {
        $snapshot->collectionReviews()->detach($review->id);
    }
    expect(fn () => app(DepartmentBudgetPlanner::class)->handle($c['actor'], $snapshot))->toThrow(ValidationException::class);
})->with(['batch', 'review', 'binding']);

test('collection evidence is immutable restrictive keys preserve budget bindings and rollback refuses evidence', function (): void {
    $c = collectionsContext();
    $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput());
    $review = approveCollection($c, $batch);
    app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], collectionPlanInput($c, $review));
    expect(fn () => $batch->update(['received_minor_units' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $batch->delete())->toThrow(LogicException::class)->and(fn () => $review->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table((new CollectionBatchReview)->getTable())->where('id', $review->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table((new CollectionBatch)->getTable())->where('id', $batch->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table((new CollectionBatch)->getTable())->where('id', $batch->id)->update(['received_minor_units' => 1.5]))->toThrow(QueryException::class);
    $migration = require database_path('migrations/2026_10_09_014954_create_collection_batches_and_budget_bindings.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists');
});

test('empty collection migration rollback preserves earlier budgets and can reinstall', function (): void {
    $migration = require database_path('migrations/2026_10_09_014954_create_collection_batches_and_budget_bindings.php');
    $migration->down();
    $migration->up();
    $c = collectionsContext();
    expect(app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput())->hasValidSnapshot())->toBeTrue();
});

test('legacy budget snapshots remain explicit unverified attestations rather than inferred reviewed collections', function (): void {
    $c = collectionsContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], collectionPlanInput($c));
    $legacy = $snapshot->snapshot;
    $legacy['schema_version'] = 1;
    unset($legacy['collections'], $legacy['opening_funds_exclude_collections']);
    $legacy['amounts']['realized_receipts'] = '10001';
    DB::table((new BudgetSnapshot)->getTable())->where('id', $snapshot->id)->update(['snapshot' => json_encode($legacy, JSON_THROW_ON_ERROR), 'snapshot_digest' => PaymentIntent::digest($legacy)]);
    $plan = app(DepartmentBudgetPlanner::class)->handle($c['actor'], $snapshot);
    expect($plan['collection_evidence']['mode'])->toBe('legacy_staff_attestation')->and($plan['collection_evidence']['review_ids'])->toBe([])
        ->and($plan['settlement_funding_verified'])->toBeFalse()->and($plan['can_execute'])->toBeFalse();
});

test('collection capture retry refuses changed money actor or source identity', function (string $case): void {
    $c = collectionsContext();
    $data = collectionInput();
    app(CaptureCollectionBatch::class)->handle($c['actor'], $data);
    if ($case === 'amount') {
        $data['received_amount'] = '101.01';
    } elseif ($case === 'source') {
        $data['source_reference'] = 'changed-reference';
    } else {
        $c['actor'] = $c['reviewer'];
    }
    expect(fn () => app(CaptureCollectionBatch::class)->handle($c['actor'], $data))->toThrow(ValidationException::class)
        ->and(CollectionBatch::query()->count())->toBe(1);
})->with(['amount', 'source', 'actor']);

test('source reference canonicalization prevents a case or whitespace duplicate', function (): void {
    $c = collectionsContext();
    $data = collectionInput();
    $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], $data);
    $data['capture_key'] = (string) Str::uuid();
    $data['source_reference'] = '  CASHIER-REPORT-01  ';
    $data['source_document_digest'] = hash('sha256', 'different-report-bytes');
    $data['collected_from'] = now()->subDays(4)->toIso8601String();
    $data['collected_until'] = now()->subDays(3)->toIso8601String();
    expect($batch->source_reference)->toBe('cashier-report-01')
        ->and(fn () => app(CaptureCollectionBatch::class)->handle($c['actor'], $data))->toThrow(ValidationException::class);
});

test('collection budget bindings remain valid when JSON object order changes', function (): void {
    $c = collectionsContext();
    $review = approveCollection($c, app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput()));
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], collectionPlanInput($c, $review));
    $content = $snapshot->snapshot;
    $content['collections'][0] = array_reverse($content['collections'][0], true);
    $content = array_reverse($content, true);
    DB::table((new BudgetSnapshot)->getTable())->where('id', $snapshot->id)->update(['snapshot' => json_encode($content, JSON_THROW_ON_ERROR)]);
    expect(app(DepartmentBudgetPlanner::class)->handle($c['actor'], $snapshot)['collection_evidence']['review_ids'])->toBe([$review->id]);
});

test('collection source decision and budget binding commit together on persistence failure', function (): void {
    $c = collectionsContext();
    $review = approveCollection($c, app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput()));
    DB::unprepared("CREATE TRIGGER reject_test_collection_binding BEFORE INSERT ON budget_snapshot_collection_review
        BEGIN SELECT RAISE(ABORT, 'Injected collection binding write failure'); END");
    try {
        expect(fn () => app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], collectionPlanInput($c, $review)))->toThrow(QueryException::class)
            ->and(BudgetSnapshot::query()->count())->toBe(0)
            ->and(Activity::query()->where('event', 'budget_snapshot_captured')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER reject_test_collection_binding');
    }
});

test('collection HTTP writes throttle replay without multiplying receipts', function (): void {
    $c = collectionsContext();
    actingAs($c['actor']);
    $data = collectionInput();
    for ($attempt = 0; $attempt < 10; $attempt++) {
        postJson(route('finance.collection-batches.store'), $data)->assertSuccessful();
    }
    postJson(route('finance.collection-batches.store'), $data)->assertTooManyRequests();
    expect(CollectionBatch::query()->count())->toBe(1)->and(Transaction::query()->count())->toBe(0);
});

test('collection intervals retain exact UTC identity when institution runs in a different timezone', function (): void {
    $originalTimezone = date_default_timezone_get();
    $originalConfig = config('app.timezone');
    try {
        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');
        $c = collectionsContext();
        $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput());
        expect($batch->fresh()->hasValidSnapshot())->toBeTrue();
        expect(approveCollection($c, $batch->fresh())->id)->toBeGreaterThan(0);
    } finally {
        config(['app.timezone' => $originalConfig]);
        date_default_timezone_set($originalTimezone);
    }
});

test('collection HTTP routes require authenticated independent identities and refuse receipt or actor injection', function (): void {
    $c = collectionsContext();
    $payload = collectionInput();
    postJson(route('finance.collection-batches.store'), $payload)->assertUnauthorized();
    actingAs($c['actor']);
    postJson(route('finance.collection-batches.store'), $payload + ['prepared_by' => $c['reviewer']->id])->assertUnprocessable();
    $response = postJson(route('finance.collection-batches.store'), $payload)->assertSuccessful()->assertJsonPath('data.receipts_reviewed', false);
    /** @var CollectionBatch $batch */
    $batch = CollectionBatch::query()->findOrFail($response->json('data.id'));
    $review = ['expected_digest' => $batch->snapshot_digest, 'decision' => 'approve_receipts',
        'verification_reference' => 'Independent cashier report.', 'reason' => 'Receipts checked.', 'received_and_restrictions_verified' => true];
    postJson(route('finance.collection-batches.review', $batch), $review)->assertForbidden();
    actingAs($c['reviewer']);
    postJson(route('finance.collection-batches.review', $batch), $review + ['received_amount' => '999999'])->assertUnprocessable();
    postJson(route('finance.collection-batches.review', $batch), $review)->assertSuccessful()->assertJsonPath('data.can_execute', false);
    getJson(route('finance.collection-batches.show', $batch))->assertSuccessful()->assertJsonPath('data.receipts_reviewed', true)
        ->assertJsonPath('data.arc_funding_verified', false);
});

test('unverified ordinary and foreign installation staff cannot read capture or review collections', function (string $case): void {
    $c = collectionsContext();
    $batch = app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput());
    if ($case === 'ordinary') {
        /** @var User $ordinary */
        $ordinary = User::factory()->create();
        $c['actor'] = $ordinary;
    } elseif ($case === 'unverified') {
        $c['actor']->forceFill(['email_verified_at' => null])->save();
    } else {
        /** @var Organization $other */
        $other = Organization::factory()->create();
        config(['eduflow.institution_id' => $other->id]);
    }
    actingAs($c['actor']);
    getJson(route('finance.collection-batches.show', $batch))->assertForbidden();
    expect(fn () => app(CaptureCollectionBatch::class)->handle($c['actor'], collectionInput()))->toThrow(AuthorizationException::class);
})->with(['ordinary', 'unverified', 'foreign']);
