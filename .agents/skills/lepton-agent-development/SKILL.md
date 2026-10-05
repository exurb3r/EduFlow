---
name: lepton-agent-development
description: Build on the Lepton Agent package for Circle agent wallets and the Arc network — transfers, balances, authentication, x402 payments, and offline testing. Use when adding or changing code that moves USDC, reads Arc chain state, authenticates a Circle session, or mocks the Lepton gateways.
---

# Lepton Agent Development

## When to use this skill

Use it when working with `yukazakiri/lepton-agent`: moving USDC, reading Arc chain
state, authenticating a Circle agent wallet, paying x402 services, or writing tests
that must not touch a chain.

## The four gateways

Everything goes through a contract resolved from the container. There is deliberately
no generic `run()` passthrough, so there is never a reason to shell out to `circle`
yourself.

| Contract | Purpose |
|---|---|
| `WalletGateway` | `transfer`, `balance`, `transactions`, `limits` |
| `ArcNetworkGateway` | `rpc`, `blockNumber`, `chainId`, `rpcUrl`, `treasuryAddress`, `explorerUrl`, `addressExplorerUrl` |
| `AuthGateway` | `authStatus`, `beginLogin`, `completeLogin`, `isAuthenticatedFor` |
| `X402Gateway` | `searchServices`, `inspectService`, `payService`, `gatewayBalance` |

```php
use Yukazakiri\Lepton\Contracts\WalletGateway;

$wallets = app(WalletGateway::class);
```

Inject the contract into a service rather than resolving it deep in a call chain, so
the class stays testable.

## Money is integer base units

USDC is 6 decimals. `45.00 USDC` is `45_000000`. Never use a float for a balance or an
amount: `(float) '45.00' * 1000000` is the exact bug class this package exists to
prevent, and a pre-formatted `"24,470.00"` string casts to `24.0`.

```php
use Yukazakiri\Lepton\Support\Amounts;

$units = Amounts::fromDecimalString('45.00');   // 45_000000
$shown = Amounts::toDecimalString($units);      // "45.00"
```

Store and pass base units. Convert for display only, at the edge.

## Never `hexdec()` a chain quantity

Arc native USDC is 18 decimals, so 20 USDC is 2e19 wei — past `PHP_INT_MAX`. `hexdec()`
degrades to a float there and an `(int)` cast silently wraps to a negative number.

```php
$wei = $arc->rpc('eth_getBalance', [$address, 'latest']);
$usdc = Amounts::fromHexQuantity($wei, 18);   // string arithmetic, exact
```

The same applies to block numbers: cast through `Amounts::fromHexQuantity($block, 0)`.

## Transfers

```php
$result = $wallets->transfer(
    fromAddress: $treasury,
    toAddress: $recipient,
    amountBaseUnits: $units,
    options: ['chain' => 'ARC-TESTNET'],
);
```

Options: `chain`, `rpcUrl`, `token`, `idempotencyKey`, `estimate`.

- `estimate` — validates without broadcasting. Use it to preflight.
- `idempotencyKey` — reuse the same key to make a retry safe instead of a double spend.
- `LEPTON_DRY_RUN=true` — applies `estimate` globally.

The facade is convenient but keeps the float risk visible in the call site; prefer
`transfer()` with explicit base units in anything financial.

### Validate the destination before you send

Circle requires `0x` plus **exactly 40 hex characters**. EIP-55 checksum casing is not
required — a lowercase address is fine, a 39-character one is rejected with
`Invalid destination address`.

```php
if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $recipient)) {
    // Refuse locally. Do not rely on the CLI to catch it.
}
```

Never synthesise a recipient from a hash, an id, or a string concatenation. A rejected
address is recoverable; one the recipient does not control is not. If the destination
is missing, escalate to a human rather than inventing one.

## A hash is a claim, not proof

`transfer()` returns a hash. That hash is a claim about settlement, not evidence of it.
Verify before recording a payment as settled:

```php
$onChain = $arc->rpc('eth_getTransactionByHash', [$hash]);
$settled = is_array($onChain) && $onChain !== [];
```

A stored hash that is absent from the chain means the payment never happened. Mark it
failed rather than retrying blindly.

## Two wallet types, and it matters

| Type | Key custody | Circle can sign? |
|---|---|---|
| **Agent** | Circle MPC, email OTP | **Yes** |
| **Local** (arc-canteen) | Your private key | No |

Pointing a treasury at a local wallet is the most common cause of
`No local wallet matches 0x… and no agent session is active`. Assert in a startup
check that the configured address is a Circle agent wallet.

## Authentication

`AuthGateway` wraps Circle's non-interactive two-step OTP flow. The session is owned by
the Circle CLI; the package stores no credentials. Sessions last seven days.

```php
$auth = app(AuthGateway::class);

$status = $auth->authStatus();
$status['testnet']['authenticated'];   // bool
$status['mainnet']['expires_in'];      // "26d 6h 52m"

if (! $auth->isAuthenticatedFor('ARC-TESTNET')) {
    throw new RuntimeException('Log in before transferring on testnet.');
}
```

**Mainnet and testnet are independent.** A valid mainnet session does not authorise
testnet transfers — check the specific chain, not "is there a session".

**Never authenticate from a worker or the settlement path.** The OTP needs a human, so
`beginLogin()` / `completeLogin()` belong in an operator-run command. The request ID
is one-shot and expires in 10 minutes.

## Reading the chain

```php
$arc = app(ArcNetworkGateway::class);

$arc->chainId();      // 5042002 on testnet, 5042 on mainnet
$arc->blockNumber();  // hex string
$arc->explorerUrl($hash);
```

`rpc()` is **allowlisted by the proxy**. `method 'trace_block' not allowed by the proxy`
means the method is not exposed — it is not a bug in the calling code, and retrying
will not help.

## x402 payments

```php
$services = app(X402Gateway::class);

$services->searchServices('exchange rate');
$services->inspectService('https://api.example.com/rates');

$response = $services->payService('https://api.example.com/rates', $payer, [
    'maxAmount' => '0.01',   // hard ceiling; refuses to overpay
]);
```

Always pass `maxAmount`. Without it, a mispriced service can drain more than intended.

## Testing

The `fake` driver needs no network, no credentials and no cost.

```php
config(['lepton.default' => 'fake']);

$wallets = app(WalletGateway::class);
$wallets->seedBalance('0xTreasury', 45_000000);
```

Bind a fresh fake per test. Fakes are stateful, so a shared instance leaks balances
between tests.

```php
$this->app->instance(WalletGateway::class, new FakeLeptonGateway(
    treasuryAddress: '0xTreasury',
    chainCode: 'ARC-TESTNET',
));
```

Always assert `isFake` when it matters. A receipt produced by the fake driver must
never be presented as settled:

```php
expect($result->isFake)->toBeFalse();
```

To test code that checks the chain, bind a stub `ArcNetworkGateway` that knows about
exactly one hash. That is what makes a fabricated receipt fail the test rather than
pass unnoticed.

Bindings are `scoped()`, so they are Octane-safe. Do not cache a gateway on a static
property or a singleton.

## What the package does not wrap

`wallet create`, `fund`, `sign`, `swap`, `execute`, `import`, `bridge`, `contract`,
`earn`, `blockchain config`. Run those through the Circle CLI directly; do not assume
a gateway method exists because the CLI verb does.
