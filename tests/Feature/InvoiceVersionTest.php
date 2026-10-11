<?php

declare(strict_types=1);

use App\Actions\CaptureInvoiceVersion;
use App\Actions\ReviewInvoiceVersion;
use App\Agents\EduFlowAgent;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Enums\TransactionType;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\CircleWalletService;
use App\Services\CurrencyValuationCalculator;
use App\Services\FinancialPolicyEngine;
use App\Services\InstallationInstitution;
use App\Services\VendorPaymentSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/** @return array{institution: Organization, actor: User, reviewer: User, invoice: Invoice, budget: Budget, wallet: Wallet} */
function invoiceVersionContext(): array
{
    config(['eduflow.institution_id' => null]);
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create(['currency' => 'PHP']);
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'IT department', 'category' => 'software',
        'allocated_amount' => '1000', 'spent_amount' => '50', 'remaining_amount' => '950', 'status' => 'active']);
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Supplier alias',
        'wallet_address' => '0x'.str_repeat('2', 40), 'status' => 'verified', 'risk_level' => 'low']);
    $invoice = Invoice::query()->create(['organization_id' => $institution->id, 'budget_id' => $budget->id,
        'vendor_id' => $vendor->id, 'reference' => 'BILL-'.Str::uuid(), 'amount' => '57.51', 'due_date' => now()->addDay(), 'category' => 'software', 'status' => 'pending']);
    $wallet = Wallet::query()->create(['organization_id' => $institution->id, 'provider' => 'circle', 'network' => 'arc',
        'address' => '0x'.str_repeat('1', 40), 'balance' => '500', 'status' => 'active']);

    return ['institution' => $institution, 'actor' => $actor, 'reviewer' => $reviewer, 'invoice' => $invoice, 'budget' => $budget, 'wallet' => $wallet];
}

/** @return array{capture_key: string, source_amount: string, source_currency: string, source_evidence: string, business_approval_reference: string, department: string, period_start: string, period_end: string, source_per_usdc: string, rate_source: string, rate_observed_at: string, rounding: string} */
function invoiceVersionInput(): array
{
    return ['capture_key' => (string) Str::uuid(), 'source_amount' => '57.51', 'source_currency' => 'PHP',
        'source_evidence' => 'approved-extract-row-01', 'business_approval_reference' => 'department-approval-01',
        'department' => 'IT department', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '57.5000', 'rate_source' => 'staff-reference-01', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'half_up'];
}

test('exact currency valuation preserves local amounts and explicit rounding without indicative fallbacks', function (string $rounding, string $expected): void {
    $mapped = (new CurrencyValuationCalculator)->calculate('0.01', CurrencyCode::PHP, '3', $rounding);
    expect($mapped['source_minor_units'])->toBe('1')->and($mapped['valuation_base_units'])->toBe($expected);
})->with([['down', '3333'], ['half_up', '3333'], ['up', '3334']]);

test('currency valuation handles precise rates ties and big multiplication without float arithmetic', function (): void {
    $calculator = new CurrencyValuationCalculator;
    expect($calculator->calculate('0.01', CurrencyCode::PHP, '128', 'half_up')['valuation_base_units'])->toBe('78')
        ->and($calculator->calculate('0.01', CurrencyCode::PHP, '32', 'half_up')['valuation_base_units'])->toBe('313')
        ->and($calculator->calculate('57.51', CurrencyCode::PHP, '57.500000000001', 'down')['valuation_base_units'])->toBe('1000173')
        ->and($calculator->calculate('90000000000000.00', CurrencyCode::PHP, '1000', 'down')['valuation_base_units'])->toBe('90000000000000000');
});

test('invalid valuation input fails closed', function (string $amount, string $currency, string $rate, string $rounding): void {
    expect(fn (): array => (new CurrencyValuationCalculator)->calculate($amount, CurrencyCode::from($currency), $rate, $rounding))->toThrow(Exception::class);
})->with([
    ['-1', 'PHP', '57.5', 'down'], ['1.001', 'PHP', '57.5', 'down'], ['1e3', 'PHP', '57.5', 'down'],
    ['1', 'USDC', '2', 'down'], ['1', 'PHP', '0', 'down'], ['1', 'PHP', '1e3', 'down'],
    ['1', 'PHP', '57.5', 'unknown'], ['0.01', 'PHP', '999999999999999999', 'down'],
    ['92233720368547758.07', 'PHP', '0.000000000001', 'up'],
]);

test('ordinary invoice version supports six decimal USDC identity valuation without demo network state', function (): void {
    $context = invoiceVersionContext();
    $data = invoiceVersionInput();
    $data['source_currency'] = 'USDC';
    $data['source_amount'] = '25.000001';
    $data['source_per_usdc'] = '1';
    $version = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], $data);
    expect($version->hasValidSnapshot())->toBeTrue()->and($version->source_minor_units)->toBe(25_000001)
        ->and($version->valuation_base_units)->toBe(25_000001)->and($version->evidence())->not->toHaveKeys(['chain', 'chain_id', 'mirror_state'])
        ->and(Schema::hasTable('shadow_bills'))->toBeFalse()->and(Schema::hasTable('shadow_bill_reviews'))->toBeFalse()
        ->and(Schema::hasColumn('invoice_versions', 'chain'))->toBeFalse();
});

test('capture preserves exact explicit local evidence without trusting float invoice storage or touching real finances', function (): void {
    $context = invoiceVersionContext();
    $invoiceBefore = $context['invoice']->fresh()->getRawOriginal();
    $budgetBefore = $context['budget']->fresh()->getRawOriginal();
    $walletBefore = $context['wallet']->fresh()->getRawOriginal();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    expect($bill->hasValidSnapshot())->toBeTrue()->and($bill->source_minor_units)->toBe(5751)
        ->and($bill->valuation_base_units)->toBe(1_000174)->and($bill->snapshot['mapping']['source_per_usdc'])->toBe('57.5')
        ->and($bill->evidence()['can_execute'])->toBeFalse()->and($bill->evidence()['payment_state_changed'])->toBeFalse()
        ->and($context['invoice']->fresh()->getRawOriginal())->toBe($invoiceBefore)
        ->and($context['budget']->fresh()->getRawOriginal())->toBe($budgetBefore)
        ->and($context['wallet']->fresh()->getRawOriginal())->toBe($walletBefore)
        ->and(Student::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0)
        ->and(PaymentIntent::query()->count())->toBe(0);
});

test('capture retry returns original evidence once and refuses changed identity source rate or document', function (string $change): void {
    $context = invoiceVersionContext();
    $data = invoiceVersionInput();
    $capture = app(CaptureInvoiceVersion::class);
    $bill = $capture->handle($context['actor'], $context['invoice'], $data);
    expect($capture->handle($context['actor'], $context['invoice'], $data)->id)->toBe($bill->id)
        ->and(Activity::query()->where('event', 'invoice_version_captured')->count())->toBe(1);
    match ($change) {
        'key' => $data['capture_key'] = (string) Str::uuid(),
        'amount' => $data['source_amount'] = '58',
        'rate' => $data['source_per_usdc'] = '58',
        'document' => $context['invoice']->update(['reference' => 'CHANGED']),
        default => throw new LogicException('Unknown case.'),
    };
    expect(fn () => $capture->handle($context['actor'], $context['invoice'], $data))->toThrow(ValidationException::class)
        ->and(InvoiceVersion::query()->count())->toBe(1);
})->with(['key', 'amount', 'rate', 'document']);

test('versioned invoice source cannot enter legacy payment cycle policy draft or direct Circle service', function (): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    expect(fn () => app(FinancialPolicyEngine::class)->evaluateInvoice($context['invoice'], $context['wallet']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(VendorPaymentSnapshot::class)->build($context['institution'], $context['invoice'], $context['wallet'], new Money(0, CurrencyCode::USDC)))->toThrow(ValidationException::class)
        ->and(fn () => app(CircleWalletService::class)->executePaymentBaseUnits($context['wallet'], '0x'.str_repeat('2', 40), 1, TransactionType::VENDOR_PAYMENT, Invoice::class, $context['invoice']->id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(CircleWalletService::class)->executePaymentBaseUnits($context['wallet'], '0x'.str_repeat('2', 40), 1, TransactionType::VENDOR_PAYMENT, InvoiceVersion::class, $bill->id))->toThrow(InvalidArgumentException::class);
    $result = app(EduFlowAgent::class)->runAutonomousCycle($context['institution']);
    expect($context['invoice']->fresh()->status)->toBe('pending')->and(Transaction::query()->count())->toBe(0)
        ->and($result['stats']['auto_paid'])->toBe(0)
        ->and($result['stats']['total_disbursed_usdc'])->toBe(0.0);
});

test('authenticated evidence review is separate immutable and repeat safe but never payment authority', function (string $decision): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    actingAs($context['reviewer']);
    postJson(route('finance.invoice-versions.review', $bill), [
        'expected_digest' => $bill->snapshot_digest, 'decision' => $decision, 'reason' => 'Staff reviewed source and reference mapping.',
    ])->assertSuccessful()->assertJsonPath('data.can_execute', false)->assertJsonPath('data.bill.payment_approved', false);
    postJson(route('finance.invoice-versions.review', $bill), ['expected_digest' => $bill->snapshot_digest, 'decision' => $decision,
        'reason' => 'Staff reviewed source and reference mapping.'])->assertSuccessful();
    expect(InvoiceVersionReview::query()->count())->toBe(1)->and(Activity::query()->where('event', 'invoice_evidence_reviewed')->count())->toBe(1)
        ->and($context['invoice']->fresh()->status)->toBe('pending')->and(Transaction::query()->count())->toBe(0)
        ->and(Gate::forUser($context['reviewer'])->allows('execute', $bill))->toBeFalse();
})->with(['approve_evidence', 'reject', 'hold']);

test('review fails on self approval stale digest document drift and conflicting repeat', function (string $case): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    $reviewer = $context['reviewer'];
    $digest = $bill->snapshot_digest;
    if ($case === 'self') {
        $context['actor']->assignRole(Role::findOrCreate('super_admin', 'web'));
        $reviewer = $context['actor'];
    } elseif ($case === 'digest') {
        $digest = str_repeat('0', 64);
    } elseif ($case === 'document') {
        $context['invoice']->update(['due_date' => now()->addDays(2)]);
    } else {
        app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $digest, 'hold', 'Needs staff correction.');
    }
    expect(fn () => app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $digest, 'approve_evidence', 'Approved reference mapping.'))
        ->toThrow($case === 'self' ? AuthorizationException::class : ValidationException::class);
})->with(['self', 'digest', 'document', 'repeat']);

test('raw source amount change invalidates evidence review without claiming recovered precision', function (): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    DB::table((new Invoice)->getTable())->where('id', $context['invoice']->id)->update(['amount' => 58.51]);
    expect(fn () => app(ReviewInvoiceVersion::class)->handle($context['reviewer'], $bill, $bill->snapshot_digest, 'approve_evidence', 'Reviewed.'))->toThrow(ValidationException::class)
        ->and($bill->fresh()->source_minor_units)->toBe(5751);
});

test('evidence review tolerates PostgreSQL style object key order but not content drift', function (): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    $snapshot = array_reverse($bill->snapshot, true);
    $snapshot['document'] = array_reverse($snapshot['document'], true);
    DB::table((new InvoiceVersion)->getTable())->where('id', $bill->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    $review = app(ReviewInvoiceVersion::class)->handle($context['reviewer'], $bill, $bill->snapshot_digest, 'approve_evidence', 'Reviewed.');
    expect($review->hasValidEvidence($bill))->toBeTrue();
});

test('institution ownership forbids foreign invoice budget and review', function (string $case): void {
    $context = invoiceVersionContext();
    /** @var Organization $foreign */
    $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
    config(['eduflow.institution_id' => $context['institution']->id]);
    $resolver = Mockery::mock(InstallationInstitution::class);
    $resolver->shouldReceive('current')->andReturn($context['institution']);
    $resolver->shouldReceive('require')->andReturn($context['institution']);
    app()->instance(InstallationInstitution::class, $resolver);
    if ($case === 'invoice') {
        DB::table((new Invoice)->getTable())->where('id', $context['invoice']->id)->update(['organization_id' => $foreign->id]);
        expect(fn () => app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput()))->toThrow(ModelNotFoundException::class);
    } elseif ($case === 'budget') {
        $context['budget']->update(['organization_id' => $foreign->id]);
        expect(fn () => app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput()))->toThrow(ValidationException::class);
    } else {
        $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
        DB::table((new InvoiceVersion)->getTable())->where('id', $bill->id)->update(['organization_id' => $foreign->id]);
        actingAs($context['reviewer']);
        getJson(route('finance.invoice-versions.show', $bill))->assertForbidden();
        postJson(route('finance.invoice-versions.review', $bill), ['expected_digest' => $bill->snapshot_digest, 'decision' => 'approve_evidence', 'reason' => 'Reviewed.'])->assertForbidden();
    }
})->with(['invoice', 'budget', 'review']);

test('HTTP capture rejects inexact or numeric source input before creating evidence', function (): void {
    $context = invoiceVersionContext();
    actingAs($context['actor']);
    $data = invoiceVersionInput() + ['invoice_id' => $context['invoice']->id];
    $data['source_amount'] = '57.511';
    postJson(route('finance.invoice-versions.store'), $data)->assertUnprocessable();
    $data['source_amount'] = 57.51;
    postJson(route('finance.invoice-versions.store'), $data)->assertUnprocessable();
    expect(InvoiceVersion::query()->count())->toBe(0);
});

test('capture and review HTTP paths authenticate actual staff and reject spoofed authority', function (): void {
    $context = invoiceVersionContext();
    $data = invoiceVersionInput() + ['invoice_id' => $context['invoice']->id];
    postJson(route('finance.invoice-versions.store'), $data)->assertUnauthorized();
    /** @var User $ordinary */
    $ordinary = User::factory()->create();
    actingAs($ordinary);
    postJson(route('finance.invoice-versions.store'), $data)->assertForbidden();
    actingAs($context['actor']);
    postJson(route('finance.invoice-versions.store'), $data + ['prepared_by' => $context['reviewer']->id])->assertUnprocessable();
    postJson(route('finance.invoice-versions.store'), $data)->assertSuccessful()->assertJsonPath('data.snapshot.source.currency', 'PHP')
        ->assertJsonPath('data.snapshot.source.minor_units', '5751')->assertJsonPath('data.can_execute', false);
    /** @var InvoiceVersion $bill */
    $bill = InvoiceVersion::query()->sole();
    actingAs($context['reviewer']);
    getJson(route('finance.invoice-versions.show', $bill))->assertSuccessful();
    postJson(route('finance.invoice-versions.review', $bill), ['expected_digest' => $bill->snapshot_digest,
        'decision' => 'approve_evidence', 'reason' => 'Reviewed.', 'reviewed_by' => $context['actor']->id])->assertUnprocessable();
});

test('invalid source context refuses capture', function (string $case): void {
    $context = invoiceVersionContext();
    $data = invoiceVersionInput();
    match ($case) {
        'period' => $data['period_end'] = now()->toDateString(),
        'rate_time' => $data['rate_observed_at'] = now()->addDay()->toIso8601String(),
        'no_evidence' => $data['source_evidence'] = '',
        'budget' => $context['budget']->update(['status' => 'exhausted']),
        'closed' => $context['invoice']->update(['status' => 'paid']),
        default => throw new LogicException('Unknown case.'),
    };
    expect(fn () => app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], $data))->toThrow(ValidationException::class)
        ->and(InvoiceVersion::query()->count())->toBe(0);
})->with(['period', 'rate_time', 'no_evidence', 'budget', 'closed']);

test('self-consistent snapshot digest cannot conceal changed valuation calculation', function (): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    $snapshot = $bill->snapshot;
    $snapshot['mapping']['valuation_base_units'] = '1';
    DB::table((new InvoiceVersion)->getTable())->where('id', $bill->id)->update([
        'valuation_base_units' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_digest' => PaymentIntent::digest($snapshot),
    ]);
    expect($bill->fresh()->hasValidSnapshot())->toBeFalse();
});

test('one capture key cannot bind another bill and existing payment evidence refuses version capture', function (): void {
    $context = invoiceVersionContext();
    $input = invoiceVersionInput();
    app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], $input);
    $other = $context['invoice']->replicate();
    $other->reference = 'OTHER-'.Str::uuid();
    $other->save();
    expect(fn () => app(CaptureInvoiceVersion::class)->handle($context['actor'], $other, $input))->toThrow(ValidationException::class);
    Transaction::query()->create(['organization_id' => $context['institution']->id, 'wallet_id' => $context['wallet']->id,
        'type' => TransactionType::VENDOR_PAYMENT, 'recipient_address' => '0x'.str_repeat('2', 40), 'amount' => '1',
        'currency' => 'USDC', 'status' => 'pending', 'network' => 'arc', 'reference_type' => Invoice::class, 'reference_id' => $other->id]);
    expect(fn () => app(CaptureInvoiceVersion::class)->handle($context['actor'], $other, invoiceVersionInput()))->toThrow(ValidationException::class);
});

test('raw tampering and malformed invoice snapshots fail integrity verification', function (string $case): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    $updates = $case === 'amount' ? ['valuation_base_units' => 1] : ['snapshot' => 'null'];
    DB::table((new InvoiceVersion)->getTable())->where('id', $bill->id)->update($updates);
    expect($bill->fresh()->hasValidSnapshot())->toBeFalse()
        ->and(fn () => app(ReviewInvoiceVersion::class)->handle($context['reviewer'], $bill, $bill->snapshot_digest, 'approve_evidence', 'Reviewed.'))->toThrow(ValidationException::class);
})->with(['amount', 'shape']);

test('invoice records and reviews refuse model mutation', function (string $record, string $operation): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    $review = app(ReviewInvoiceVersion::class)->handle($context['reviewer'], $bill, $bill->snapshot_digest, 'approve_evidence', 'Reviewed.');
    $model = $record === 'bill' ? $bill : $review;
    expect(fn () => $operation === 'delete' ? $model->delete() : $model->update(['organization_id' => 999]))->toThrow(LogicException::class);
})->with(['bill', 'review'])->with(['update', 'delete']);

test('invoice version DB bounds prevent fractional negative mainnet and executable state writes', function (array $updates): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    expect(fn () => DB::table((new InvoiceVersion)->getTable())->where('id', $bill->id)->update($updates))->toThrow(QueryException::class);
})->with([[['source_minor_units' => 1.5]], [['valuation_base_units' => -1]], [['status' => 'settled']], [['source_currency' => 'INVALID']]]);

test('restrictive invoice version evidence prevents deleting source invoice budget and staff', function (string $record): void {
    $context = invoiceVersionContext();
    $bill = app(CaptureInvoiceVersion::class)->handle($context['actor'], $context['invoice'], invoiceVersionInput());
    app(ReviewInvoiceVersion::class)->handle($context['reviewer'], $bill, $bill->snapshot_digest, 'approve_evidence', 'Reviewed.');
    expect(fn () => $context[$record]->delete())->toThrow(QueryException::class);
})->with(['invoice', 'budget', 'actor', 'reviewer', 'institution']);

test('invoice version migration only rolls back empty evidence and factory needs existing source', function (): void {
    $migration = require database_path('migrations/2026_10_08_005233_create_invoice_versions_table.php');
    $migration->down();
    expect(Schema::hasTable('invoice_versions'))->toBeFalse();
    $migration->up();
    $context = invoiceVersionContext();
    expect(fn () => InvoiceVersion::factory()->make())->toThrow(LogicException::class);
    /** @var InvoiceVersion $bill */
    $bill = InvoiceVersion::factory()->forSource($context['invoice'], $context['actor'])->create();
    expect($bill->hasValidSnapshot())->toBeTrue()->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists');
});
