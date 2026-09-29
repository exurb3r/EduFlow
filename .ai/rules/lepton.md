---
paths:
  - app/Services/Lepton*.php
  - app/Services/Lepton/**/*.php
  - app/Console/Commands/Lepton*.php
  - app/Console/Commands/EduFlowDemo.php
  - database/seeders/**/*Lepton*.php
  - database/seeders/**/*Financial*.php
---

# Lepton / Arc testnet settlement

## The testnet faucet gives 20 USDC per drip, and it rate-limits hard
`circle wallet fund --address <agent-wallet> --chain ARC-TESTNET` ignores `--amount` and
mints exactly **20 USDC** per call. After ~5 drips back to back the faucet returns
`Faucet drip failed (429): API rate limit error`. This is a per-user cap, not a
transient failure — do not retry in a loop.

Consequence: a realistic funded balance is roughly **100–200 USDC**. The seeded demo
scenarios (450 auto-pay, 2500 escalate, 20000 reject) total 3,100 USDC and can therefore
**never** all settle on testnet. Do not "fix" a failing demo settlement by assuming the
wallet is underfunded and topping it up — scale the scenario down, or keep the large
figures as fake-driver only.

## On Arc, USDC is the gas token
A wallet needs USDC to send anything, including a zero-value transfer. An empty agent
wallet cannot pay its own fee, so a "balance is zero" symptom can really be "no gas".

## Testnet and mainnet agent sessions are independent
`php artisan lepton:login --status` reports both. A valid mainnet session does **not**
authorise an ARC-TESTNET transfer. This has produced `no agent session is active` errors
on a machine that was clearly logged in.

## Point the treasury at a Circle agent wallet, not an arc-canteen local wallet
Circle can only sign for wallets it custodies. An `arc-canteen` local wallet holds a key
Circle cannot use, so transfers against it fail even with a valid session. `lepton:doctor`
prints all three addresses (env, database, circle) side by side — a mismatch is the bug.

## Never cast a hex quantity with hexdec()
`hexdec()` returns a **float** past `PHP_INT_MAX`, and an `(int)` cast of that wraps to a
negative number. Native Arc USDC is 18 decimals, so 20 USDC is 2e19 wei — well over the
ceiling. Use `Yukazakiri\Lepton\Support\Amounts::fromHexQuantity()`, which is string
arithmetic. `tests/Feature/LeptonPrecisionTest.php` fails if `hexdec(` reappears in any
chain-reading file.

## A stored transaction hash is a claim, not proof
`lepton:reconcile` is the only thing that can mark a receipt settled. Fabricated hashes
are marked `failed` with `metadata.reconciliation = 'failed'`; `lepton:reconcile --fix`
is idempotent and only touches rows not already reconciled. Never present a
`Transaction` row as settled on the strength of its `provider_tx_hash` alone.

## Never seed a demo with a balance the chain cannot back
The ledger and the chain are separate numbers. A seeded ledger of 24,470 USDC against a
119 USDC on-chain balance means every auto-pay will fail for lack of funds, and the
dashboard will show drift. Seed the ledger to match what the wallet actually holds, or
keep the large figures off-chain only.
