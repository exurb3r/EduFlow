<?php

declare(strict_types=1);

use App\Actions\ReviewVendorPayment;
use App\Models\PaymentSubmissionOutbox;
use App\Services\Payments\AsyncPaymentTransport;
use Brick\Math\BigInteger;
use Mockery\MockInterface;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransferResult;

/**
 * Chain and rail fixtures for the Arc submission and settlement tests.
 *
 * These are shared rather than file-local because the end-to-end workflow test
 * needs the same three things the settlement tests assert against: a chain that
 * answers with a settled receipt, a transport that accepts, and an entry an
 * independent MFA approval actually produced. A helper defined inside one
 * feature file is invisible to the others, so that test would pass only when the
 * whole suite happened to run alongside its donors.
 */

/**
 * @param  array{chain?:string, chain_id?:int, sender?:string, recipient?:string, amount_base_units?:int, max_fee_base_units?:int}  $overrides
 * @return array{chain:string, chain_id:int, sender:string, recipient:string, amount_base_units:int, max_fee_base_units:int}
 */
function verifierExpected(array $overrides = []): array
{
    return $overrides + [
        'chain' => 'ARC-TESTNET',
        'chain_id' => 5042002,
        'sender' => '0x'.str_repeat('1', 40),
        'recipient' => '0x'.str_repeat('2', 40),
        'amount_base_units' => 25_000000,
        'max_fee_base_units' => 1_000000,
    ];
}

function hexQty(int|string|BigInteger $value): string
{
    return '0x'.(is_string($value) ? $value : BigInteger::of($value)->toBase(16));
}

/**
 * @param  array<string, mixed>  $responses
 */
function stubArc(array $responses, string $expectation = 'any'): ArcNetworkGateway&MockInterface
{
    $arc = Mockery::mock(ArcNetworkGateway::class);

    if (! $arc instanceof ArcNetworkGateway) {
        throw new RuntimeException('Mockery did not build an Arc gateway.');
    }

    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    $arc->shouldReceive('rpcUrl')->andReturn('https://rpc.testnet.arc.network');
    mockExpectation($arc, 'explorerUrl')->andReturnUsing(fn (string $hash): string => 'https://explorer.testnet.arc.network/tx/'.$hash);
    mockExpectation($arc, 'rpc')->andReturnUsing(function (string $method, array $params) use ($responses): mixed {
        if (! array_key_exists($method, $responses)) {
            throw new RuntimeException('Unexpected Arc read: '.$method);
        }

        $value = $responses[$method];

        if ($value instanceof Throwable) {
            throw $value;
        }

        return $value;
    });

    app()->instance(ArcNetworkGateway::class, $arc);

    return $arc;
}

/** A committed, successful native-value receipt for the authorized movement. */
function settledReceipt(array $overrides = []): array
{
    return $overrides + [
        'eth_getTransactionReceipt' => [
            'transactionHash' => '0x'.str_repeat('f', 64),
            'blockNumber' => '0x64',
            'status' => '0x1',
            'gasUsed' => hexQty(21000),
            'effectiveGasPrice' => hexQty(1_000000000),
            'logs' => [],
        ],
        'eth_getTransactionByHash' => [
            'from' => '0x'.str_repeat('1', 40),
            'to' => '0x'.str_repeat('2', 40),
            'value' => hexQty(BigInteger::of(25_000000)->multipliedBy(BigInteger::of('1000000000000'))),
            'blockNumber' => '0x64',
        ],
        'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64), 'timestamp' => '0x65'],
    ];
}

/**
 * A committed, successful native-value receipt for an actual owed payment.
 *
 * The chain reads have to describe the movement that was actually authorized,
 * so the fixture is built from the entry rather than from hardcoded values —
 * otherwise the test would pass a predicate that is only ever asked about a
 * payment nobody made.
 */
function settledReceiptFor(PaymentSubmissionOutbox $entry, array $overrides = []): array
{
    $responses = settledReceipt();
    $responses['eth_getTransactionByHash']['from'] = $entry->snapshot['source_address'];
    $responses['eth_getTransactionByHash']['to'] = $entry->snapshot['recipient_address'];
    $responses['eth_getTransactionByHash']['value'] = hexQty(
        BigInteger::of((int) $entry->snapshot['amount_base_units'])->multipliedBy(BigInteger::of('1000000000000')),
    );

    // Gas is priced at the floor so the fee lands under whatever ceiling the
    // reviewer actually approved for this payment. Fee enforcement has its own
    // tests that set the price deliberately.
    $responses['eth_getTransactionReceipt']['effectiveGasPrice'] = '0x1';

    foreach ($overrides as $method => $value) {
        $responses[$method] = $value;
    }

    return $responses;
}

/**
 * @return array{0: PaymentSubmissionOutbox, 1: array<string, mixed>}
 */
function executorEntry(): array
{
    $c = paymentAuthorizationContext();
    $authorization = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], paymentAuthorizationInput($c));

    $entry = PaymentSubmissionOutbox::query()
        ->where('payment_authorization_id', $authorization->id)
        ->firstOrFail();

    return [$entry, $c];
}

function executorGateway(?Throwable $throws = null, bool $fake = false): WalletGateway&MockInterface
{
    $wallets = Mockery::mock(WalletGateway::class);

    if (! $wallets instanceof WalletGateway) {
        throw new RuntimeException('Mockery did not build a wallet gateway.');
    }

    mockExpectation($wallets, 'transfer')->andReturnUsing(
        function (string $from, string $to, int $amount, array $options = []) use ($throws, $fake): TransferResult {
            if ($throws instanceof Throwable) {
                throw $throws;
            }

            return new TransferResult(
                txHash: '0x'.str_repeat('f', 64),
                fromAddress: $from,
                toAddress: $to,
                amountBaseUnits: $amount,
                chain: (string) $options['chain'],
                isFake: $fake,
                explorerUrl: 'https://explorer.testnet.arc.network/tx/'.str_repeat('f', 64),
            );
        },
    );
    $wallets->shouldReceive('balance')->andReturn(new BalanceResult('0x'.str_repeat('1', 40), 100_000000, 'ARC-TESTNET'));

    app()->instance(WalletGateway::class, $wallets);

    return $wallets;
}

/**
 * A transport whose answers the test dictates.
 *
 * Submitting and resolving are supplied separately because the properties under
 * test treat them as different facts: an acceptance is the rail saying "I have
 * it", and a resolution is what the rail says later about whether money moved.
 */
function scriptedTransport(callable $submit, callable $resolve): AsyncPaymentTransport&MockInterface
{
    $transport = Mockery::mock(AsyncPaymentTransport::class);

    if (! $transport instanceof AsyncPaymentTransport) {
        throw new RuntimeException('Mockery did not build a payment transport.');
    }

    mockExpectation($transport, 'submit')->andReturnUsing($submit);
    mockExpectation($transport, 'resolve')->andReturnUsing($resolve);
    $transport->shouldReceive('estimate')->andReturn(['data' => ['medium' => ['networkFee' => '0.0177']]]);
    app()->instance(AsyncPaymentTransport::class, $transport);

    return $transport;
}
