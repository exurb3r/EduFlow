<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\ApproveFundingWindow;
use App\Actions\ApproveVendorDestination;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\EnrollPaymentReviewer;
use App\Actions\PrepareFundingWindow;
use App\Actions\PrepareVendorDestination;
use App\Actions\PrepareVendorPayment;
use App\Actions\ReleaseReservedCapacity;
use App\Actions\ReserveVendorPayment;
use App\Actions\ReviewInvoiceVersion;
use App\Actions\ReviewReservationRelease;
use App\Actions\ReviewVendorPayment;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Pages\PaymentReviews;
use App\Models\Budget;
use App\Models\FundingWindowApproval;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentReviewerEnrollment;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use Brick\Math\BigInteger;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\Expectation;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

/** @return array<string, mixed> */
function paymentAuthorizationContext(string $method = 'filament_app', bool $fake = false): array
{
    travelTo(now()->utc()->startOfSecond());
    config(['eduflow.institution_id' => null, 'eduflow.background_finance.enabled' => false,
        'lepton.default' => $fake ? 'fake' : 'circle', 'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002, 'lepton.arc.treasury' => null]);
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create();
    /** @var User $maker */
    $maker = User::factory()->create();
    $maker->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $reviewer->givePermissionTo(Permission::findOrCreate('AuthorizePayment:PaymentIntent', 'web'));
    RateLimiter::clear('finance.payment-review-mfa.'.$reviewer->id);
    $secret = app(Google2FA::class)->generateSecretKey();
    if ($method === 'filament_app') {
        $reviewer->saveAppAuthenticationSecret($secret);
    } else {
        $reviewer->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now()])->save();
    }
    /** @var User $checker */
    $checker = User::factory()->create();
    $checker->assignRole(Role::findOrCreate('admin', 'web'));
    $checkerSecret = app(Google2FA::class)->generateSecretKey();
    $checker->saveAppAuthenticationSecret($checkerSecret);
    RateLimiter::clear('finance.payment-review-mfa.'.$checker->id);
    $enrollment = app(EnrollPaymentReviewer::class)->handle($checker, $reviewer, [
        'request_key' => (string) Str::uuid(), 'reviewer_mfa_method' => $method, 'verification_reference' => 'Synthetic independent identity and authenticator check.',
        'password' => 'password', 'mfa_method' => 'filament_app',
        'mfa_code' => app(Google2FA::class)->oathTotp($checkerSecret, intdiv(now()->timestamp, 30)),
    ]);
    $policy = app(CreateFinancePolicyVersion::class)->handle($maker, 'review-v1', Money::fromDecimal('10', CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
    app(ActivateFinancePolicy::class)->handle($reviewer, $policy, null);
    /** @var Wallet $wallet */
    $wallet = Wallet::query()->create(['organization_id' => $institution->id, 'provider' => 'circle', 'network' => 'arc-testnet',
        'address' => '0x'.str_repeat('1', 40), 'balance' => '999999', 'status' => 'active']);
    /** @var Vendor $vendor */
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Approved IT Supplier', 'status' => 'verified',
        'risk_level' => 'low', 'wallet_address' => '0x'.str_repeat('2', 40)]);
    $destination = app(PrepareVendorDestination::class)->handle($maker, $vendor, 'review-v1', $vendor->wallet_address, 'ARC-TESTNET', 'synthetic-control-check');
    app(ApproveVendorDestination::class)->handle($reviewer, $destination, $destination->content_digest, null, 'synthetic-independent-check');
    /** @var Budget $budget */
    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'IT department', 'category' => 'software',
        'allocated_amount' => '999999', 'spent_amount' => '0', 'remaining_amount' => '999999', 'status' => 'active']);
    $bills = [];
    $drafts = [];
    foreach (['25.000001', '30.000001'] as $index => $amount) {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->create(['organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
            'reference' => 'REVIEW-'.Str::uuid(), 'amount' => '999999', 'due_date' => now()->addDays($index + 1), 'category' => 'software', 'status' => 'pending']);
        $bill = app(CaptureInvoiceVersion::class)->handle($maker, $invoice, [
            'capture_key' => (string) Str::uuid(), 'source_amount' => $amount, 'source_currency' => 'USDC',
            'source_evidence' => 'synthetic-approved-bill-'.$index, 'business_approval_reference' => 'synthetic-business-approval-'.$index,
            'department' => 'IT department', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
            'source_per_usdc' => '1', 'rate_source' => 'identity-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
        ]);
        app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $bill->snapshot_digest, 'approve_evidence', 'Synthetic source checked.');
        $bills[] = $bill;
        $drafts[] = app(PrepareVendorPayment::class)->handle($maker, $invoice, $wallet, new Money(2, CurrencyCode::USDC), (string) Str::uuid());
    }
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($maker, $budget, [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => 'IT department',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => array_map(fn (InvoiceVersion $bill): int => $bill->id, $bills), 'allocation' => '100', 'already_spent' => '0',
        'other_budget_commitments' => '0', 'opening_funds' => '100', 'realized_receipts' => '0', 'actual_outflows' => '0',
        'restricted_cash' => '0', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
        'budget_evidence' => 'synthetic-approved-allocation', 'cash_evidence' => 'synthetic-opening-funds',
        'commitment_evidence' => 'synthetic-other-obligations', 'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true,
        'opening_funds_exclude_collections' => true, 'collection_review_ids' => [],
    ]);
    stubPaymentAuthorizationBalance('100000000000000000007');
    $window = app(PrepareFundingWindow::class)->handle($maker, $snapshot, $wallet, (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
    $approval = app(ApproveFundingWindow::class)->handle($reviewer, $window, $window->snapshot_digest, 'Synthetic exclusive funds checked.');
    $hold = app(ReserveVendorPayment::class)->handle($maker, $drafts[0], $approval, (string) Str::uuid(), $drafts[0]->snapshot_digest, $approval->approval_digest);

    return ['institution' => $institution, 'maker' => $maker, 'reviewer' => $reviewer, 'secret' => $secret, 'method' => $method,
        'wallet' => $wallet, 'vendor' => $vendor, 'budget' => $budget, 'drafts' => $drafts, 'approval' => $approval,
        'window' => $window, 'hold' => $hold, 'checker' => $checker, 'checkerSecret' => $checkerSecret, 'enrollment' => $enrollment];
}

function stubPaymentAuthorizationBalance(string $native): void
{
    $arc = Mockery::mock(ArcNetworkGateway::class);
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    /** @var Expectation $rpc */
    $rpc = $arc->shouldReceive('rpc');
    $rpc->andReturnUsing(fn (string $method): mixed => match ($method) {
        'eth_chainId' => '0x'.BigInteger::of(5042002)->toBase(16),
        'eth_getBalance' => '0x'.BigInteger::of($native)->toBase(16),
        'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64),
            'timestamp' => '0x'.BigInteger::of(now()->timestamp - 1)->toBase(16)],
        default => throw new RuntimeException('Unexpected Arc read: '.$method),
    });
    app()->instance(ArcNetworkGateway::class, $arc);
}

/** @param array<string, mixed> $c
 * @return array<string, mixed>
 */
function paymentAuthorizationInput(array $c, string $decision = 'approve_payment', int $index = 0): array
{
    return ['request_key' => (string) Str::uuid(), 'payment_reservation_id' => $c['hold']->id,
        'intent_digest' => $c['drafts'][$index]->snapshot_digest, 'reservation_digest' => $c['hold']->snapshot_digest,
        'decision' => $decision, 'reason' => 'Approved bill, destination, allocation and fee ceiling reviewed.',
        'valid_until' => $decision === 'approve_payment' ? now()->addMinutes(3)->utc()->toIso8601String() : null,
        'password' => 'password', 'mfa_code' => app(Google2FA::class)->oathTotp($c['secret'], intdiv(now()->timestamp, 30)), 'mfa_method' => $c['method']];
}

/** @param array<string, mixed> $c */
function reserveSecondAuthorizationBill(array $c): PaymentReservation
{
    /** @var PaymentIntent $draft */
    $draft = $c['drafts'][1];
    /** @var FundingWindowApproval $approval */
    $approval = $c['approval'];

    return app(ReserveVendorPayment::class)->handle($c['maker'], $draft, $approval, (string) Str::uuid(), $draft->snapshot_digest, $approval->approval_digest);
}

test('independent MFA payment approval binds exact reserved amount without sending or changing local accounts', function (string $method): void {
    $c = paymentAuthorizationContext($method);
    $input = paymentAuthorizationInput($c);
    $authorization = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    expect($authorization->hasValidEvidence($c['drafts'][0], $c['hold']))->toBeTrue()
        ->and($authorization->snapshot['amount_base_units'])->toBe('25000001')->and($authorization->snapshot['max_fee_base_units'])->toBe('2')
        ->and($authorization->snapshot['balance_observation']['native_units'])->toBe('100000000000000000007')
        ->and($authorization->evidence()['payment_approved'])->toBeTrue()->and($authorization->evidence()['can_execute'])->toBeFalse()
        ->and($authorization->evidence()['payments_submitted'])->toBe(0)->and($authorization->evidence()['current_execution_checks_required'])->toBeTrue()
        ->and($c['drafts'][0]->evidence()['approved'])->toBeTrue()->and($c['drafts'][0]->evidence()['can_execute'])->toBeFalse()
        ->and(Gate::forUser($c['reviewer'])->allows('execute', $c['drafts'][0]))->toBeFalse()
        ->and($c['wallet']->fresh()->balance)->toBe(999999.0)->and($c['budget']->fresh()->remaining_amount)->toBe(999999.0)
        ->and(Invoice::query()->where('status', '!=', 'pending')->count())->toBe(0)
        ->and(Student::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reserved_vendor_payment_reviewed')->count())->toBe(1);
    $payload = json_encode($authorization->evidence(), JSON_THROW_ON_ERROR);
    expect($payload)->not->toContain($c['secret']);
    expect($payload)->not->toContain($input['mfa_code']);
    expect($payload)->not->toContain('factor_fingerprint');
    expect(json_encode($authorization->toArray(), JSON_THROW_ON_ERROR))->not->toContain('factor_fingerprint');
    expect(json_encode($authorization->toArray(), JSON_THROW_ON_ERROR))->not->toContain('snapshot"');
})->with(['filament_app', 'fortify_totp']);

test('same review retry returns historical evidence without renewing expired payment authority or using TOTP again', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $first = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    travel(4)->minutes();
    $retry = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    expect($retry->id)->toBe($first->id)->and($retry->evidence()['approval_recorded'])->toBeTrue()
        ->and($retry->evidence()['payment_approved'])->toBeFalse()->and($retry->evidence()['authorization_unexpired'])->toBeFalse()
        ->and(PaymentAuthorization::query()->count())->toBe(1)->and(Activity::query()->where('event', 'reserved_vendor_payment_reviewed')->count())->toBe(1);
});

test('new review identity decision amount or expiry cannot replace existing authority', function (string $case): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    if ($case === 'key') {
        $input['request_key'] = (string) Str::uuid();
    } elseif ($case === 'decision') {
        $input['decision'] = 'reject_payment';
        $input['valid_until'] = null;
    } elseif ($case === 'reason') {
        $input['reason'] = 'Different instruction.';
    } elseif ($case === 'expiry') {
        $input['valid_until'] = now()->addMinutes(4)->utc()->toIso8601String();
    } else {
        $input['intent_digest'] = str_repeat('0', 64);
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(1);
})->with(['key', 'decision', 'reason', 'expiry', 'digest']);

test('same authenticator timestep cannot approve another bill even through different MFA provider', function (string $method): void {
    $c = paymentAuthorizationContext($method);
    $secondHold = reserveSecondAuthorizationBill($c);
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    $c['hold'] = $secondHold;
    $input = paymentAuthorizationInput($c, index: 1);
    if ($method === 'filament_app') {
        $c['reviewer']->forceFill(['two_factor_secret' => encrypt($c['secret']), 'two_factor_confirmed_at' => now()])->save();
        $input['mfa_method'] = 'fortify_totp';
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][1], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(1);
    travel(31)->seconds();
    $input['mfa_method'] = $method;
    $input['mfa_code'] = app(Google2FA::class)->oathTotp($c['secret'], intdiv(now()->timestamp, 30));
    expect(app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][1], $input)->id)->toBeGreaterThan(1)
        ->and(PaymentAuthorization::query()->count())->toBe(2);
})->with(['filament_app', 'fortify_totp']);

test('payment reviewer needs explicit permission verified email and separation even as super admin', function (string $case): void {
    $c = paymentAuthorizationContext();
    $reviewer = $c['reviewer'];
    if ($case === 'permission') {
        $reviewer->revokePermissionTo('AuthorizePayment:PaymentIntent');
    } elseif ($case === 'email') {
        $reviewer->forceFill(['email_verified_at' => null])->save();
    } elseif ($case === 'role') {
        $reviewer->syncRoles([]);
    } elseif ($case === 'role-only-permission') {
        $reviewer->revokePermissionTo('AuthorizePayment:PaymentIntent');
        Role::findOrCreate('admin', 'web')->givePermissionTo('AuthorizePayment:PaymentIntent');
    } else {
        $reviewer = $c['maker'];
        $reviewer->assignRole(Role::findOrCreate('super_admin', 'web'));
        $reviewer->givePermissionTo('AuthorizePayment:PaymentIntent');
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($reviewer, $c['drafts'][0], paymentAuthorizationInput($c)))->toThrow(AuthorizationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
})->with(['permission', 'email', 'role', 'role-only-permission', 'maker']);

test('broad Gate override cannot grant payment permission through direct action or HTTP', function (): void {
    $c = paymentAuthorizationContext();
    $c['reviewer']->revokePermissionTo('AuthorizePayment:PaymentIntent');
    Gate::before(fn (): bool => true);
    actingAs($c['reviewer']);
    postJson(route('finance.payment-authorizations.store', $c['drafts'][0]), paymentAuthorizationInput($c))->assertForbidden();
    expect(PaymentAuthorization::query()->count())->toBe(0);
});

test('payment review refuses impersonated session despite valid factor and permissions', function (): void {
    $c = paymentAuthorizationContext();
    session(['impersonated_by' => $c['maker']->id]);
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c)))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
});

test('stale bill vendor treasury budget driver chain or cash blocks payment authorization', function (string $case): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    if ($case === 'invoice') {
        Invoice::query()->whereKey($c['drafts'][0]->invoice_id)->update(['status' => 'paid']);
    } elseif ($case === 'vendor') {
        $c['vendor']->update(['wallet_address' => '0x'.str_repeat('3', 40)]);
    } elseif ($case === 'wallet') {
        $c['wallet']->update(['status' => 'inactive']);
    } elseif ($case === 'budget') {
        $c['budget']->update(['status' => 'inactive']);
    } elseif ($case === 'driver') {
        config(['lepton.default' => 'fake']);
    } elseif ($case === 'chain') {
        config(['lepton.arc.chain' => 'ARC', 'lepton.arc.chain_id' => 5042]);
    } elseif ($case === 'expiry') {
        travel(11)->minutes();
        $input = paymentAuthorizationInput($c);
    } elseif ($case === 'cash') {
        reserveSecondAuthorizationBill($c);
        stubPaymentAuthorizationBalance('50000000000000000000');
    } else {
        DB::table((new PaymentReservation)->getTable())->where('id', $c['hold']->id)->update(['amount_base_units' => 1]);
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0)->and(PaymentReservation::query()->count())->toBeGreaterThan(0)
        ->and(Transaction::query()->count())->toBe(0);
})->with(['invoice', 'vendor', 'wallet', 'budget', 'driver', 'chain', 'expiry', 'cash', 'tamper']);

test('reject or hold records independent decision without releasing held funds despite funding expiry', function (string $decision): void {
    $c = paymentAuthorizationContext();
    travel(11)->minutes();
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    $review = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c, $decision));
    expect($review->hasValidEvidence($c['drafts'][0], $c['hold']))->toBeTrue()->and($review->evidence()['payment_approved'])->toBeFalse()
        ->and($review->evidence()['funds_reserved'])->toBeTrue()->and($review->evidence()['can_execute'])->toBeFalse()
        ->and(PaymentReservation::query()->count())->toBe(1)->and(Transaction::query()->count())->toBe(0);
})->with(['reject_payment', 'hold_payment']);

test('fake payment approval stays simulation only and never grants network payment authority', function (): void {
    $c = paymentAuthorizationContext(fake: true);
    $review = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    expect($review->evidence()['is_fake'])->toBeTrue()->and($review->evidence()['authority_scope'])->toBe('simulation_only')
        ->and($review->evidence()['approval_recorded'])->toBeTrue()->and($review->evidence()['payment_approved'])->toBeFalse()
        ->and($review->evidence()['can_execute'])->toBeFalse();
});

test('changing account authenticator cannot bypass independently pinned payment enrollment', function (): void {
    $c = paymentAuthorizationContext();
    $c['secret'] = app(Google2FA::class)->generateSecretKey();
    $c['reviewer']->saveAppAuthenticationSecret($c['secret']);
    expect($c['enrollment']->matchesCurrentFactor($c['reviewer']))->toBeFalse()
        ->and(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c)))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
});

test('revoked reviewer permission or changed authenticator invalidates current payment authority without rewriting history', function (string $case): void {
    $c = paymentAuthorizationContext();
    $authorization = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    if ($case === 'permission') {
        $c['reviewer']->revokePermissionTo('AuthorizePayment:PaymentIntent');
    } else {
        $c['reviewer']->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    }
    expect($authorization->evidence()['approval_recorded'])->toBeTrue()->and($authorization->evidence()['payment_approved'])->toBeFalse()
        ->and($authorization->evidence()['reviewer_authority_current'])->toBeFalse()->and($authorization->evidence()['can_execute'])->toBeFalse();
})->with(['permission', 'factor']);

test('TOTP already used in login cannot authorize payment review', function (string $provider): void {
    $c = paymentAuthorizationContext($provider === 'filament' ? 'filament_app' : 'fortify_totp');
    $input = paymentAuthorizationInput($c);
    $key = $provider === 'filament' ? 'filament.app_authentication_codes.'.md5($c['secret']) : 'fortify.2fa_codes.'.md5($input['mfa_code']);
    Cache::put($key, intdiv(now()->timestamp, 30), 600);
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
})->with(['filament', 'fortify']);

test('successful review publishes login replay markers only after durable commit', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $level = DB::transactionLevel();
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);
    app('db.transactions')->commit(DB::connection()->getName(), $level, $level - 1);
    expect(Cache::get('filament.app_authentication_codes.'.md5($c['secret'])))->toBe(intdiv(now()->timestamp, 30))
        ->and(Cache::get('fortify.2fa_codes.'.md5($input['mfa_code'])))->toBe(intdiv(now()->timestamp, 30));
});

test('reviewer enrollment requires different MFA checked staff and cannot replace pinned factor', function (): void {
    $c = paymentAuthorizationContext();
    $input = ['request_key' => $c['enrollment']->request_key, 'reviewer_mfa_method' => 'filament_app',
        'verification_reference' => 'Synthetic independent identity and authenticator check.', 'password' => 'password', 'mfa_method' => 'filament_app',
        'mfa_code' => app(Google2FA::class)->oathTotp($c['checkerSecret'], intdiv(now()->timestamp, 30))];
    expect(app(EnrollPaymentReviewer::class)->handle($c['checker'], $c['reviewer'], $input)->id)->toBe($c['enrollment']->id)
        ->and(fn () => app(EnrollPaymentReviewer::class)->handle($c['reviewer'], $c['reviewer'], $input))->toThrow(ValidationException::class)
        ->and(fn () => $c['enrollment']->update(['mfa_method' => 'fortify_totp']))->toThrow(LogicException::class);
    $input['request_key'] = (string) Str::uuid();
    expect(fn () => app(EnrollPaymentReviewer::class)->handle($c['checker'], $c['reviewer'], $input))->toThrow(ValidationException::class)
        ->and(PaymentReviewerEnrollment::query()->count())->toBe(1);
});

test('fresh password enrolled TOTP and bounded UTC expiry are mandatory', function (string $case): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    if ($case === 'password') {
        $input['password'] = 'wrong-password';
    } elseif ($case === 'code') {
        $input['mfa_code'] = 'wrong-code';
    } elseif ($case === 'enrollment') {
        $c['reviewer']->saveAppAuthenticationSecret(null);
    } elseif ($case === 'unconfirmed') {
        $c['reviewer']->forceFill(['two_factor_secret' => encrypt($c['secret']), 'two_factor_confirmed_at' => null])->save();
        $input['mfa_method'] = 'fortify_totp';
    } elseif ($case === 'missing-expiry') {
        $input['valid_until'] = null;
    } elseif ($case === 'wide-expiry') {
        $input['valid_until'] = now()->addMinutes(6)->utc()->toIso8601String();
    } elseif ($case === 'expired') {
        $input['valid_until'] = now()->utc()->toIso8601String();
    } else {
        $input['valid_until'] = now()->addMinutes(3)->setTimezone('Asia/Manila')->toIso8601String();
    }
    expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
})->with(['password', 'code', 'enrollment', 'unconfirmed', 'missing-expiry', 'wide-expiry', 'expired', 'non-utc']);

test('authentication rate limit survives failed database review transactions', function (): void {
    $c = paymentAuthorizationContext();
    $bad = paymentAuthorizationInput($c);
    $bad['password'] = 'wrong-password';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $bad))->toThrow(ValidationException::class);
    }
    expect(RateLimiter::attempts('finance.payment-review-mfa.'.$c['reviewer']->id))->toBe(5)
        ->and(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c)))->toThrow(ValidationException::class)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
});

test('payment authorization write failure rolls back audit and MFA consumption for safe retry', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    DB::unprepared("CREATE TRIGGER reject_test_payment_authorization BEFORE INSERT ON payment_authorizations
        BEGIN SELECT RAISE(ABORT, 'Injected authorization write failure'); END");
    try {
        expect(fn () => app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input))->toThrow(QueryException::class)
            ->and(PaymentAuthorization::query()->count())->toBe(0)->and(Activity::query()->where('event', 'reserved_vendor_payment_reviewed')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER reject_test_payment_authorization');
    }
    expect(app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input)->hasValidEvidence($c['drafts'][0], $c['hold']))->toBeTrue();
});

test('authorization evidence rejects mutation deletion tampering and destructive rollback', function (): void {
    $c = paymentAuthorizationContext();
    $review = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    expect(fn () => $review->update(['decision' => 'reject_payment']))->toThrow(LogicException::class)
        ->and(fn () => $review->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table((new PaymentReservation)->getTable())->where('id', $c['hold']->id)->delete())->toThrow(QueryException::class);
    $migration = require database_path('migrations/2026_10_09_142623_create_payment_authorizations_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    DB::table((new PaymentAuthorization)->getTable())->where('id', $review->id)->update(['decision' => 'reject_payment']);
    expect($review->fresh()->evidence()['evidence_valid'])->toBeFalse()->and($review->fresh()->evidence()['payment_approved'])->toBeFalse();
});

test('payment review HTTP endpoints enforce authority exact input and private evidence response', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $url = route('finance.payment-authorizations.store', $c['drafts'][0]);
    actingAs($c['maker']);
    postJson($url, $input)->assertForbidden();
    actingAs($c['reviewer']);
    postJson($url, [...$input, 'amount_base_units' => 1])->assertUnprocessable()->assertJsonValidationErrors('amount_base_units');
    postJson($url, $input)->assertSuccessful()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.payment_approved', true)->assertJsonPath('data.can_execute', false)->assertJsonMissingPath('data.factor_fingerprint');
    getJson(route('finance.payment-authorizations.show', $c['drafts'][0]))->assertSuccessful()->assertJsonPath('data.decision', 'approve_payment');
    $outsider = User::factory()->create();
    actingAs($outsider);
    getJson(route('finance.payment-authorizations.show', $c['drafts'][0]))->assertForbidden();
});

test('finance payment review page displays exact held evidence and records independent MFA decision', function (): void {
    $c = paymentAuthorizationContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    get(PaymentReviews::getUrl(panel: 'finance'))->assertSuccessful()->assertSee('Approval is authority, not settlement.');
    $page = Livewire::test(PaymentReviews::class)->assertSee('Payment review queue')->assertSee('25.000001 USDC');
    $page->__call('mountAction', [TestAction::make('viewPaymentEvidence')->table($c['hold'])]);
    $page->assertSet('mountedReservationDigest', $c['hold']->snapshot_digest)
        ->assertSet('mountedIntentDigest', $c['drafts'][0]->snapshot_digest);
    $page->__call('unmountAction', []);
    $input = paymentAuthorizationInput($c);
    $page->__call('callAction', [TestAction::make('reviewReservedPayment')->table($c['hold']), [
        'decision' => 'approve_payment', 'reason' => $input['reason'], 'valid_until' => $input['valid_until'],
        'password' => $input['password'], 'mfa_code' => $input['mfa_code'], 'mfa_method' => 'filament_app', 'attestation' => true,
    ]]);
    $page->__call('assertHasNoFormErrors', []);
    expect(PaymentAuthorization::query()->count())->toBe(1)->and(Transaction::query()->count())->toBe(0)
        ->and(PaymentAuthorization::query()->firstOrFail()->evidence()['can_execute'])->toBeFalse();
});

test('finance page independently enrolls another verified payment reviewer without granting payment or sending money', function (): void {
    $c = paymentAuthorizationContext();
    /** @var User $target */
    $target = User::factory()->create();
    $target->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $target->givePermissionTo('AuthorizePayment:PaymentIntent');
    $target->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    travel(31)->seconds();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['checker']);
    $page = Livewire::test(PaymentReviews::class);
    $page->__call('callAction', ['enrollPaymentReviewer', [
        'reviewer_id' => $target->id, 'reviewer_mfa_method' => 'filament_app',
        'verification_reference' => 'Synthetic independent staff and authenticator verification.',
        'password' => 'password', 'mfa_method' => 'filament_app',
        'mfa_code' => app(Google2FA::class)->oathTotp($c['checkerSecret'], intdiv(now()->timestamp, 30)), 'attestation' => true,
    ]]);
    $page->__call('assertHasNoFormErrors', []);
    expect(PaymentReviewerEnrollment::query()->where('reviewer_id', $target->id)->firstOrFail()->matchesCurrentFactor($target))->toBeTrue()
        ->and(PaymentAuthorization::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
});

test('finance page hides review from staff without separately granted payment authority', function (): void {
    $c = paymentAuthorizationContext();
    $c['reviewer']->revokePermissionTo('AuthorizePayment:PaymentIntent');
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    $page = Livewire::test(PaymentReviews::class);
    $page->__call('assertActionHidden', [TestAction::make('reviewReservedPayment')->table($c['hold'])]);
    expect(PaymentAuthorization::query()->count())->toBe(0);
});

test('failed HTML payment review never flashes password or MFA code into session', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $input['reason'] = '';
    actingAs($c['reviewer']);
    post(route('finance.payment-authorizations.store', $c['drafts'][0]), $input)->assertSessionHasErrors('reason')
        ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.mfa_code');
});

test('payment approval workspace reports historical decisions separately from awaiting review', function (string $decision, string $bucket): void {
    $c = paymentAuthorizationContext();
    $second = reserveSecondAuthorizationBill($c);
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c, $decision));
    travel(4)->minutes();
    $c['reviewer']->assignRole(Role::findOrCreate('super_admin', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($c['reviewer']);

    $page = Livewire::test(PaymentReviews::class)
        ->assertSee('Historical decisions, not current authority or settlement')
        ->assertSee('Your reviewer setup')
        ->assertSee('Account prerequisites met');
    $overview = $page->instance()->reviewOverview();
    expect($overview['awaiting'])->toBe(1)->and($overview[$bucket])->toBe(1)->and($overview['released'])->toBe(0);
    $page->__call('filterTable', ['review_status', 'awaiting']);
    $page->__call('assertCanSeeTableRecords', [[$second]]);
    $page->__call('assertCanNotSeeTableRecords', [[$c['hold']]]);
    $page->__call('filterTable', ['review_status', $bucket]);
    $page->__call('assertCanSeeTableRecords', [[$c['hold']]]);
    $page->__call('assertCanNotSeeTableRecords', [[$second]]);
    expect(Transaction::query()->count())->toBe(0);
})->with([
    ['approve_payment', 'approved'], ['hold_payment', 'held_or_rejected'], ['reject_payment', 'held_or_rejected'],
]);

test('payment approval workspace explains missing reviewer prerequisites without exposing a review action', function (string $case): void {
    $c = paymentAuthorizationContext();
    if ($case === 'permission') {
        $c['reviewer']->revokePermissionTo('AuthorizePayment:PaymentIntent');
    } elseif ($case === 'email') {
        $c['reviewer']->forceFill(['email_verified_at' => null])->save();
    } else {
        $c['reviewer']->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    }
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    $page = Livewire::test(PaymentReviews::class)->assertSee('Setup required')->assertSee('Reviewer setup required');
    $page->__call('assertActionHidden', [TestAction::make('reviewReservedPayment')->table($c['hold'])]);
    expect($page->instance()->reviewerReadiness()['ready'])->toBeFalse()
        ->and(PaymentAuthorization::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
})->with(['permission', 'email', 'factor']);

test('payment approval workspace excludes released capacity from awaiting reviews and preserves readable history', function (): void {
    $c = paymentAuthorizationContext();
    $proposer = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
    $proposal = app(ReleaseReservedCapacity::class)->handle($proposer, $c['hold'], (string) Str::uuid(), $c['hold']->snapshot_digest, 'Capacity no longer needed.');
    app(ReviewReservationRelease::class)->handle($c['reviewer'], $proposal, $proposal->content_digest, 'approve_release', 'Capacity no longer needed.');
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    $page = Livewire::test(PaymentReviews::class)->assertSee('Capacity released · history only');
    expect($page->instance()->reviewOverview())->toBe(['awaiting' => 0, 'approved' => 0, 'held_or_rejected' => 0, 'released' => 1]);
    $page->__call('assertActionHidden', [TestAction::make('reviewReservedPayment')->table($c['hold'])]);
    $page->__call('mountAction', [TestAction::make('viewPaymentEvidence')->table($c['hold'])]);
    $page->assertSet('mountedPaymentEvidence.reservation_state', 'Released — application capacity returned');
    expect(PaymentAuthorization::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
});

test('payment approval workspace fails closed when institution context becomes ambiguous', function (): void {
    $c = paymentAuthorizationContext();
    Organization::factory()->create();
    config(['eduflow.institution_id' => $c['institution']->id]);
    $c['reviewer']->assignRole(Role::findOrCreate('super_admin', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($c['reviewer']);
    get(PaymentReviews::getUrl(panel: 'admin'))->assertForbidden();
    expect(PaymentAuthorization::query()->count())->toBe(0);
});

test('payment approval workspace labels simulation approval as historical rather than network authority', function (): void {
    $c = paymentAuthorizationContext(fake: true);
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    $page = Livewire::test(PaymentReviews::class)->assertSee('Simulation only')
        ->assertSee('Historical decisions, not current authority or settlement');
    expect($page->instance()->reviewOverview()['approved'])->toBe(1)
        ->and(PaymentAuthorization::query()->firstOrFail()->evidence()['payment_approved'])->toBeFalse()
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0);
});

test('payment approval workspace explains maker checker separation even when account prerequisites are met', function (): void {
    $c = paymentAuthorizationContext();
    $c['maker']->givePermissionTo('AuthorizePayment:PaymentIntent');
    $secret = app(Google2FA::class)->generateSecretKey();
    $c['maker']->saveAppAuthenticationSecret($secret);
    travel(31)->seconds();
    app(EnrollPaymentReviewer::class)->handle($c['checker'], $c['maker'], [
        'request_key' => (string) Str::uuid(), 'reviewer_mfa_method' => 'filament_app', 'verification_reference' => 'Synthetic independent maker factor verification.',
        'password' => 'password', 'mfa_method' => 'filament_app',
        'mfa_code' => app(Google2FA::class)->oathTotp($c['checkerSecret'], intdiv(now()->timestamp, 30)),
    ]);
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['maker']);
    $page = Livewire::test(PaymentReviews::class)->assertSee('Account prerequisites met')->assertSee('Another independent reviewer required');
    $page->__call('assertActionHidden', [TestAction::make('reviewReservedPayment')->table($c['hold'])]);
    expect(PaymentAuthorization::query()->count())->toBe(0);
});
