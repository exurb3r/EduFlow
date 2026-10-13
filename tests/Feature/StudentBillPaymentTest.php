<?php

declare(strict_types=1);

use App\Ai\Advisory\AdvisoryEnvelope;
use App\Ai\Advisory\AdvisoryGate;
use App\Enums\StudentBillCategory;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\BillPaymentPolicy;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\StudentBillPaymentQuote;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

/**
 * A student, an active operator-generated wallet, one bill, a funded Arc testnet
 * rail and a scripted chain.
 *
 * Only the rail and the chain are mocked. The quote, the consent, the policy
 * evaluation, the settlement verification and the allocation are all the
 * production code, so what is asserted here is the lane's own behaviour and not
 * a stub of it.
 *
 * `balance` is the funded on-chain balance in base units, so a test can make the
 * wallet poor without shadowing an expectation the fixture already owns.
 *
 * @param  array{category?: StudentBillCategory, amount_base_units?: int, balance?: int}  $overrides
 * @return array{student: Student, bill: StudentBill, arc: ArcNetworkGateway&MockInterface}
 */
function billPaymentContext(array $overrides = []): array
{
    Bus::fake();
    config(['eduflow.institution_id' => null, 'lepton.default' => 'circle', 'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002, 'lepton.arc.treasury' => '0x'.str_repeat('2', 40)]);
    Organization::factory()->create();

    $student = Student::factory()->create(['payout_address' => '0x'.str_repeat('1', 40)]);
    if (! $student instanceof Student) {
        throw new RuntimeException('Student factory returned no student.');
    }
    StudentWallet::factory()->create([
        'student_id' => $student->id, 'address' => $student->payout_address,
        'provider' => 'circle', 'status' => 'active', 'chain' => 'ARC-TESTNET',
    ]);
    $bill = StudentBill::factory()->create([
        'student_id' => $student->id,
        'category' => $overrides['category'] ?? StudentBillCategory::LABORATORY,
        'amount_base_units' => $overrides['amount_base_units'] ?? 150_000_000,
        'paid_base_units' => 0,
        'status' => StudentBill::STATUS_OPEN,
    ]);
    if (! $bill instanceof StudentBill) {
        throw new RuntimeException('Student bill factory returned no bill.');
    }

    $balance = BigInteger::of($overrides['balance'] ?? 20_000_000)->multipliedBy('1000000000000');
    $arc = Mockery::mock(ArcNetworkGateway::class);
    if (! $arc instanceof ArcNetworkGateway) {
        throw new RuntimeException('Arc mock does not implement gateway.');
    }
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    mockExpectation($arc, 'addressExplorerUrl')->andReturn('https://example.test/address');
    mockExpectation($arc, 'explorerUrl')->andReturn('https://example.test/tx');
    mockExpectation($arc, 'rpc')->with('eth_chainId')->andReturn('0x4cef52');
    mockExpectation($arc, 'rpc')->with('eth_getBalance', Mockery::any())->andReturn('0x'.$balance->toBase(16));
    app()->instance(ArcNetworkGateway::class, $arc);

    $transport = Mockery::mock(AsyncPaymentTransport::class);
    if (! $transport instanceof AsyncPaymentTransport) {
        throw new RuntimeException('Transport mock does not implement the rail.');
    }
    $transport->shouldReceive('estimate')->andReturn(['networkFee' => '0.02945113436514256']);
    app()->instance(AsyncPaymentTransport::class, $transport);

    return ['student' => $student, 'bill' => $bill, 'arc' => $arc];
}

/**
 * A sanitized advisory classification, or none at all.
 *
 * Built through the gate's sanitizer rather than by asking a model: the sanitizer
 * is the only place model output becomes application state, and `null` is the
 * honest answer when the switches are off — which is the real-world default, and
 * the branch that escalates.
 *
 * @param  array<string, mixed>  $overrides
 */
function billAdvisory(array $overrides = []): ?AdvisoryEnvelope
{
    return app(AdvisoryGate::class)->sanitizeClassification(array_merge([
        'category' => 'laboratory',
        'beneficiary_alias' => 'Chemistry Department',
        'confidence' => 0.97,
        'narrative' => 'Consumables for a laboratory subject this term.',
        'anomaly_flags' => [],
    ], $overrides));
}

/**
 * Mount a quote and record the payer's acceptance of it.
 *
 * @param  array<string, mixed>  $c
 * @param  array<string, mixed>  $accept
 */
function billConsent(array $c, int $amount = 5_000_000, ?AdvisoryEnvelope $advisory = null, array $accept = []): StudentBillPayment
{
    /** @var StudentBillPaymentQuote $service */
    $service = app(StudentBillPaymentQuote::class);
    $quote = $service->quote($c['student'], $c['bill'], $amount, null, $advisory);

    return $service->consent(
        $c['student'],
        $c['bill'],
        $amount,
        $quote['request_key'],
        $accept['digest'] ?? $quote['request_digest'],
        $accept['accepted'] ?? true,
    );
}

/**
 * Script the chain's answer for one transfer.
 *
 * The `$marker` fills the hash: a chain cannot contain the same hash twice, so
 * two genuinely different payments need two different ones, while the
 * double-posting case deliberately reuses one.
 *
 * @param  array<string, mixed>  $c
 * @param  array<string, mixed>  $overrides
 */
function billSettlementStub(array $c, StudentBillPayment $payment, int $amount = 5_000_000, array $overrides = [], string $marker = 'f'): string
{
    $hash = '0x'.str_repeat($marker, 64);
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionReceipt', [$hash])->andReturn([
        'transactionHash' => $hash, 'blockNumber' => '0x64', 'status' => '0x1',
        'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => [], ...$overrides,
    ]);
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionByHash', [$hash])->andReturn([
        'from' => $payment->payer_address, 'to' => $payment->recipient_address,
        'value' => '0x'.BigInteger::of($amount)->multipliedBy('1000000000000')->toBase(16), 'blockNumber' => '0x64',
    ]);
    mockExpectation($c['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);

    return $hash;
}

/**
 * Quote, consent, prove the movement and allocate it. The whole verified path.
 *
 * @param  array<string, mixed>  $c
 */
function billSettledPayment(array $c, int $amount = 5_000_000, ?AdvisoryEnvelope $advisory = null): StudentBillPayment
{
    /** @var StudentBillPaymentQuote $service */
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, $amount, $advisory);
    $service->recordSettlement($payment, billSettlementStub($c, $payment));

    return $service->allocate($payment->fresh());
}

test('a quote binds the exact principal, the bill and a digest', function (): void {
    $c = billPaymentContext();
    $quote = app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory());

    expect($quote['amount_base_units'])->toBe(5_000_000)
        ->and($quote['student_id'])->toBe($c['student']->id)
        ->and($quote['student_bill_id'])->toBe($c['bill']->id)
        ->and($quote['bill_category'])->toBe('laboratory')
        ->and($quote['category_label'])->toBe('Laboratory fee')
        ->and($quote['payer_address'])->toBe('0x'.str_repeat('1', 40))
        // The destination is configuration, so it cannot be a form field.
        ->and($quote['recipient_address'])->toBe(config('lepton.arc.treasury'))
        ->and($quote['chain'])->toBe('ARC-TESTNET')
        ->and($quote['chain_id'])->toBe(5042002)
        ->and($quote['quoted_network_fee'])->toBe('0.02945113436514256')
        // Ceiling rounded UP, so a wallet holding exactly the quoted total is
        // never a few base units short of paying it.
        ->and($quote['fee_ceiling_base_units'])->toBe(29_452)
        ->and($quote['total_base_units'])->toBe(5_029_452)
        ->and($quote['available_base_units'])->toBe(20_000_000)
        ->and($quote['remaining_base_units'])->toBe(150_000_000)
        ->and($quote['expires_at'])->not->toBe($quote['quoted_at'])
        ->and($quote['consent_text'])->toBe(StudentBillPayment::CONSENT_TEXT)
        ->and($quote['purpose'])->toBe(StudentBillPayment::PURPOSE)
        ->and($quote['request_digest'])->toMatch('/^[0-9a-f]{64}$/');

    $stored = StudentBillPayment::query()->where('request_key', $quote['request_key'])->sole();
    expect($stored->hasValidSnapshot())->toBeTrue()
        ->and($stored->snapshot_digest)->toBe($quote['request_digest']);
});

test('another students bill, or another bill, cannot be paid under this quote', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $quote = $service->quote($c['student'], $c['bill'], 5_000_000);

    $stranger = Student::factory()->create();
    $otherBill = StudentBill::factory()->for($c['student'])->create();

    // Neither a quote nor a consent may be mounted against a bill the student
    // does not owe, and a bill the registrar has withdrawn is not payable either.
    expect(fn () => $service->quote($stranger, $c['bill'], 5_000_000))
        ->toThrow(ValidationException::class, 'within this student bill remaining balance')
        ->and(fn () => $service->consent($stranger, $c['bill'], 5_000_000, $quote['request_key'], $quote['request_digest'], true))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => $service->consent($c['student'], $otherBill, 5_000_000, $quote['request_key'], $quote['request_digest'], true))
        ->toThrow(NotFoundHttpException::class)
        ->and(StudentBillPayment::query()->count())->toBe(1)
        ->and(StudentBillPayment::query()->whereNull('consent_digest')->count())->toBe(1);
});

test('a changed amount, a wrong digest or a refusal to accept is not consent', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $quote = $service->quote($c['student'], $c['bill'], 5_000_000);

    expect(fn () => $service->consent($c['student'], $c['bill'], 6_000_000, $quote['request_key'], $quote['request_digest'], true))
        ->toThrow(ValidationException::class, 'binds a different amount')
        ->and(fn () => $service->consent($c['student'], $c['bill'], 5_000_000, $quote['request_key'], $quote['request_digest'], false))
        ->toThrow(ValidationException::class, 'Accept the exact mounted quote')
        ->and(fn () => $service->consent($c['student'], $c['bill'], 5_000_000, $quote['request_key'], hash('sha256', 'not the quote'), true))
        ->toThrow(ValidationException::class, 'Accept the exact mounted quote')
        ->and(StudentBillPayment::query()->sole()->consent_digest)->toBeNull();
});

test('an expired quote cannot be consented to', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $quote = $service->quote($c['student'], $c['bill'], 5_000_000);

    $this->travel(StudentBillPaymentQuote::MAX_QUOTE_SECONDS + 5)->seconds();

    expect(fn () => $service->consent($c['student'], $c['bill'], 5_000_000, $quote['request_key'], $quote['request_digest'], true))
        ->toThrow(ValidationException::class, 'Quote expired')
        ->and(StudentBillPayment::query()->sole()->consent_digest)->toBeNull();
});

test('consent is bound to the quote by digest and is written once', function (): void {
    $c = billPaymentContext();
    $payment = billConsent($c, 5_000_000);

    expect($payment->hasValidConsent())->toBeTrue()
        ->and($payment->consent_snapshot['intent_digest'])->toBe($payment->snapshot_digest)
        ->and($payment->consent_snapshot['confirmed_by'])->toBe($c['student']->user_id)
        ->and($payment->consent_snapshot['accepted'])->toBeTrue()
        ->and($payment->consent_snapshot['bill_remaining_base_units_at_consent'])->toBe(150_000_000);

    // A replay finds the same consent rather than minting a second one.
    $service = app(StudentBillPaymentQuote::class);
    $again = $service->consent($c['student'], $c['bill'], 5_000_000, $payment->request_key, $payment->snapshot_digest, true);

    expect($again->id)->toBe($payment->id)
        ->and($again->consent_digest)->toBe($payment->consent_digest)
        ->and(StudentBillPayment::query()->count())->toBe(1);
});

test('an amount larger than the bill is refused and a settled payment shrinks what is owed', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);

    expect(fn () => $service->quote($c['student'], $c['bill'], 150_000_001))
        ->toThrow(ValidationException::class, 'within this student bill remaining balance')
        ->and(fn () => $service->quote($c['student'], $c['bill'], 0))
        ->toThrow(ValidationException::class, 'A positive amount');

    $payment = billSettledPayment($c);

    expect($payment->applied_base_units)->toBe(5_000_000)
        ->and($c['bill']->fresh()->paid_base_units)->toBe(5_000_000)
        ->and($c['bill']->fresh()->remainingAmount())->toBe(145_000_000)
        ->and($c['bill']->fresh()->status)->toBe(StudentBill::STATUS_PARTIALLY_PAID);
});

test('a withdrawn bill is refused outright', function (): void {
    $c = billPaymentContext();
    $c['bill']->update(['status' => StudentBill::STATUS_VOID]);

    expect(fn () => app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000))
        ->toThrow(ValidationException::class, 'the bill must be payable');
});

test('a wallet that cannot cover the principal plus the fee ceiling is refused', function (): void {
    // 5.000000 USDC of principal and nothing left for the 0.029452 ceiling.
    $c = billPaymentContext(['balance' => 5_000_000]);

    expect(fn () => app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000))
        ->toThrow(ValidationException::class, 'plus the network fee ceiling')
        ->and(StudentBillPayment::query()->count())->toBe(0);
});

test('a fee the rail will not report is a refusal, never a free transfer', function (): void {
    $c = billPaymentContext();
    // A fresh rail, because a fixture's own expectation would otherwise answer
    // first and the unreadable fee would never be reached.
    $transport = Mockery::mock(AsyncPaymentTransport::class);
    mockExpectation($transport, 'estimate')->andReturn(['networkFee' => 'broken']);
    app()->instance(AsyncPaymentTransport::class, $transport);

    expect(fn () => app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000))
        ->toThrow(ValidationException::class, 'never assumed free')
        ->and(StudentBillPayment::query()->count())->toBe(0);
});

test('a debt that shrinks after the payer confirmed lands the remainder as an overpayment', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);

    // The registrar corrected the charge while the payer was answering. Nothing
    // is applied yet, so the amount is still correctable.
    $c['bill']->update(['amount_base_units' => 2_000_000, 'status' => StudentBill::STATUS_OPEN]);

    $service->recordSettlement($payment, billSettlementStub($c, $payment));
    $allocated = $service->allocate($payment->fresh());

    expect($allocated->applied_base_units)->toBe(2_000_000)
        ->and($allocated->overpayment_base_units)->toBe(3_000_000)
        ->and($allocated->applied_base_units + $allocated->overpayment_base_units)->toBe($allocated->amount_base_units)
        ->and($allocated->overpayment())->toBe('3.0 USDC')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(2_000_000)
        ->and($c['bill']->fresh()->remainingAmount())->toBe(0)
        ->and($c['bill']->fresh()->status)->toBe(StudentBill::STATUS_PAID);
});

test('a second quote after a partial payment reflects the reduced remaining amount', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    billSettledPayment($c);
    // The in-memory bill predates the allocation, so re-read it: quoting from a
    // stale row would happily offer to collect a figure that is already paid.
    $c['bill']->refresh();

    expect($c['bill']->remainingAmount())->toBe(145_000_000);

    // The whole original figure is no longer collectable, and a share of the
    // reduced one is.
    expect(fn () => $service->quote($c['student'], $c['bill'], 150_000_000))
        ->toThrow(ValidationException::class, 'within this student bill remaining balance');

    $second = $service->quote($c['student'], $c['bill'], 19_000_000, null, billAdvisory());

    expect($second['remaining_base_units'])->toBe(145_000_000)
        ->and($second['amount_base_units'])->toBe(19_000_000);
});

test('allocating twice credits the bill once and leaves exactly one payment row', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);
    $service->recordSettlement($payment, billSettlementStub($c, $payment));

    $first = $service->allocate($payment->fresh());
    $second = $service->allocate($payment->fresh());
    $third = $service->allocate($first->fresh());

    expect($second->allocated_at?->toIso8601String())->toBe($first->allocated_at?->toIso8601String())
        ->and($third->applied_base_units)->toBe($first->applied_base_units)
        ->and($c['bill']->fresh()->paid_base_units)->toBe(5_000_000)
        ->and(StudentBillPayment::query()->count())->toBe(1);
});

test('allocation writes no ledger row and moves no balance', function (): void {
    $c = billPaymentContext();
    // Attached to the installation's own organization: a second one would make
    // the institution ambiguous and refuse every quote in the lane.
    Wallet::factory()->create(['organization_id' => Organization::query()->sole()->id, 'balance' => 25_420.00]);
    $balance = Wallet::query()->sole()->balance;

    $payment = billSettledPayment($c);

    expect($payment->isAllocated())->toBeTrue()
        ->and($payment->tx_verified_on_chain)->toBeTrue()
        ->and(Transaction::query()->count())->toBe(0)
        ->and(Wallet::query()->count())->toBe(1)
        ->and(Wallet::query()->sole()->balance)->toBe($balance);
});

test('the same settled movement cannot be posted under a second consent', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);
    $service->recordSettlement($payment, billSettlementStub($c, $payment));
    $service->allocate($payment->fresh());

    // A second consent for the identical transfer, which is what a replayed
    // settlement would look like if the movement were the only identity.
    $twin = billConsent($c, 5_000_000);
    $service->recordSettlement($twin, billSettlementStub($c, $twin));

    expect(fn () => $service->allocate($twin->fresh()))
        ->toThrow(ValidationException::class, 'already posted under another consent')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(5_000_000);
});

test('a rehearsal is recorded as a rehearsal and allocates nothing', function (): void {
    $c = billPaymentContext();
    config(['lepton.default' => 'fake']);
    $fake = new FakeLeptonGateway;
    $fake->seedBalance($c['student']->payout_address, 20_000_000);
    app()->instance(ArcNetworkGateway::class, $fake);
    app()->instance(WalletGateway::class, $fake);

    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);

    expect($payment->isRehearsal())->toBeTrue()
        ->and($payment->simulated)->toBeTrue()
        // Nothing was charged, so the quote reports a zero fee rather than a
        // fabricated one — and it says nothing settled.
        ->and($payment->snapshot['quoted_network_fee'])->toBe('0.000000')
        ->and($payment->hasValidSettlement())->toBeFalse()
        ->and($payment->isEligibleForUnattendedSubmission())->toBeFalse();

    expect(fn () => $service->recordSettlement($payment, '0x'.str_repeat('f', 64)))
        ->toThrow(ValidationException::class, 'Intact consent is required')
        ->and(fn () => $service->allocate($payment->fresh()))
        ->toThrow(ValidationException::class, 'exact verified movement proof')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0)
        ->and($c['bill']->fresh()->remainingAmount())->toBe(150_000_000)
        ->and($payment->fresh()->allocated_at)->toBeNull()
        ->and($payment->fresh()->applied_base_units)->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

test('a bill the policy escalates is quotable and consentable but never unattended', function (): void {
    // `miscellaneous` is the catch-all the registrar falls back to, so the
    // policy refuses to let it pay without a person whatever a classifier says.
    $c = billPaymentContext(['category' => StudentBillCategory::MISCELLANEOUS]);
    $service = app(StudentBillPaymentQuote::class);

    $quote = $service->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory());

    expect($quote['policy_decision'])->toBe('escalate')
        ->and($quote['policy_code'])->toBe(BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED)
        ->and($quote['requires_human_approval'])->toBeTrue()
        ->and($quote['eligible_for_unattended_submission'])->toBeFalse();

    // The student may still settle their own charge.
    $payment = $service->consent($c['student'], $c['bill'], 5_000_000, $quote['request_key'], $quote['request_digest'], true);

    expect($payment->requires_human_approval)->toBeTrue()
        ->and($payment->policy_code)->toBe(BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED)
        ->and($payment->isEligibleForUnattendedSubmission())->toBeFalse()
        ->and($payment->hasValidSnapshot())->toBeTrue()
        // The denial is bound into the quote, so it cannot be dropped between
        // mounting and consenting.
        ->and($payment->snapshot['requires_human_approval'])->toBeTrue();
});

test('with no advisory classification the policy treats the bill as unknown and escalates', function (): void {
    $c = billPaymentContext();
    $quote = app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000);

    expect(billAdvisory())->not->toBeNull()
        ->and($quote['policy_decision'])->toBe('escalate')
        ->and($quote['policy_code'])->toBe(BillPaymentPolicy::CODE_ADVISORY_MISSING)
        ->and($quote['requires_human_approval'])->toBeTrue();
});

test('a confident anomaly-free allow-listed bill is the only one eligible for unattended submission', function (): void {
    $c = billPaymentContext();
    $quote = app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory());

    expect($quote['policy_decision'])->toBe('auto_approve')
        ->and($quote['policy_code'])->toBe(BillPaymentPolicy::CODE_AUTO_PAYMENT)
        ->and($quote['requires_human_approval'])->toBeFalse()
        // Nothing has been accepted yet, so even an auto-approved quote is not
        // yet a submission.
        ->and($quote['eligible_for_unattended_submission'])->toBeFalse();

    $payment = billConsent($c, 5_000_000, billAdvisory());

    expect($payment->isEligibleForUnattendedSubmission())->toBeTrue();
});

test('a wallet that would be left empty is held for a person rather than paid', function (): void {
    $c = billPaymentContext();
    config(['lepton.default' => 'fake']);
    $fake = new FakeLeptonGateway;
    // Exactly the principal and nothing else: a rehearsal charges no fee, so
    // this is the one balance for which the reserve check can bite.
    $fake->seedBalance($c['student']->payout_address, 5_000_000);
    app()->instance(ArcNetworkGateway::class, $fake);
    app()->instance(WalletGateway::class, $fake);

    $quote = app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory());

    expect($quote['policy_decision'])->toBe('hold')
        ->and($quote['policy_code'])->toBe(BillPaymentPolicy::CODE_RESERVE_BREACH)
        ->and($quote['requires_human_approval'])->toBeTrue()
        ->and($quote['eligible_for_unattended_submission'])->toBeFalse();
});

test('a bill amount cannot be rewritten once money is applied, and the allocation path does not try', function (): void {
    $c = billPaymentContext();
    $payment = billSettledPayment($c);
    // The allocation credited a different instance of the bill; a stale one would
    // read `paid_base_units` as zero and let the rewrite through.
    $c['bill']->refresh();

    expect($c['bill']->paid_base_units)->toBe(5_000_000)
        ->and(fn () => $c['bill']->update(['amount_base_units' => 200_000_000]))
        ->toThrow(LogicException::class)
        ->and(StudentBill::query()->find($c['bill']->id)?->amount_base_units)->toBe(150_000_000)
        // And the correction cannot ride in with the payment either.
        ->and(fn () => $c['bill']->update(['amount_base_units' => 200_000_000, 'paid_base_units' => 55_000_000]))
        ->toThrow(LogicException::class)
        ->and($payment->fresh()->amount_base_units)->toBe(5_000_000)
        ->and($payment->fresh()->applied_base_units)->toBe(5_000_000)
        ->and($payment->fresh()->overpayment_base_units)->toBe(0)
        ->and($c['bill']->fresh()->remainingAmount())->toBe(145_000_000);
});

test('a correct allocation still works for another bill after one has been paid', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $first = billSettledPayment($c);
    $c['bill']->refresh();

    $secondBill = StudentBill::factory()->for($c['student'])->create([
        'category' => StudentBillCategory::EXAMINATION,
        'amount_base_units' => 5_000_000,
        'paid_base_units' => 0,
    ]);

    $quote = $service->quote($c['student'], $secondBill, 5_000_000, null, billAdvisory());
    $second = $service->consent($c['student'], $secondBill, 5_000_000, $quote['request_key'], $quote['request_digest'], true);
    $service->recordSettlement($second, billSettlementStub($c, $second, marker: 'e'));
    $service->allocate($second->fresh());

    expect($first->fresh()->isAllocated())->toBeTrue()
        ->and($second->fresh()->isAllocated())->toBeTrue()
        ->and($second->fresh()->applied_base_units)->toBe(5_000_000)
        ->and($secondBill->fresh()->status)->toBe(StudentBill::STATUS_PAID)
        ->and($secondBill->fresh()->remainingAmount())->toBe(0)
        ->and($c['bill']->fresh()->remainingAmount())->toBe(145_000_000)
        ->and(StudentBillPayment::query()->count())->toBe(2);
});

test('allocation refuses a payment whose proof is not intact', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);

    // Consented but never proven: nothing to apply.
    $consented = billConsent($c, 5_000_000);
    expect(fn () => $service->allocate($consented->fresh()))
        ->toThrow(ValidationException::class, 'exact verified movement proof')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0)
        ->and($consented->fresh()->allocated_at)->toBeNull();

    // Proven for a different principal than the one consented: the chain says
    // 1.000000 moved, so nothing is stored and nothing may be allocated.
    $other = billConsent($c, 5_000_000);
    $hash = billSettlementStub($c, $other, 1_000_000);

    expect(fn () => $service->recordSettlement($other, $hash))
        ->toThrow(ValidationException::class, 'does not establish this exact principal')
        ->and($other->fresh()->settlement_evidence_digest)->toBeNull()
        ->and($other->fresh()->tx_hash)->toBeNull()
        ->and($other->fresh()->tx_verified_on_chain)->toBeFalse()
        ->and(fn () => $service->allocate($other->fresh()))
        ->toThrow(ValidationException::class, 'exact verified movement proof')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0);
});

test('a reverted receipt proves nothing and is never recorded as a settlement', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);
    $hash = billSettlementStub($c, $payment, overrides: ['status' => '0x0']);

    expect(fn () => $service->recordSettlement($payment, $hash))
        ->toThrow(ValidationException::class, 'Verdict: reverted')
        ->and($payment->fresh()->settlement_evidence_digest)->toBeNull()
        ->and(fn () => $service->allocate($payment->fresh()))
        ->toThrow(ValidationException::class, 'exact verified movement proof')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0);
});

test('settlement evidence, the fee and the hash are write-once', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);
    $proven = $service->recordSettlement($payment, billSettlementStub($c, $payment));

    expect(fn () => $proven->update(['network_fee' => '0.5']))->toThrow(LogicException::class)
        ->and(fn () => $proven->update(['tx_hash' => '0x'.str_repeat('a', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $proven->update(['settlement_evidence_digest' => hash('sha256', 'other')]))->toThrow(LogicException::class)
        ->and(fn () => $proven->update(['tx_verified_on_chain' => false]))->toThrow(LogicException::class)
        ->and(fn () => $proven->update(['amount_base_units' => 1_000_000]))->toThrow(LogicException::class)
        ->and(fn () => $proven->delete())->toThrow(LogicException::class)
        ->and($proven->fresh()->network_fee)->toBe('0.000000000000021000')
        ->and($proven->fresh()->hasValidSettlement())->toBeTrue();
});

test('an applied allocation cannot be rewritten afterwards', function (): void {
    $c = billPaymentContext();
    $allocated = billSettledPayment($c);

    expect(fn () => $allocated->update(['applied_base_units' => 1_000_000]))->toThrow(LogicException::class)
        ->and(fn () => $allocated->update(['overpayment_base_units' => 4_000_000]))->toThrow(LogicException::class)
        ->and(fn () => $allocated->update(['allocated_at' => null]))->toThrow(LogicException::class)
        ->and($allocated->fresh()->applied_base_units)->toBe(5_000_000)
        ->and($allocated->fresh()->overpayment_base_units)->toBe(0);
});

test('the factory mints a row whose quote re-derives from its own columns', function (): void {
    $payment = StudentBillPayment::factory()->create();

    expect($payment->hasValidSnapshot())->toBeTrue()
        ->and($payment->applied_base_units)->toBe(5_000_000)
        ->and($payment->overpayment_base_units)->toBe(0)
        ->and($payment->studentBill->is($payment->studentBill))->toBeTrue();

    $quoted = StudentBillPayment::factory()->quoted()->create();

    expect($quoted->hasValidSnapshot())->toBeTrue()
        ->and($quoted->consent_digest)->toBeNull()
        ->and($quoted->tx_hash)->toBeNull()
        ->and($quoted->allocated_at)->toBeNull();

    $rehearsal = StudentBillPayment::factory()->rehearsal()->create();

    expect($rehearsal->hasValidSnapshot())->toBeTrue()
        ->and($rehearsal->isRehearsal())->toBeTrue()
        ->and($rehearsal->hasValidSettlement())->toBeFalse()
        ->and($rehearsal->isEligibleForUnattendedSubmission())->toBeFalse();
});
