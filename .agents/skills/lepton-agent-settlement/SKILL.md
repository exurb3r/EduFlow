---
name: lepton-agent-settlement
description: Prove that USDC payments actually settled on Arc, and design code that cannot mistake a stored transaction hash for proof. Use when recording payments, reconciling a ledger against the chain, handling transfer failures, or auditing whether a disbursement really moved money.
---

# Lepton Agent Settlement

## When to use this skill

Use it when a payment is recorded, when a ledger is compared to a chain balance, when a
transfer fails partway, or when anyone asks whether a disbursement really happened.

## The core rule

**A transaction hash in your database is a claim, not proof.**

`transfer()` returns a hash after the CLI reports success. That is evidence the Circle
API accepted a request — not that a transaction exists at that hash. The two diverge
whenever a request is fabricated, when a fake driver is left enabled, or when a hash is
copied from elsewhere.

Treat every stored hash as unverified until the chain has confirmed it.

## Proving a single payment

```php
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

$arc = app(ArcNetworkGateway::class);

$tx = $arc->rpc('eth_getTransactionByHash', [$hash]);
$settled = is_array($tx) && $tx !== [];
```

A missing or `null` result from a reachable chain means the payment **never settled**.
Do not retry the business logic on the assumption of a transient failure.

If the RPC is unreachable, that is a different verdict — "unverifiable", not
"fabricated". Never let a network outage mark good payments as failed. Fall back to
Circle's own wallet history:

```php
$known = collect($wallets->transactions($address))
    ->pluck('txHash')
    ->all();
```

Only treat a hash as absent when the chain was actually reachable and did not know it.

## The three verdicts

Distinguish them, because they call for different responses:

| Verdict | Meaning | Response |
|---|---|---|
| **verified** | Found on chain | Record as settled |
| **fabricated** | Chain reachable, hash absent | Record as failed, investigate |
| **unverifiable** | Could not reach the chain | Retry the check later, decide nothing |

A ledger-only credit with no hash at all is neither success nor failure — it is an
internal accounting entry, and it never moved USDC.

## Recording outcomes honestly

Store the verdict alongside the receipt, not just the status:

```php
$transaction->update([
    'status' => TransactionStatus::FAILED,
    'metadata' => array_merge($transaction->metadata, [
        'reconciliation' => 'failed',
        'reconciled_at' => now()->toIso8601String(),
        'reconciliation_note' => 'Hash absent from chain; receipt never settled.',
    ]),
]);
```

Make the fix **idempotent**. A `--fix` style command that re-stamps rows it already
reconciled will churn timestamps and make it impossible to tell what changed. Reject or
skip rows already carrying `reconciliation = 'failed'`.

When a command reports that something needs fixing, only say so when it actually does.
Printing "run with --fix" underneath your own success message trains people to ignore
the output.

## Never fabricate a destination

A payment address must come from a real record.

A destination synthesised from a hash, an id, or string concatenation produces
addresses that are either rejected by Circle or, worse, accepted. Circle requires
`0x` plus exactly 40 hex characters — checksum casing is irrelevant, length is not.

```php
if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $recipient)) {
    throw new InvalidArgumentException('Payout address is not a valid 0x address.');
}
```

When a recipient address is missing, **escalate to a human**. Do not generate one. A
rejected address is recoverable; money sent to an address nobody controls is not.

## Insufficient funds means the ledger lied

`the asset amount owned by the wallet is insufficient for the transaction` almost
always means the recorded balance is fiction — a seeded or assumed figure the chain
does not support.

A deterministic policy engine that checks a *database* balance will happily approve a
payment the chain will refuse. The engine is authoritative about *policy*; the chain is
authoritative about *funds*. Both must agree before a disbursement is attempted.

Surface the drift rather than hiding it:

```php
$drift = $ledgerBalance - $onchainBalance;
```

## Gas is the same token

On Arc, USDC is the gas token. A wallet needs USDC to send anything at all, including
a zero-value transfer. "My balance reads zero" is sometimes really "no gas", and a
wallet funded only in a non-native token cannot broadcast.

## Preflight before you commit

Use the `estimate` option to learn the real fee without broadcasting:

```php
$wallets->transfer($from, $to, $units, ['estimate' => true]);
```

Set `LEPTON_DRY_RUN=true` in non-production to make every transfer estimate-only.

## Test the failure path

The bug this guards against is invisible unless you test for it. Bind a stub chain that
knows about **exactly one** hash, and assert the others come back fabricated:

```php
$arc = new class($knownHash) implements ArcNetworkGateway {
    public function rpc(string $method, array $params = []): mixed
    {
        if ($method !== 'eth_getTransactionByHash') {
            return null;
        }

        return $params[0] === $this->knownHash
            ? ['blockNumber' => '0x3d667a7', 'hash' => $this->knownHash]
            : null;
    }
    // ... remaining interface methods
};
```

A reconciliation test that cannot produce a "fabricated" verdict is not testing
anything.
