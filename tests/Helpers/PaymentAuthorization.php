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
use App\Actions\ReserveVendorPayment;
use App\Actions\ReviewInvoiceVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\FundingWindowApproval;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Mockery\Expectation;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\travelTo;

/**
 * The one institution every vendor-payment review test starts from.
 *
 * These live in a shared helper rather than beside the tests that first needed
 * them because the cross-workflow tests — a settled tuition receipt funding a
 * reviewed proposal and a vendor outflow, for instance — depend on this exact
 * institution. A fixture defined inside one feature file is invisible to every
 * other file, so such a test only passes when the whole suite happens to run.
 */
/** @return array<string, mixed> */
function paymentAuthorizationContext(string $method = 'filament_app', bool $fake = false, bool $withFunding = true): array
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
    $window = $withFunding ? app(PrepareFundingWindow::class)->handle($maker, $snapshot, $wallet, (string) Str::uuid(), now()->addMinutes(10)->toIso8601String()) : null;
    $approval = $window === null ? null : app(ApproveFundingWindow::class)->handle($reviewer, $window, $window->snapshot_digest, 'Synthetic exclusive funds checked.');
    $hold = $approval === null ? null : app(ReserveVendorPayment::class)->handle($maker, $drafts[0], $approval, (string) Str::uuid(), $drafts[0]->snapshot_digest, $approval->approval_digest);

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
