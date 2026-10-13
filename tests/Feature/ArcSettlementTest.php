<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Jobs\ProcessPaymentSubmission;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionAttempt;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use App\Services\ArcSettlementVerifier;
use App\Services\InstallationInstitution;
use App\Services\IsolatedPaymentExecutor;
use App\Services\PaymentSettlementReconciler;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\TransferResult;

/**
 * The isolated executor and the Arc settlement predicate.
 *
 * These tests exist because the failure they guard against is invisible: a
 * payment system that reports success without money moving looks identical to
 * one that worked, until a vendor is unpaid. So the properties under test are
 * the uncomfortable ones — that a hash alone proves nothing, that a lookup
 * failure is never fabrication, that inclusion is not finality, and that a
 * mismatched amount is a mismatch even when everything else lines up.
 */
// ---------------------------------------------------------------------------
// The settlement predicate
// ---------------------------------------------------------------------------

test('a committed successful receipt with matching movement is the only path to verified', function (): void {
    stubArc(settledReceipt());

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('verified')
        ->and($verdict['settled'])->toBeTrue()
        ->and($verdict['reason'])->toContain('matching sender, recipient and amount');
});

test('a hash that is merely present is not settlement', function (): void {
    // The exact trap: a receipt exists, execution succeeded, but the amount
    // moved is not the amount that was authorized.
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['value'] = hexQty(BigInteger::of(1_000000)->multipliedBy(BigInteger::of('1000000000000')));
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('mismatched')
        ->and($verdict['settled'])->toBeFalse()
        ->and($verdict['reason'])->toContain('No USDC Transfer log and no native value');
});

test('a payment to the wrong recipient is a mismatch even at the right amount', function (): void {
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['to'] = '0x'.str_repeat('9', 40);
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('mismatched')
        ->and($verdict['reason'])->toContain('No USDC Transfer log and no native value')
        ->and($verdict['settled'])->toBeFalse();
});

test('a payment from the wrong treasury is a mismatch', function (): void {
    // The sender is established from whichever stream carries the movement.
    // Here the native `value` names the wrong origin, and with no Transfer log
    // there is nothing left that could authorise it.
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['from'] = '0x'.str_repeat('9', 40);
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('mismatched')
        ->and($verdict['reason'])->toContain('No USDC Transfer log and no native value')
        ->and($verdict['settled'])->toBeFalse();
});

test('a reverted execution is a final failure, not a settlement', function (): void {
    $responses = settledReceipt();
    $responses['eth_getTransactionReceipt']['status'] = '0x0';
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('reverted')
        ->and($verdict['settled'])->toBeFalse()
        // Arc includes a revert irreversibly and spends the nonce, so this is
        // final rather than something to wait on.
        ->and($verdict['reason'])->toContain('nonce is spent');
});

test('an unreadable block read cannot unsettle a payment Arc has already made final', function (): void {
    // Arc documents deterministic finality: receipts are immediately
    // authoritative, there are no reorgs, and confirmation count 1 is correct.
    // So the block read supplies evidence, not the verdict. If it could gate
    // settlement, an RPC hiccup would strand genuinely paid vendors as
    // "unresolved" forever -- the opposite failure from the one this service
    // exists to prevent.
    $responses = settledReceipt();
    $responses['eth_getBlockByNumber'] = new RuntimeException('gateway timeout');
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('verified')
        ->and($verdict['settled'])->toBeTrue()
        // The evidence we could not read is recorded as missing, not invented.
        ->and($verdict['block_hash'])->toBeNull()
        ->and($verdict['block_timestamp'])->toBeNull();
});

test('a transaction known to the chain but not yet in a block is pending, not failed', function (): void {
    stubArc([
        'eth_getTransactionReceipt' => null,
        'eth_getTransactionByHash' => ['from' => '0x'.str_repeat('1', 40), 'blockNumber' => null],
    ]);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('pending')
        ->and($verdict['settled'])->toBeFalse()
        ->and($verdict['reason'])->toContain('may still settle');
});

test('a transaction claiming a block but carrying no receipt is dropped, not settled', function (): void {
    stubArc([
        'eth_getTransactionReceipt' => null,
        'eth_getTransactionByHash' => ['from' => '0x'.str_repeat('1', 40), 'blockNumber' => '0x64'],
    ]);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('dropped')
        ->and($verdict['settled'])->toBeFalse()
        ->and($verdict['fabricated'])->toBeFalse();
});

test('a missing hash is never reported as fabrication', function (): void {
    // This is the defect the predicate exists to replace: an RPC that cannot
    // answer is not evidence that nobody was paid.
    stubArc(['eth_getTransactionReceipt' => null, 'eth_getTransactionByHash' => null]);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('not_found')
        ->and($verdict['settled'])->toBeFalse()
        ->and($verdict['fabricated'])->toBeFalse()
        ->and($verdict['reason'])->toContain('Absence is not evidence of fabrication')
        ->and($verdict['requires_investigation'])->toBeTrue();
});

test('an unreadable chain is unknown rather than a verdict', function (): void {
    stubArc(['eth_getTransactionReceipt' => new RuntimeException('gateway timeout')]);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('unreadable')
        ->and($verdict['settled'])->toBeFalse()
        ->and($verdict['fabricated'])->toBeFalse()
        ->and($verdict['reason'])->toContain('unknown and requires investigation');
});

test('a malformed provider reference is refused before any chain read', function (): void {
    // No stub is registered: reaching the chain at all is the failure.
    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), 'not-a-hash');

    expect($verdict['verdict'])->toBe('not_found')
        ->and($verdict['settled'])->toBeFalse()
        ->and($verdict['reason'])->toContain('not a transaction hash');
});

test('an ERC-20 Transfer log settles a payment carried on the token interface', function (): void {
    // Arc exposes the same USDC through a native 18-decimal balance and a
    // 6-decimal ERC-20 interface; either may carry the authorized amount.
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['to'] = '0x'.str_repeat('7', 40);
    $responses['eth_getTransactionByHash']['value'] = '0x0';
    // An ERC-20 transfer really does emit two logs -- the 18-decimal system
    // event and the 6-decimal ERC-20 event -- so the fixture does too.
    $responses['eth_getTransactionReceipt']['logs'] = [
        [
            'address' => '0xfffffffffffffffffffffffffffffffffffffffe',
            'topics' => [
                '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                '0x000000000000000000000000'.str_repeat('1', 40),
                '0x000000000000000000000000'.str_repeat('2', 40),
            ],
            'data' => hexQty(BigInteger::of(25_000000)->multipliedBy(BigInteger::of('1000000000000'))),
        ],
        [
            'address' => '0x3600000000000000000000000000000000000000',
            'topics' => [
                '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                '0x000000000000000000000000'.str_repeat('1', 40),
                '0x000000000000000000000000'.str_repeat('2', 40),
            ],
            'data' => hexQty(BigInteger::of(25_000000)),
        ],
    ];
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('verified')
        ->and($verdict['settled'])->toBeTrue()
        ->and($verdict['matched_interfaces'])->toContain('erc20_log')
        ->and($verdict['matched_interfaces'])->toContain('native_system_log');
});

test('a native send carrying both a value and a system log is one movement, not a double count', function (): void {
    // A plain native send on Arc carries a non-zero `value` *and* emits an
    // 18-decimal system Transfer log. Refusing to settle that would record a
    // correctly paid vendor as mismatched -- an accusation, on real money.
    $responses = settledReceipt();
    $responses['eth_getTransactionReceipt']['logs'] = [[
        'address' => '0xfffffffffffffffffffffffffffffffffffffffe',
        'topics' => [
            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
            '0x000000000000000000000000'.str_repeat('1', 40),
            '0x000000000000000000000000'.str_repeat('2', 40),
        ],
        'data' => hexQty(BigInteger::of(25_000000)->multipliedBy(BigInteger::of('1000000000000'))),
    ]];
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('verified')
        ->and($verdict['settled'])->toBeTrue()
        ->and($verdict['matched_interfaces'])->toContain('native_value')
        ->and($verdict['matched_interfaces'])->toContain('native_system_log');
});

test('a Transfer log from an emitter of unknown scale is skipped rather than guessed at', function (): void {
    // Reading an unknown-scale payload with the wrong decimals is how a real
    // settlement becomes a mismatch. Unknown means unknown.
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['to'] = '0x'.str_repeat('7', 40);
    $responses['eth_getTransactionByHash']['value'] = '0x0';
    $responses['eth_getTransactionReceipt']['logs'] = [[
        'address' => '0x00000000000000000000000000000000deadbeef',
        'topics' => [
            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
            '0x000000000000000000000000'.str_repeat('1', 40),
            '0x000000000000000000000000'.str_repeat('2', 40),
        ],
        'data' => hexQty(BigInteger::of(25_000000)),
    ]];
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('mismatched')
        ->and($verdict['settled'])->toBeFalse();
});

test('a fee above the approved ceiling is not a settlement', function (): void {
    // 2e9 gas at 1 gwei is 2e18 wei, i.e. 2 USDC — over the 1 USDC ceiling
    // the reviewer approved.
    stubArc(settledReceipt([
        'eth_getTransactionReceipt' => [
            'transactionHash' => '0x'.str_repeat('f', 64),
            'blockNumber' => '0x64',
            'status' => '0x1',
            'gasUsed' => hexQty(2_000000000),
            'effectiveGasPrice' => hexQty(1_000000000),
            'logs' => [],
        ],
    ]));

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(['max_fee_base_units' => 1_000000]), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('fee_exceeded')
        ->and($verdict['settled'])->toBeFalse()
        ->and($verdict['reason'])->toContain('exceeded the approved ceiling');
});

test('a native interface amount is read at eighteen decimals, not truncated', function (): void {
    // 25.000001 USDC must not be rounded to 25.00 and accepted.
    $expected = verifierExpected(['amount_base_units' => 25_000001]);
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['value'] = hexQty(BigInteger::of(25_000000)->multipliedBy(BigInteger::of('1000000000000')));
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify($expected, '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('mismatched')
        ->and($verdict['settled'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// The isolated executor
// ---------------------------------------------------------------------------

test('the executor refuses to run while the submission runtime is off', function (): void {
    [$entry] = executorEntry();
    executorGateway();
    config(['eduflow.submission.enabled' => false]);

    expect(fn () => app(IsolatedPaymentExecutor::class)->submit($entry))
        ->toThrow(ValidationException::class, 'Payment submission runtime is disabled');
});

test('the executor refuses anything but the explicitly configured Arc testnet rail', function (): void {
    [$entry] = executorEntry();
    executorGateway();
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);
    $entry->snapshot = ['chain' => 'ARC-MAINNET', 'chain_id' => 12345] + $entry->snapshot;

    expect(fn () => app(IsolatedPaymentExecutor::class)->submit($entry))
        ->toThrow(ValidationException::class, 'mainnet remains blocked');
});

test('the executor refuses a destination address that is not a usable address', function (): void {
    [$entry] = executorEntry();
    executorGateway();
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);
    $entry->snapshot = ['recipient_address' => '0xnothex'] + $entry->snapshot;

    expect(fn () => app(IsolatedPaymentExecutor::class)->submit($entry))
        ->toThrow(ValidationException::class, 'not a usable address');
});

test('the stop switch holds submission without erasing the owed payment', function (): void {
    [$entry] = executorEntry();
    executorGateway();
    config(['eduflow.submission.enabled' => true, 'eduflow.submission.stop_switch' => true]);

    expect(fn () => app(IsolatedPaymentExecutor::class)->submit($entry))
        ->toThrow(ValidationException::class, 'stop switch is engaged')
        ->and($entry->fresh()->state)->toBe('queued')
        ->and(Transaction::query()->count())->toBe(0);
});

test('the fee preflight validates without broadcasting a write', function (): void {
    [$entry] = executorEntry();

    $seen = [];
    $wallets = Mockery::mock(WalletGateway::class);

    if (! $wallets instanceof WalletGateway) {
        throw new RuntimeException('Mockery did not build a wallet gateway.');
    }

    mockExpectation($wallets, 'transfer')->andReturnUsing(
        function (string $from, string $to, int $amount, array $options = []) use (&$seen): TransferResult {
            $seen = $options;

            return new TransferResult('0x'.str_repeat('f', 64), $from, $to, $amount, 'ARC-TESTNET');
        },
    );
    app()->instance(WalletGateway::class, $wallets);

    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);

    app(IsolatedPaymentExecutor::class)->estimate($entry);

    expect($seen['estimate'])->toBeTrue()
        ->and($seen['idempotencyKey'])->toBe($entry->provider_idempotency_key)
        ->and($seen['chain'])->toBe('ARC-TESTNET');
});

test('an uncertain provider response is unknown, never a failure', function (): void {
    [$entry] = executorEntry();
    executorGateway(new RuntimeException('read: timeout'));
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);

    $outcome = app(IsolatedPaymentExecutor::class)->submit($entry);

    // A blind retry here risks paying twice, so the executor reports the
    // uncertainty rather than resolving it on the caller's behalf.
    expect($outcome['outcome'])->toBe('unknown')
        ->and($outcome['provider_reference'])->toBeNull()
        ->and($outcome['detail']['provider_exception'])->toBeTrue();
});

test('a simulated transfer is never submitted as a network movement', function (): void {
    [$entry] = executorEntry();
    executorGateway(fake: true);
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'fake']);

    $outcome = app(IsolatedPaymentExecutor::class)->submit($entry);

    expect($outcome['outcome'])->toBe('blocked')
        ->and($outcome['detail']['is_fake'])->toBeTrue();
});

test('a submitted reference is recorded as submitted, never as settled', function (): void {
    [$entry] = executorEntry();
    executorGateway();
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);

    (new ProcessPaymentSubmission($entry->id))
        ->handle(app(IsolatedPaymentExecutor::class));

    $entry->refresh();

    expect($entry->state)->toBe('submitted')
        ->and($entry->stage)->toBe('awaiting_settlement_verification')
        ->and($entry->result['settled'])->toBeFalse()
        ->and($entry->result['can_execute'])->toBeFalse()
        ->and($entry->result['provider_reference'])->toBe('0x'.str_repeat('f', 64));

    $attempt = PaymentSubmissionAttempt::query()
        ->where('payment_submission_outbox_id', $entry->id)
        ->firstOrFail();

    expect($attempt->outcome)->toBe('submitted')
        ->and($attempt->provider_idempotency_key)->toBe($entry->provider_idempotency_key)
        ->and(Transaction::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Reconciliation: the only path from a reference to a settlement
// ---------------------------------------------------------------------------

test('only a verified verdict completes an entry, and the hold is what releases capacity', function (): void {
    [$entry] = executorEntry();
    executorGateway();
    stubArc(settledReceiptFor($entry));
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry->fresh());

    expect($verdict['settled'])->toBeTrue()
        ->and($entry->fresh()->state)->toBe('completed')
        ->and($entry->fresh()->stage)->toBe('verified_settled')
        ->and($entry->fresh()->result['can_execute'])->toBeFalse();
});

test('an unresolved verdict keeps the entry owed and holds its capacity', function (): void {
    [$entry, $c] = executorEntry();
    executorGateway();
    stubArc(['eth_getTransactionReceipt' => null, 'eth_getTransactionByHash' => ['blockNumber' => null]]);
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry->fresh());

    expect($verdict['verdict'])->toBe('pending')
        ->and($entry->fresh()->state)->toBe('unknown')
        ->and($entry->fresh()->result['fabricated'])->toBeFalse()
        ->and($c['hold']->fresh()->isReleased())->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0);
});

test('an entry with no provider reference is not verifiable and never settles', function (): void {
    [$entry] = executorEntry();
    stubArc(settledReceipt());

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry);

    expect($verdict['verdict'])->toBe('not_applicable')
        ->and($verdict['settled'])->toBeFalse()
        ->and($entry->fresh()->state)->toBe('queued');
});

test('a fee above the approved ceiling leaves the entry owed end to end', function (): void {
    // Guards the reconciler path specifically: a verdict that says "verified"
    // while silently under-reading the fee would complete an entry the
    // reviewer never actually authorised at that cost.
    [$entry] = executorEntry();
    executorGateway();

    $ceiling = (int) $entry->snapshot['max_fee_base_units'];
    $responses = settledReceiptFor($entry);
    $responses['eth_getTransactionReceipt']['effectiveGasPrice'] = hexQty(1_000000000);
    stubArc($responses);

    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry->fresh());

    expect($ceiling)->toBe(2)
        ->and($verdict['verdict'])->toBe('fee_exceeded')
        ->and($verdict['settled'])->toBeFalse()
        ->and($entry->fresh()->state)->toBe('unknown')
        ->and($entry->fresh()->stage)->toBe('unverified_fee_exceeded')
        ->and(app(PaymentSettlementReconciler::class)->mirrorSettlement($entry->fresh(), $verdict))->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);
});

test('a mirrored settlement records the full six-decimal amount without rounding', function (): void {
    // The intent pays 25.000001 USDC. A 2-decimal ledger column would render
    // this as 25.00 — a figure the chain never confirmed.
    [$entry] = executorEntry();
    executorGateway();
    stubArc(settledReceiptFor($entry));

    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));

    $entry->refresh();
    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry);
    $mirror = app(PaymentSettlementReconciler::class)->mirrorSettlement($entry, $verdict);

    expect((int) $entry->snapshot['amount_base_units'])->toBe(25_000001)
        ->and($mirror)->not->toBeNull()
        ->and($mirror->metadata['amount_base_units'])->toBe('25000001')
        // The integer in metadata is the authoritative amount: the column is
        // a convenience view, and SQLite stores a `decimal` as a double, so
        // only the integer is exact everywhere. This asserts the figure
        // rounds to 25.000001 rather than 25.00 — the rounding the old
        // 2-decimal column would have applied.
        ->and(round((float) DB::table('transactions')->where('id', $mirror->id)->value('amount'), 6))->toBe(25.000001);
});

test('a mirrored settlement records the exact amount and never settles the local payable', function (): void {
    [$entry] = executorEntry();
    executorGateway();
    stubArc(settledReceiptFor($entry));
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'synchronous']);

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));

    $entry->refresh();
    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry);
    $mirror = app(PaymentSettlementReconciler::class)->mirrorSettlement($entry, $verdict);

    expect($mirror)->not->toBeNull()
        ->and($mirror->type)->toBe(TransactionType::VENDOR_PAYMENT)
        ->and($mirror->metadata['settles_local_payable'])->toBeFalse()
        ->and($mirror->metadata['local_accounts_changed'])->toBeFalse()
        ->and($mirror->provider_tx_hash)->toBe('0x'.str_repeat('f', 64))
        ->and($mirror->network)->toBe('ARC-TESTNET')
        ->and(PaymentIntent::query()->whereKey($entry->payment_intent_id)->exists())->toBeTrue();
});

test('an unreachable rail leaves the payment unknown and never settled or completed', function (): void {
    // The live testnet run is blocked on this machine because the Circle CLI is
    // not installed. That is exactly the condition this covers: the provider
    // cannot answer, and the honest result is "unknown, reconcile me" -- not
    // submitted, not failed, and above all not completed.
    Organization::factory()->create();
    $wallets = Mockery::mock(WalletGateway::class);

    if (! $wallets instanceof WalletGateway) {
        throw new RuntimeException('Mockery did not build a wallet gateway.');
    }

    mockExpectation($wallets, 'transfer')->andThrow(new RuntimeException('circle: command not found'));
    $executor = new IsolatedPaymentExecutor($wallets, app(InstallationInstitution::class));

    $entry = new PaymentSubmissionOutbox(['request_key' => (string) Str::uuid()]);
    $entry->snapshot = [
        'chain' => 'ARC-TESTNET', 'chain_id' => 5042002,
        'source_address' => '0x'.str_repeat('1', 40),
        'recipient_address' => '0x'.str_repeat('2', 40),
        'amount_base_units' => '2000000',
    ];
    $entry->provider_idempotency_key = 'eduflow:test';

    config(['eduflow.submission.enabled' => true, 'eduflow.submission.stop_switch' => false, 'lepton.default' => 'circle']);

    $outcome = $executor->submit($entry);

    expect($outcome['outcome'])->toBe('unknown')
        ->and($outcome['provider_reference'])->toBeNull()
        ->and($outcome['detail']['provider_exception'])->toBeTrue()
        // No money moved, so nothing downstream may claim otherwise.
        ->and(Transaction::query()->count())->toBe(0)
        ->and(PaymentSubmissionOutbox::query()->where('state', 'completed')->count())->toBe(0)
        ->and(PaymentSubmissionOutbox::query()->where('state', 'submitted')->count())->toBe(0);
});

test('a relayed agent-wallet transfer settles from its Transfer log, not the envelope', function (): void {
    // Observed on real ARC-TESTNET receipts from Circle agent wallets: the
    // transaction is submitted by a relayer against a delegated account, so
    // `from` is the relayer, `to` is the delegate and `value` is zero. The
    // treasury only appears as the `from` topic of the system Transfer log.
    // Requiring the envelope's sender to equal the treasury would refuse every
    // genuine agent-wallet payment while proving nothing about the money.
    $relayer = '0x9ae75fa838fcce70bb2def05f9d6595643bf0d91';
    $delegate = '0x0000000071727de22e5e9d8baf0edac6f37da032';

    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['from'] = $relayer;
    $responses['eth_getTransactionByHash']['to'] = $delegate;
    $responses['eth_getTransactionByHash']['value'] = '0x0';
    $responses['eth_getTransactionReceipt']['logs'] = [[
        'address' => '0xfffffffffffffffffffffffffffffffffffffffe',
        'topics' => [
            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
            '0x000000000000000000000000'.str_repeat('1', 40),
            '0x000000000000000000000000'.str_repeat('2', 40),
        ],
        'data' => hexQty(BigInteger::of(25_000000)->multipliedBy(BigInteger::of('1000000000000'))),
    ]];
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('verified')
        ->and($verdict['settled'])->toBeTrue()
        ->and($verdict['matched_interfaces'])->toBe(['native_system_log']);
});

test('a relayer cannot launder someone else\'s transfer into an authorized settlement', function (): void {
    // The log's `from` topic is what authorizes the sender. If it names
    // somebody other than the treasury, the movement is not ours no matter who
    // submitted the transaction.
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['from'] = '0x9ae75fa838fcce70bb2def05f9d6595643bf0d91';
    $responses['eth_getTransactionByHash']['to'] = '0x0000000071727de22e5e9d8baf0edac6f37da032';
    $responses['eth_getTransactionByHash']['value'] = '0x0';
    $responses['eth_getTransactionReceipt']['logs'] = [[
        'address' => '0xfffffffffffffffffffffffffffffffffffffffe',
        'topics' => [
            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
            '0x000000000000000000000000'.str_repeat('9', 40),
            '0x000000000000000000000000'.str_repeat('2', 40),
        ],
        'data' => hexQty(BigInteger::of(25_000000)->multipliedBy(BigInteger::of('1000000000000'))),
    ]];
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('mismatched')
        ->and($verdict['settled'])->toBeFalse();
});

test('a six-decimal payload at the system emitter cannot inflate a bill a millionfold', function (): void {
    // 25 USDC is 25e18 native units; read as 6-decimal base units that is
    // 25,000,000 USDC. The emitter's scale is the only thing standing between a
    // real settlement and a fabricated one, so this pins that the system
    // emitter is interpreted at 18.
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['from'] = '0x9ae75fa838fcce70bb2def05f9d6595643bf0d91';
    $responses['eth_getTransactionByHash']['value'] = '0x0';
    $responses['eth_getTransactionReceipt']['logs'] = [[
        'address' => '0xfffffffffffffffffffffffffffffffffffffffe',
        'topics' => [
            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
            '0x000000000000000000000000'.str_repeat('1', 40),
            '0x000000000000000000000000'.str_repeat('2', 40),
        ],
        'data' => hexQty(BigInteger::of(25_000000)),
    ]];
    stubArc($responses);

    $verdict = app(ArcSettlementVerifier::class)->verify(verifierExpected(), '0x'.str_repeat('f', 64));

    expect($verdict['verdict'])->toBe('mismatched')
        ->and($verdict['settled'])->toBeFalse();
});
