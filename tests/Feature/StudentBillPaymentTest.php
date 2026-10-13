<?php

declare(strict_types=1);

use App\Enums\AgentDecisionType;
use App\Enums\StudentBillCategory;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\BillPaymentPolicy;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\StudentBillPaymentQuote;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

// Fixtures (billPaymentContext, billAdvisory, billConsent, billSettlementStub,
// billSettledPayment) now live in tests/Helpers/StudentBillFixtures.php so the
// quote, allocation and submission suites can each run on their own.

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
        // Money is arriving at the college, so the quote says in as many words
        // that it grants nothing about money leaving it.
        ->and($quote['authorizes_outgoing_spend'])->toBeFalse()
        ->and($quote['outgoing_spend_assessment_scope'])->toBe(StudentBillPayment::SPEND_ASSESSMENT_SCOPE)
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

    // Only now is a second consent legitimate: the first payment is allocated,
    // so its outcome is known and the debt has already moved by 5.000000. A
    // second consent while the first had no outcome is refused instead — see
    // "a second consent for a bill with no outcome yet is refused" below.
    //
    // A second consent for the identical transfer is still what a replayed
    // settlement looks like if the movement were the only identity.
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
        ->and($payment->authorizesOutgoingSpend())->toBeFalse()
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

    // A rehearsal can never settle, so it must not wedge the bill: a further
    // payment for the same bill is still mountable.
    $rehearsed = $service->quote($c['student'], $c['bill'], 5_000_000);

    expect($rehearsed['request_key'])->not->toBe($payment->request_key)
        ->and($rehearsed['simulated'])->toBeTrue();
});

test('a bill the assessment cannot vouch for is still consentable, and still authorises nothing', function (): void {
    // `miscellaneous` is the catch-all the registrar falls back to, so the
    // assessment cannot vouch for it whatever a classifier says. That is a
    // reason for a person to read it, not a reason to stop the student paying.
    $c = billPaymentContext(['category' => StudentBillCategory::MISCELLANEOUS]);
    $service = app(StudentBillPaymentQuote::class);

    $quote = $service->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory());

    expect($quote['policy_decision'])->toBe('advisory_classification')
        ->and($quote['policy_code'])->toBe(BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED)
        ->and($quote['requires_human_approval'])->toBeTrue()
        ->and($quote['eligible_for_unattended_submission'])->toBeFalse();

    // Consent is a statement by the payer about their own transfer. It does not
    // ask for a vendor spending approval, so it is not gated on one.
    $payment = $service->consent($c['student'], $c['bill'], 5_000_000, $quote['request_key'], $quote['request_digest'], true);

    expect($payment->requires_human_approval)->toBeTrue()
        ->and($payment->policy_code)->toBe(BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED)
        ->and($payment->hasValidConsent())->toBeTrue()
        ->and($payment->isEligibleForUnattendedSubmission())->toBeFalse()
        ->and($payment->authorizesOutgoingSpend())->toBeFalse()
        ->and($payment->hasValidSnapshot())->toBeTrue()
        // The assessment is bound into the quote, so it cannot be dropped
        // between mounting and consenting.
        ->and($payment->snapshot['requires_human_approval'])->toBeTrue()
        ->and($payment->snapshot['policy_code'])->toBe(BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED);
});

test('with no advisory classification the assessment records the bill as unknown', function (): void {
    $c = billPaymentContext();
    $quote = app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000);

    expect(billAdvisory())->not->toBeNull()
        ->and($quote['policy_decision'])->toBe('advisory_classification')
        ->and($quote['policy_code'])->toBe(BillPaymentPolicy::CODE_ADVISORY_MISSING)
        ->and($quote['requires_human_approval'])->toBeTrue()
        ->and($quote['authorizes_outgoing_spend'])->toBeFalse();
});

test('a confident anomaly-free allow-listed bill is ordinary to triage and never spend authority', function (): void {
    $c = billPaymentContext();
    $quote = app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory());

    expect($quote['policy_decision'])->toBe('advisory_classification')
        ->and($quote['policy_code'])->toBe(StudentBillPayment::CODE_INBOUND_ADVISORY_ONLY)
        // The old verdict here was `auto_approve` with
        // `requires_human_approval = false`, which read as permission to release
        // treasury funds on the strength of a payment arriving at the treasury.
        ->and($quote['policy_decision'])->not->toBe('auto_approve')
        ->and($quote['requires_human_approval'])->toBeTrue()
        ->and($quote['authorizes_outgoing_spend'])->toBeFalse()
        ->and($quote['eligible_for_unattended_submission'])->toBeFalse();

    $payment = billConsent($c, 5_000_000, billAdvisory());

    // Consent is intact, the balance is ample and the classification is certain,
    // and it is still not releasable unattended.
    expect($payment->hasValidConsent())->toBeTrue()
        ->and($payment->authorizesOutgoingSpend())->toBeFalse()
        ->and($payment->isEligibleForUnattendedSubmission())->toBeFalse();
});

test('a wallet holding exactly the principal is never read as the treasury reserve', function (): void {
    // The old verdict here was `TREASURY_RESERVE_BREACH_V1` — a *treasury* hold
    // produced by the *student's* wallet being emptied. That is an
    // institutional question answered with the payer's balance, so no reserve
    // code may appear at all: this lane has no reserve to protect, and never
    // had one to breach.
    $c = billPaymentContext();
    config(['lepton.default' => 'fake']);
    $fake = new FakeLeptonGateway;
    // Exactly the principal and nothing else: a rehearsal charges no fee.
    $fake->seedBalance($c['student']->payout_address, 5_000_000);
    app()->instance(ArcNetworkGateway::class, $fake);
    app()->instance(WalletGateway::class, $fake);

    $quote = app(StudentBillPaymentQuote::class)->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory());

    expect($quote['policy_decision'])->toBe('advisory_classification')
        ->and($quote['policy_code'])->toBe(StudentBillPayment::CODE_INBOUND_ADVISORY_ONLY)
        ->and($quote['policy_code'])->not->toBe(BillPaymentPolicy::CODE_RESERVE_BREACH)
        ->and($quote['available_base_units'])->toBe(5_000_000)
        ->and($quote['requires_human_approval'])->toBeTrue()
        ->and($quote['authorizes_outgoing_spend'])->toBeFalse();
});

test('a second consent for a bill with no outcome yet is refused, and a replay is not a second payment', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);

    // Replaying the SAME request key reads the mounted quote back rather than
    // minting a second one, so a double-submitted button is not a second
    // payment.
    $replay = $service->quote($c['student'], $c['bill'], 5_000_000, $payment->request_key);

    expect($replay['request_digest'])->toBe($payment->snapshot_digest)
        ->and($replay['authorizes_outgoing_spend'])->toBeFalse()
        ->and(StudentBillPayment::query()->count())->toBe(1);

    // A NEW request key for the same bill is refused while the first payment
    // has no outcome: two consents authorise two transfers whose results cannot
    // be told apart, and the lane treats an unproven movement as something to
    // investigate rather than resend.
    expect(fn () => $service->quote($c['student'], $c['bill'], 5_000_000))
        ->toThrow(ValidationException::class, 'already has a payment with no outcome yet')
        ->and(StudentBillPayment::query()->count())->toBe(1)
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0);

    // Only once the first is proven and allocated is a further payment a
    // legitimate one: its outcome is known and the debt has already moved.
    $service->recordSettlement($payment, billSettlementStub($c, $payment));
    $service->allocate($payment->fresh());
    $c['bill']->refresh();

    $second = $service->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory());
    $consented = $service->consent($c['student'], $c['bill'], 5_000_000, $second['request_key'], $second['request_digest'], true);

    expect($consented->id)->not->toBe($payment->id)
        ->and($consented->hasValidConsent())->toBeTrue()
        ->and(StudentBillPayment::query()->count())->toBe(2)
        ->and($c['bill']->fresh()->remainingAmount())->toBe(145_000_000);
});

test('a bill withdrawn after the payer consented is credited nothing and owes the student the whole arrival', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);
    $service->recordSettlement($payment, billSettlementStub($c, $payment));

    // The registrar withdraws the charge while the transfer is in flight. The
    // bill still has a remaining balance, so the ordinary split would have
    // applied part of the arrival to a charge that no longer exists.
    $c['bill']->update(['status' => StudentBill::STATUS_VOID]);

    $allocated = $service->allocate($payment->fresh());

    expect($allocated->isAllocated())->toBeTrue()
        ->and($allocated->applied_base_units)->toBe(0)
        ->and($allocated->overpayment_base_units)->toBe(5_000_000)
        ->and($allocated->overpayment())->toBe('5.0 USDC')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0)
        ->and($c['bill']->fresh()->status)->toBe(StudentBill::STATUS_VOID);
});

test('a bill that is no longer the paying students is refused and credited nothing', function (): void {
    $c = billPaymentContext();
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, 5_000_000);
    $service->recordSettlement($payment, billSettlementStub($c, $payment));

    // The charge is reassigned to another student after the payer consented. It
    // is not this student's debt, so nothing may be allocated against it.
    $c['bill']->update(['student_id' => Student::factory()->create()->id]);

    expect(fn () => $service->allocate($payment->fresh()))
        ->toThrow(ValidationException::class, 'not this student\'s')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0)
        ->and($c['bill']->fresh()->status)->toBe(StudentBill::STATUS_OPEN)
        ->and($payment->fresh()->allocated_at)->toBeNull()
        ->and($payment->fresh()->applied_base_units)->toBe(0);
});

test('no payer wealth, no category and no classifier confidence grants spend authority', function (
    StudentBillCategory $category,
    int $balance,
    array $advisoryOverrides,
    string $expectedCode,
): void {
    $c = billPaymentContext(['category' => $category, 'balance' => $balance]);
    $service = app(StudentBillPaymentQuote::class);

    $quote = $service->quote($c['student'], $c['bill'], 5_000_000, null, billAdvisory($advisoryOverrides));

    expect($quote['policy_decision'])->toBe('advisory_classification')
        ->and($quote['policy_code'])->toBe($expectedCode)
        ->and($quote['requires_human_approval'])->toBeTrue()
        ->and($quote['authorizes_outgoing_spend'])->toBeFalse()
        ->and($quote['outgoing_spend_assessment_scope'])->toBe(StudentBillPayment::SPEND_ASSESSMENT_SCOPE)
        ->and($quote['eligible_for_unattended_submission'])->toBeFalse();

    $payment = $service->consent($c['student'], $c['bill'], 5_000_000, $quote['request_key'], $quote['request_digest'], true);

    expect($payment->hasValidConsent())->toBeTrue()
        ->and($payment->requires_human_approval)->toBeTrue()
        ->and($payment->authorizesOutgoingSpend())->toBeFalse()
        ->and($payment->isEligibleForUnattendedSubmission())->toBeFalse();
})->with([
    // The wallet balance is the PAYER's own money, so these four say the same
    // thing about spend authority from four directions.
    'a very rich student, a reviewed category, a certain classifier' => [
        StudentBillCategory::LABORATORY, 100_000_000, [], StudentBillPayment::CODE_INBOUND_ADVISORY_ONLY,
    ],
    'a reviewed category with a hesitant classifier' => [
        StudentBillCategory::EXAMINATION, 100_000_000, ['confidence' => 0.4], BillPaymentPolicy::CODE_ADVISORY_CONFIDENCE,
    ],
    'a reviewed category the classifier itself flagged' => [
        StudentBillCategory::LABORATORY, 100_000_000, ['anomaly_flags' => ['recurring_unexpected']], BillPaymentPolicy::CODE_ADVISORY_ANOMALY_FLAGS,
    ],
    'an unreviewed category with a certain classifier' => [
        StudentBillCategory::MISCELLANEOUS, 100_000_000, [], BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED,
    ],
    // A wallet holding exactly the principal plus the ceiling: the old
    // `reserve_protected` check evaluated this student's own funds against the
    // treasury's reserve floor and held the payment for a person as a result.
    'a wallet holding exactly the principal and the fee ceiling' => [
        StudentBillCategory::LABORATORY, 5_029_452, [], StudentBillPayment::CODE_INBOUND_ADVISORY_ONLY,
    ],
]);

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
    // 1.000000 moved, so nothing is stored and nothing may be allocated. This
    // runs on a second bill, because the first bill's consent is now itself an
    // unresolved payment and the lane refuses a second one against it.
    $otherBill = StudentBill::factory()->for($c['student'])->create([
        'amount_base_units' => 5_000_000, 'paid_base_units' => 0, 'status' => StudentBill::STATUS_OPEN,
    ]);

    expect(fn () => $service->quote($c['student'], $c['bill'], 5_000_000))
        ->toThrow(ValidationException::class, 'already has a payment with no outcome yet');

    $other = billConsent(['student' => $c['student'], 'bill' => $otherBill], 5_000_000);
    $hash = billSettlementStub($c, $other, 1_000_000);

    expect(fn () => $service->recordSettlement($other, $hash))
        ->toThrow(ValidationException::class, 'does not establish this exact principal')
        ->and($other->fresh()->settlement_evidence_digest)->toBeNull()
        ->and($other->fresh()->tx_hash)->toBeNull()
        ->and($other->fresh()->tx_verified_on_chain)->toBeFalse()
        ->and(fn () => $service->allocate($other->fresh()))
        ->toThrow(ValidationException::class, 'exact verified movement proof')
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0)
        ->and($otherBill->fresh()->paid_base_units)->toBe(0);
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
        ->and($rehearsal->authorizesOutgoingSpend())->toBeFalse()
        ->and($rehearsal->isEligibleForUnattendedSubmission())->toBeFalse();
});

test('a quote minted before the scope marker existed still re-derives, and still grants nothing', function (): void {
    $legacy = StudentBillPayment::factory()->create();

    // Shaped exactly like a quote mounted before the marker existed: no scope
    // keys, an `auto_approve` verdict and `requires_human_approval = false`.
    // Its digest covers those exact bytes, so tightening the validator to
    // reject it would expire every outstanding consent a student already gave.
    expect($legacy->snapshot)->not->toHaveKey('authorizes_outgoing_spend')
        ->and($legacy->snapshot)->not->toHaveKey('outgoing_spend_assessment_scope')
        ->and($legacy->policy_decision)->toBe('auto_approve')
        ->and($legacy->requires_human_approval)->toBeFalse()
        ->and($legacy->hasValidSnapshot())->toBeTrue()
        ->and($legacy->hasValidConsent())->toBeFalse()
        // The factory's default row carries no consent, so the two layers above
        // the snapshot are correctly absent. What matters is that the snapshot
        // still re-derives, so the row can still be read and still allocated.
        ->and($legacy->consent_digest)->toBeNull()
        // And the verdict it recorded buys nothing: an old row cannot be the
        // one place where an inbound receipt reads as permission to spend.
        ->and($legacy->authorizesOutgoingSpend())->toBeFalse()
        ->and($legacy->isEligibleForUnattendedSubmission())->toBeFalse();
});

test('a quote that claims to authorise outgoing spend cannot be created at all', function (): void {
    // An intact, well-formed row carrying everything the original quote carried.
    // Only the two scope keys and the flag are added, and they contradict each
    // other: nothing here is malformed except the claim of authority.
    $row = StudentBillPayment::factory()->create();
    $snapshot = $row->snapshot;
    $snapshot['policy_decision'] = AgentDecisionType::AUTO_APPROVE->value;
    $snapshot['policy_code'] = BillPaymentPolicy::CODE_AUTO_PAYMENT;
    $snapshot['requires_human_approval'] = false;
    $snapshot['authorizes_outgoing_spend'] = true;
    $snapshot['outgoing_spend_assessment_scope'] = StudentBillPayment::SPEND_ASSESSMENT_SCOPE;

    $attributes = Arr::except($row->getAttributes(), ['id']);

    // The marker is not decorative: a row carrying it and contradicting it is
    // refused, however well-formed the rest of its evidence is.
    expect(fn () => StudentBillPayment::query()->create([
        ...$attributes,
        'request_key' => (string) Str::uuid(),
        'policy_decision' => $snapshot['policy_decision'],
        'policy_code' => $snapshot['policy_code'],
        'requires_human_approval' => false,
        'snapshot' => $snapshot,
        'snapshot_digest' => PaymentIntent::digest($snapshot),
    ]))->toThrow(LogicException::class, 'requires exact intact evidence')
        ->and(StudentBillPayment::query()->count())->toBe(1);
});
