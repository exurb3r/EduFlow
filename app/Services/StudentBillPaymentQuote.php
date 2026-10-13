<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Advisory\AdvisoryEnvelope;
use App\DTOs\BillPolicyDecision;
use App\DTOs\WalletReadState;
use App\Enums\AgentDecisionType;
use App\Enums\StudentBillCategory;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentWallet;
use App\Services\Payments\AsyncPaymentTransport;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

/**
 * Quotes a student's bill payment, records their consent to it, and allocates
 * the resulting movement against the bill.
 *
 * **This service never calls a signer.** It reads a fee, reads a balance and
 * writes evidence. Everything that can move money lives behind the immutable
 * quote and the digest-bound consent, and the only step that touches
 * `student_bills.paid_base_units` ({@see self::allocate()}) refuses unless
 * persisted chain proof says this exact principal moved.
 *
 * The shape deliberately mirrors `StudentTuitionPaymentService`: a server-minted
 * request key, an exact snapshot plus digest captured before any external call,
 * an explicit expiry, and a consent that echoes that digest rather than
 * describing the payment again. The fee arithmetic is the tuition lane's own
 * ({@see StudentTuitionPaymentService::readFee()} and
 * {@see StudentTuitionPaymentService::feeCeiling()}) rather than a second copy,
 * because a ceiling that rounds one way in one lane and the other way in another
 * is how a wallet holding exactly the quoted total becomes a few base units
 * short.
 *
 * ## Why the quote row is a `StudentBillPayment`
 *
 * The tuition lane keeps its quote in `student_tuition_payment_intents`, but
 * that table cannot describe a bill: its `hasValidSnapshot()` re-derives a
 * `tuition_account_id` and a `student_tuition_testnet_demo` purpose, and a bill
 * has neither. Reusing it would mean binding a bill to an unrelated tuition
 * account purely to satisfy a validator. So one row carries the quote, the
 * consent and the settlement, each separately re-derivable and each written
 * once. See `StudentBillPayment` for the three layers.
 *
 * ## A student may always pay what they owe
 *
 * `consent()` is available for every non-void payable bill, whatever the
 * assessment recorded on the quote says. Refusing to let a student settle their
 * own debt because an institutional rule is not satisfied would be the wrong
 * kind of safety.
 *
 * ## The assessment on this quote is triage metadata, never spend authority
 *
 * `BillPaymentPolicy` decides whether the **institution** may release its own
 * funds unattended. This lane is the other direction: a student pays a fee they
 * owe, so evaluating that policy here asked institutional questions with
 * answers the caller does not have — whose balance is the reserve, which vendor
 * has a reviewed destination — and filled the gaps with the payer's own funds
 * and a configured treasury address. That produced `AUTO_APPROVE`,
 * `requires_human_approval = false` and `isEligibleForUnattendedSubmission()`
 * true on a student's receipt: institutional permission to release the
 * treasury, manufactured from a payment that arrives at it.
 *
 * So the policy is **not** evaluated here. What is recorded is an assessment of
 * the classification alone — is there one, is it confident, is it anomaly-free,
 * is the recorded category on the policy's own allow-list — for a bursar
 * triaging the queue, carrying {@see AgentDecisionType::ADVISORY_CLASSIFICATION}
 * and never an approval. The thresholds and the codes still come from the
 * policy, so there is one place they are defined. Every row states
 * `authorizes_outgoing_spend = false` under
 * {@see StudentBillPayment::SPEND_ASSESSMENT_SCOPE}, and PLAN §17.2 correction 4
 * is why that is not negotiable: "an incoming payment still does not approve use
 * of funds".
 *
 * No advisory classification is sought here either. `AdvisoryGate::classifyBill()`
 * prompts a model, and a model must never sit on the critical path of a payment,
 * so the caller may pass a classification that was already produced and
 * sanitized elsewhere. Absent one, the assessment reads `unknown`, which is the
 * only safe direction for a missing classification.
 */
final readonly class StudentBillPaymentQuote
{
    /**
     * How long a mounted quote stays acceptable.
     *
     * Bounded and short on purpose: a quote is a promise about one principal
     * plus one bounded fee, and the longer it lives the more state can drift
     * underneath it before the payer answers. The ceiling is named so the bound
     * can be asserted rather than assumed.
     */
    public const int QUOTE_SECONDS = 120;

    public const int MAX_QUOTE_SECONDS = 300;

    public function __construct(
        private InstallationInstitution $institutions,
        private StudentWalletService $balances,
        private WalletGateway $wallets,
        private ArcNetworkGateway $arc,
        private AsyncPaymentTransport $transport,
        private ArcSettlementVerifier $verifier,
        private BillPaymentPolicy $policy,
        private StudentBillPaymentDispatcher $dispatcher,
        private StudentBillReceiptIntake $intake,
    ) {}

    /**
     * Mount a quote for one bill and one principal, and record it immutably.
     *
     * Passing a `$requestKey` re-reads the stored quote rather than minting a
     * second one, so a retried form returns the terms the student already saw
     * instead of silently re-deriving newer state.
     *
     * @return array<string, mixed>
     */
    public function quote(
        Student $student,
        StudentBill $bill,
        int $amountBaseUnits,
        ?string $requestKey = null,
        ?AdvisoryEnvelope $advisory = null,
    ): array {
        if ($requestKey !== null) {
            $payment = StudentBillPayment::query()->where('request_key', $requestKey)->firstOrFail();
            $this->assertOwner($payment, $student, $bill, $amountBaseUnits);

            return $this->quoteData($payment);
        }

        $this->payableBill($student, $bill, $amountBaseUnits);
        $wallet = $this->payableWallet($student);
        $institution = $this->institutions->require();
        // The destination is configuration, never a form field: a crafted post
        // must not be able to redirect a student's bill payment.
        $recipient = $this->recipientAddress();
        $simulated = $this->isSimulated();

        $this->balances->forget($student);
        $balance = $this->balances->snapshot($student);
        if ($balance->state !== WalletReadState::OK || ! is_string($balance->balance) || $balance->isFake !== $simulated) {
            throw ValidationException::withMessages(['amount_base_units' => 'Wallet balance is unavailable or its driver differs; no payment can be quoted.']);
        }

        // Arc takes gas from the sender on top of the principal, so the fee is
        // priced before the balance is judged. Nothing is charged for a
        // rehearsal, and saying so is accurate rather than a quote.
        $fee = $simulated ? '0.000000' : StudentTuitionPaymentService::readFee($this->transport->estimate([
            'from' => strtolower($wallet->address), 'to' => $recipient,
            'amount_base_units' => $amountBaseUnits, 'chain' => StudentBillPayment::CHAIN,
        ]));
        $feeUnits = StudentTuitionPaymentService::feeCeiling($fee);
        $total = BigInteger::of($amountBaseUnits)->plus($feeUnits)->toInt();
        $available = BigDecimal::of($balance->balance)->withPointMovedRight(6)->toScale(0, RoundingMode::Floor)->toInt();

        if ($total > $available) {
            throw ValidationException::withMessages(['amount_base_units' => 'Wallet cannot cover this principal plus the network fee ceiling.']);
        }

        $assessment = $this->assess($bill, $advisory);
        $snapshot = [
            'schema_version' => 1,
            'purpose' => StudentBillPayment::PURPOSE,
            'plane' => 'pilot',
            'asset' => 'USDC',
            'request_key' => strtolower((string) Str::uuid()),
            'organization_id' => $institution->id,
            'quoted_for' => $student->user_id,
            'student_id' => $student->id,
            'student_bill_id' => $bill->id,
            'student_wallet_id' => $wallet->id,
            // The category the registrar recorded. Advisory confidence does not
            // enter the allow-list check, so a classifier cannot re-label a
            // charge into an unattended one.
            'bill_category' => $bill->category->value,
            'payer_address' => strtolower($wallet->address),
            'recipient_address' => $recipient,
            'amount_base_units' => $amountBaseUnits,
            'quoted_network_fee' => $fee,
            'fee_ceiling_base_units' => $feeUnits,
            'available_base_units' => $available,
            'total_base_units' => $total,
            'chain' => StudentBillPayment::CHAIN,
            'chain_id' => StudentBillPayment::CHAIN_ID,
            'simulated' => $simulated,
            // The assessment below is triage metadata. It is recorded under its
            // own decision type, it authorises nothing, and the row says so in
            // the two keys below so no reader has to infer it from the verdict.
            'policy_decision' => $assessment->decision->value,
            'policy_code' => $assessment->policyCode,
            'requires_human_approval' => true,
            'authorizes_outgoing_spend' => false,
            'outgoing_spend_assessment_scope' => StudentBillPayment::SPEND_ASSESSMENT_SCOPE,
            'quoted_at' => now()->utc()->toIso8601String(),
            'expires_at' => now()->utc()->addSeconds(self::QUOTE_SECONDS)->toIso8601String(),
            'consent_text' => StudentBillPayment::CONSENT_TEXT,
        ];

        return DB::transaction(function () use ($snapshot, $bill, $student, $wallet, $recipient, $simulated, $institution, $amountBaseUnits): array {
            // Re-read the bill under a lock, so two mounts racing for the same
            // bill cannot both conclude there is no earlier payment to wait for.
            $locked = StudentBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $this->assertNoUnresolvedPaymentForBill($locked);

            $payment = StudentBillPayment::query()->create([
                'request_key' => $snapshot['request_key'],
                'organization_id' => $institution->id,
                'quoted_for' => $student->user_id,
                'student_bill_id' => $bill->id,
                'student_id' => $student->id,
                'student_wallet_id' => $wallet->id,
                'amount_base_units' => $amountBaseUnits,
                'payer_address' => $snapshot['payer_address'],
                'recipient_address' => $recipient,
                'chain' => StudentBillPayment::CHAIN,
                'policy_decision' => $snapshot['policy_decision'],
                'policy_code' => $snapshot['policy_code'],
                // Not the assessment's flag: this row is an incoming receipt,
                // and an incoming receipt never releases institutional funds.
                'requires_human_approval' => true,
                'simulated' => $simulated,
                'snapshot' => $snapshot,
                'snapshot_digest' => PaymentIntent::digest($snapshot),
            ]);

            return $this->quoteData($payment);
        }, 3);
    }

    /**
     * Record the payer's acceptance of one exact quote.
     *
     * The client must echo the digest and say yes. Anything else — a different
     * amount, a different bill, an expired quote, a changed wallet, a withdrawn
     * recipient — is a refusal asking for fresh consent, never a silent
     * re-derivation of newer state.
     */
    public function consent(
        Student $student,
        StudentBill $bill,
        int $amountBaseUnits,
        string $requestKey,
        string $expectedDigest,
        bool $accepted,
    ): StudentBillPayment {
        $payment = StudentBillPayment::query()->where('request_key', $requestKey)->firstOrFail();
        $this->assertOwner($payment, $student, $bill, $amountBaseUnits);

        if (! $accepted || ! hash_equals($payment->snapshot_digest, $expectedDigest)) {
            throw ValidationException::withMessages(['consent' => 'Accept the exact mounted quote and bounded costs.']);
        }

        return DB::transaction(function () use ($payment, $student, $bill, $amountBaseUnits): StudentBillPayment {
            Organization::query()->whereKey($payment->organization_id)->lockForUpdate()->firstOrFail();
            $stored = StudentBillPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // One consent, one attempt. A repeat is idempotent when the stored
            // consent still verifies, and a refusal when it does not: a second
            // transfer must not be minted to replace evidence that went bad.
            if ($stored->consent_digest !== null) {
                if (! $stored->hasValidConsent()) {
                    throw ValidationException::withMessages(['consent' => 'Existing consent is invalid; investigate without sending.']);
                }

                return $stored;
            }

            $locked = StudentBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $this->payableBill($student, $locked, $amountBaseUnits);
            $wallet = $this->payableWallet($student);

            if (Carbon::parse($stored->snapshot['expires_at'])->lte(now())
                || $stored->snapshot['simulated'] !== $this->isSimulated()
                || $stored->snapshot['recipient_address'] !== $this->recipientAddress()
                || $wallet->id !== $stored->student_wallet_id
                || strtolower($wallet->address) !== $stored->snapshot['payer_address']) {
                throw ValidationException::withMessages(['consent' => 'Quote expired, or the wallet or rail changed; obtain fresh consent.']);
            }

            $consent = [
                'schema_version' => 1,
                'intent_digest' => $stored->snapshot_digest,
                'confirmed_by' => $stored->quoted_for,
                'confirmed_at' => now()->utc()->toIso8601String(),
                'accepted' => true,
                'fee_ceiling_base_units' => $stored->feeCeilingBaseUnits(),
                'bill_remaining_base_units_at_consent' => $locked->remainingAmount(),
                'consent_text' => StudentBillPayment::CONSENT_TEXT,
            ];

            $stored->update([
                'consent_snapshot' => $consent,
                'consent_digest' => PaymentIntent::digest($consent),
                'consented_at' => now(),
            ]);

            // Registered inside the transaction, so it fires only if the consent
            // actually commits. A rolled-back consent must leave no dispatchable
            // work, and the student must not be left believing a payment is in
            // flight when it never was.
            $this->dispatcher->wakeAfterCommit((int) $stored->getKey());

            return $stored->refresh();
        }, 3);
    }

    /**
     * Prove on chain that this payment moved, and persist the proof once.
     *
     * A returned hash is a claim, not a settlement: the receipt has to show a
     * committed block, successful execution, and the exact sender, recipient and
     * principal this consent authorized. Anything short of that is a refusal
     * with the verifier's own verdict attached, and nothing is written.
     *
     * Idempotent in the same shape as the other steps: a re-run against a row
     * that already holds evidence returns it, or refuses if it no longer
     * verifies. It never re-proves over a different answer.
     */
    public function recordSettlement(StudentBillPayment $payment, string $txHash): StudentBillPayment
    {
        if (! $payment->hasValidConsent() || $payment->simulated) {
            throw ValidationException::withMessages(['settlement' => 'Intact consent is required before movement proof is recorded.']);
        }

        if ($payment->settlement_evidence_digest !== null) {
            if (! $payment->hasValidSettlement()) {
                throw ValidationException::withMessages(['settlement' => 'Stored movement proof no longer verifies; investigate without rewriting it.']);
            }

            return $payment;
        }

        $verdict = $this->verifier->verify([
            'chain' => $payment->snapshot['chain'],
            'chain_id' => $payment->snapshot['chain_id'],
            'sender' => $payment->payer_address,
            'recipient' => $payment->recipient_address,
            'amount_base_units' => $payment->amount_base_units,
            'max_fee_base_units' => $payment->feeCeilingBaseUnits(),
        ], $txHash);

        $receipt = $verdict['receipt'] ?? null;
        // The fee is 18 decimals, so it is carried as the exact string the chain
        // reported. A receipt the rail will not price is unproven, never zero.
        $fee = is_array($receipt) ? $this->actualFee($receipt) : null;

        if (($verdict['verdict'] ?? null) !== 'verified' || $fee === null
            || StudentTuitionPaymentService::feeCeiling($fee) > $payment->feeCeilingBaseUnits()
            || strtolower((string) ($receipt['transactionHash'] ?? '')) !== strtolower($txHash)) {
            throw ValidationException::withMessages([
                'settlement' => 'Chain proof does not establish this exact principal within the accepted fee ceiling. Verdict: '.($verdict['verdict'] ?? 'unreadable').'.',
            ]);
        }

        $evidence = [
            ...$verdict,
            'consent_digest' => $payment->consent_digest,
            'tx_hash' => strtolower($txHash),
            'chain_id' => $payment->snapshot['chain_id'],
            'sender' => $payment->payer_address,
            'recipient' => $payment->recipient_address,
            'amount_base_units' => $payment->amount_base_units,
            'actual_network_fee' => $fee,
        ];

        return DB::transaction(function () use ($payment, $evidence, $fee, $txHash): StudentBillPayment {
            $stored = StudentBillPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($stored->settlement_evidence_digest !== null) {
                if (! $stored->hasValidSettlement()) {
                    throw ValidationException::withMessages(['settlement' => 'Movement proof changed before it could be stored; investigate without rewriting it.']);
                }

                return $stored;
            }

            $stored->update([
                'settlement_evidence' => $evidence,
                'settlement_evidence_digest' => PaymentIntent::digest($evidence),
                'settlement_verified_at' => now(),
                'network_fee' => $fee,
                'tx_hash' => strtolower($txHash),
                'tx_verified_on_chain' => true,
                'executed_at' => now(),
            ]);

            return $stored->refresh();
        }, 3);
    }

    /**
     * Apply a proven payment to its bill. The only step that moves
     * `student_bills.paid_base_units`.
     *
     * Everything here is load-bearing:
     *
     *  - It refuses unless the row still carries intact consent *and* a verified
     *    movement for the exact principal, re-checked under the row lock rather
     *    than trusted from the argument.
     *  - `allocated_at` is the fence. A second call returns the stored row and
     *    credits nothing, so a retried job cannot double-apply.
     *  - The applied amount is derived from what the bill still owed at this
     *    instant. An amount larger than the remainder settles only the
     *    remainder and the excess is recorded as an overpayment, because money
     *    owed back to a student is a liability, not settled debt.
     *  - A withdrawn bill owes nothing, so nothing may be applied to it at all:
     *    the whole arriving amount becomes that overpayment. Crediting a void
     *    charge would resurrect it as settled debt, and `syncStatus()` would
     *    then read a withdrawn bill as paid.
     *  - A bill that is no longer this student's is refused outright rather than
     *    credited for nothing, because a payment against someone else's charge
     *    is a question for a person, not a row to record.
     *  - It writes no `Transaction` and moves no wallet balance. The money moved
     *    when the transfer settled; this only allocates a settlement against a
     *    debt. Counting it twice — as a chain balance and again as a ledger
     *    receipt — is precisely the failure that reconciliation exists to avoid.
     */
    public function allocate(StudentBillPayment $payment): StudentBillPayment
    {
        if (! $payment->hasValidSettlement()) {
            throw ValidationException::withMessages([
                'settlement' => 'Intact consent and exact verified movement proof are required before a bill may be credited.',
            ]);
        }

        return DB::transaction(function () use ($payment): StudentBillPayment {
            Organization::query()->whereKey($payment->organization_id)->lockForUpdate()->firstOrFail();
            $bill = StudentBill::query()->whereKey($payment->student_bill_id)->lockForUpdate()->firstOrFail();
            $stored = StudentBillPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($stored->allocated_at !== null) {
                return $stored;
            }

            if (! $stored->hasValidSettlement() || $stored->student_id !== $bill->student_id) {
                throw ValidationException::withMessages([
                    'settlement' => 'Movement proof changed, or the bill is not this student\'s, before allocation.',
                ]);
            }

            $movementKey = PaymentIntent::digest([
                'chain' => $stored->snapshot['chain_id'],
                'hash' => $stored->tx_hash,
                'sender' => $stored->payer_address,
                'recipient' => $stored->recipient_address,
                'amount' => $stored->amount_base_units,
            ]);

            // One settled movement posts once, under one identity. Two consents
            // for the same transfer would otherwise credit one bill twice.
            if (StudentBillPayment::query()->where('movement_key', $movementKey)->where('id', '!=', $stored->id)->exists()) {
                throw ValidationException::withMessages([
                    'movement' => 'Movement already posted under another consent. Investigate without altering history.',
                ]);
            }

            // A bill the registrar withdrew, after the payer already consented,
            // is the case the arithmetic above would get wrong: it has a
            // remaining balance, so the ordinary split would apply part of the
            // arrival to a charge that no longer exists.
            $applied = $bill->status === StudentBill::STATUS_VOID
                ? 0
                : min($stored->amount_base_units, max(0, $bill->remainingAmount()));

            $stored->update([
                'movement_key' => $movementKey,
                'applied_base_units' => $applied,
                'overpayment_base_units' => $stored->amount_base_units - $applied,
                'allocated_at' => now(),
            ]);

            if ($applied > 0) {
                // The bill's own amount is never touched: once money is applied
                // against it, rewriting the figure is refused by the model, and
                // the correct correction is a void plus a replacement bill.
                $bill->update(['paid_base_units' => BigInteger::of($bill->paid_base_units)->plus($applied)->toInt()]);
                $bill->syncStatus();
            }

            $this->intake->wakeAfterCommit($stored->id);

            return $stored->refresh();
        }, 3);
    }

    /**
     * The institution treasury, read on the server.
     *
     * Never taken from a request, and never synthesised: a rejected address is
     * recoverable, one the college does not control is not.
     */
    public function recipientAddress(): string
    {
        $address = config('lepton.arc.treasury');
        if (! is_string($address) || preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) !== 1) {
            throw ValidationException::withMessages(['treasury' => 'No valid institution treasury is configured.']);
        }

        return strtolower($address);
    }

    /**
     * Whether this process is on a driver that cannot move money.
     *
     * There is no fallback between real and simulated: the value is recorded in
     * the quote, so a rail that changed between quote and consent is a refusal
     * rather than a quiet rehearsal of what looked like a real payment.
     */
    public function isSimulated(): bool
    {
        return config('lepton.default') === 'fake'
            || $this->wallets instanceof FakeLeptonGateway
            || $this->arc instanceof FakeLeptonGateway;
    }

    /**
     * The bill that may be paid: this student's, not withdrawn, and for an
     * amount inside what is still owed.
     *
     * Checked again at consent time under a lock, because the debt may have
     * shrunk between the quote and the answer.
     */
    public function payableBill(Student $student, StudentBill $bill, int $amountBaseUnits): StudentBill
    {
        $this->institutions->require();

        if ($student->user_id < 1 || $bill->student_id !== $student->id || $bill->status === StudentBill::STATUS_VOID
            || $amountBaseUnits <= 0 || $amountBaseUnits > $bill->remainingAmount()) {
            throw ValidationException::withMessages([
                'amount_base_units' => 'A positive amount within this student bill remaining balance is required, and the bill must be payable.',
            ]);
        }

        return $bill;
    }

    /**
     * The wallet the payment would leave, and the only rail permitted to carry
     * it.
     *
     * An active, operator-generated Circle wallet on Arc testnet whose address
     * is still the student's own recorded payout address. A payout address that
     * has been changed since generation is refused rather than followed, so a
     * compromised student record cannot redirect a payment.
     */
    public function payableWallet(Student $student): StudentWallet
    {
        if (config('lepton.arc.chain') !== StudentBillPayment::CHAIN
            || config('lepton.arc.chain_id') !== StudentBillPayment::CHAIN_ID) {
            throw ValidationException::withMessages(['chain' => 'Bill payments permit Arc testnet 5042002 only.']);
        }

        $wallet = $student->wallet;
        if (! $wallet instanceof StudentWallet || $wallet->status !== 'active' || $wallet->provider !== 'circle'
            || $wallet->chain !== StudentBillPayment::CHAIN
            || preg_match(Student::PAYOUT_ADDRESS_PATTERN, (string) $wallet->address) !== 1
            || strcasecmp((string) $student->payout_address, (string) $wallet->address) !== 0) {
            throw ValidationException::withMessages([
                'wallet' => 'An active generated operator-controlled pilot wallet must match the student address.',
            ]);
        }

        return $wallet;
    }

    /**
     * The classification assessment recorded on an incoming receipt.
     *
     * Deliberately **not** `$this->policy->evaluate()`. That policy answers
     * "may the institution release funds unattended", and four of its checks are
     * institutional facts this lane does not have:
     *
     *  - `availableBalanceBaseUnits` / `minimumReserveBaseUnits`. The reserve
     *    floor belongs to the treasury, and the balance reaching this lane is
     *    the *payer's*. Answering an institutional reserve question with a
     *    student's wallet balance is the inverse-direction nonsense this method
     *    exists to remove, so neither is passed at all.
     *  - `hasVerifiedVendorDestination`. There is no vendor: the recipient is
     *    the college's own treasury, which becomes a reviewed vendor
     *    destination only through `VendorDestinationVersion` (PLAN §14.3.5).
     *    A configured treasury address is not one, so this lane never claims a
     *    destination it has not reviewed.
     *  - the per-bill ceiling and the day's unattended outflow cap. Both bound
     *    institutional money leaving unattended; a student's own fee leaves
     *    their own wallet, and it leaves once, against a consent they gave for
     *    that exact amount.
     *
     * What survives is the part that is genuinely about this bill and is useful
     * to a person triaging the queue: is there a classification, is it
     * confident, is it anomaly-free, and is the category the registrar
     * recorded on the policy's own allow-list. The thresholds and the codes
     * come from the policy, so they are still defined in exactly one place, and
     * the order is the policy's own: flags, then absence, then doubt, then
     * category.
     *
     * Every outcome is {@see AgentDecisionType::ADVISORY_CLASSIFICATION} with
     * `approvedAmountBaseUnits` of `0` and `requiresHumanApproval` of `true`.
     * The first says what the value is; the second is a safety property of this
     * method, not a conclusion reached by a check that does not exist here.
     */
    private function assess(StudentBill $bill, ?AdvisoryEnvelope $advisory): BillPolicyDecision
    {
        // Read once, so the assessment states plainly what is known: no
        // classification means nobody classified this bill.
        $classified = $advisory instanceof AdvisoryEnvelope && $advisory->isBillClassification();
        $confidence = $advisory instanceof AdvisoryEnvelope ? $advisory->confidence : null;
        $flags = $advisory instanceof AdvisoryEnvelope ? $advisory->anomalyFlags : [];
        $category = $bill->category->value;
        $floor = $this->policy->advisoryConfidenceFloor();

        $checks = [
            'advisory_present' => $classified,
            'advisory_confident' => $classified && $confidence !== null && $confidence >= $floor,
            'advisory_anomaly_free' => $flags === [],
            // The recorded category only. Advisory confidence never grants a
            // category, exactly as in the policy itself.
            'category_allow_listed' => in_array($category, $this->policy->allowedCategories(), true),
        ];

        if ($flags !== []) {
            return $this->assessment(
                BillPaymentPolicy::CODE_ADVISORY_ANOMALY_FLAGS,
                'The classification raised anomalies ('.implode(', ', $flags).'), so the bill needs a person before anything is done with it.',
                ['Advisory anomaly flags present: '.implode(', ', $flags).'.'], $checks,
            );
        }

        if (! $classified) {
            return $this->assessment(
                BillPaymentPolicy::CODE_ADVISORY_MISSING,
                'Nobody classified this bill. Absence is treated as unknown rather than as permission, so it is flagged for a person.',
                ['No advisory classification was supplied for this bill.'], $checks,
            );
        }

        if (! $checks['advisory_confident']) {
            return $this->assessment(
                BillPaymentPolicy::CODE_ADVISORY_CONFIDENCE,
                'The classification is below the confidence the policy requires ('.number_format($floor, 2, '.', '').'), so it is recorded as unsure.',
                ['Advisory confidence is below the floor of '.number_format($floor, 2, '.', '').'.'], $checks,
            );
        }

        if (! $checks['category_allow_listed']) {
            return $this->assessment(
                BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED,
                "Bill category '{$category}' is not on the policy's reviewed list, so a person should read it.",
                ["Category '{$category}' is not on the reviewed list."], $checks,
            );
        }

        return $this->assessment(
            StudentBillPayment::CODE_INBOUND_ADVISORY_ONLY,
            'The classification is confident, anomaly-free and in a reviewed category. That makes this bill ordinary to triage; it authorises nothing.',
            [], $checks,
        );
    }

    /**
     * Wrap one assessment conclusion.
     *
     * @param  array<string, bool>  $checks
     * @param  list<string>  $violations
     */
    private function assessment(string $policyCode, string $reasoning, array $violations, array $checks): BillPolicyDecision
    {
        return new BillPolicyDecision(
            decision: AgentDecisionType::ADVISORY_CLASSIFICATION,
            approvedAmountBaseUnits: 0,
            requiresHumanApproval: true,
            policyCode: $policyCode,
            reasoning: $reasoning,
            violations: $violations,
            checks: $checks,
        );
    }

    /**
     * Refuse a second quote for a bill whose first payment has no outcome yet.
     *
     * Two consents on one bill is the unknown-outcome hole. The payer has
     * authorised twice, one transfer may be in flight or lost, and the second
     * pair of funds has no evidence of its own — so the outcome of one is
     * guessed from the other. The lane already treats an unproven movement as
     * something to investigate rather than resend; the same has to hold for
     * consent itself.
     *
     * Unresolved means consented and not yet settled, allocated or rehearsed.
     * An allocated payment is finished and its debt has moved, so a genuinely
     * further payment is then legitimate. A rehearsal settles nothing by
     * construction, so it must not wedge the real payment behind it forever.
     *
     * Replay is not a second payment: {@see self::quote()} returns the stored
     * quote when handed the original `request_key`, so a retried form or a
     * double-submitted button reads its own row back rather than minting a new
     * consent.
     */
    private function assertNoUnresolvedPaymentForBill(StudentBill $bill): void
    {
        $unresolved = StudentBillPayment::query()
            ->where('student_bill_id', $bill->id)
            ->whereNotNull('consented_at')
            ->whereNull('settlement_evidence_digest')
            ->whereNull('allocated_at')
            ->where('simulated', false)
            ->exists();

        if ($unresolved) {
            throw ValidationException::withMessages([
                'amount_base_units' => 'This bill already has a payment with no outcome yet. Reopen that quote to check it; a second payment waits until the first is allocated.',
            ]);
        }
    }

    private function assertOwner(StudentBillPayment $payment, Student $student, StudentBill $bill, int $amountBaseUnits): void
    {
        abort_unless(
            $payment->student_id === $student->id
                && $payment->student_bill_id === $bill->id
                && $payment->quoted_for === $student->user_id,
            404,
        );

        if (! $payment->hasValidSnapshot() || $payment->amount_base_units !== $amountBaseUnits) {
            throw ValidationException::withMessages(['consent' => 'Quote binds a different amount or failed integrity checks.']);
        }
    }

    /** @return array<string, mixed> */
    private function quoteData(StudentBillPayment $payment): array
    {
        $s = $payment->snapshot;

        return [
            ...$s,
            'recipient' => $s['recipient_address'],
            'network_fee' => $s['quoted_network_fee'],
            'request_digest' => $payment->snapshot_digest,
            'total' => (string) BigDecimal::of($s['amount_base_units'])->withPointMovedLeft(6)->plus($s['quoted_network_fee']),
            // Read live for display only. Consent is bound to the digest, never
            // to whatever this returns now.
            'remaining_base_units' => StudentBill::query()->findOrFail($payment->student_bill_id)->remainingAmount(),
            'category_label' => StudentBillCategory::from($s['bill_category'])->label(),
            // Read from the row, not from the snapshot, so a quote mounted
            // before the marker existed also answers this honestly.
            'authorizes_outgoing_spend' => $payment->authorizesOutgoingSpend(),
            'outgoing_spend_assessment_scope' => StudentBillPayment::SPEND_ASSESSMENT_SCOPE,
            'eligible_for_unattended_submission' => $payment->isEligibleForUnattendedSubmission(),
        ];
    }

    /**
     * Exact gas cost of a receipt, at Arc's native 18 decimals.
     *
     * `@param array<string, mixed> $receipt
     */
    private function actualFee(array $receipt): ?string
    {
        foreach (['gasUsed', 'effectiveGasPrice'] as $field) {
            if (! is_string($receipt[$field] ?? null) || preg_match('/^0x[0-9a-fA-F]+$/D', $receipt[$field]) !== 1) {
                return null;
            }
        }

        return BigInteger::fromBase(substr($receipt['gasUsed'], 2), 16)
            ->multipliedBy(BigInteger::fromBase(substr($receipt['effectiveGasPrice'], 2), 16))
            ->toBigDecimal()
            ->withPointMovedLeft(18)
            ->toString();
    }
}
