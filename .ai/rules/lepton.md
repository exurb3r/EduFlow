---
paths:
  - 'app/Services/Lepton*.php'
  - 'app/Services/Lepton/**/*.php'
  - 'app/Console/Commands/Lepton*.php'
  - app/Console/Commands/EduFlowDemo.php
  - 'database/seeders/**/*Lepton*.php'
  - 'database/seeders/**/*Financial*.php'
  - app/Services/ArcSettlementVerifier.php
  - app/Services/IsolatedPaymentExecutor.php
  - app/Services/ReservationCapacity.php
  - app/Services/MandateOccurrenceScanner.php
  - app/Services/RecordRetrospectiveAgreement.php
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

## Each demo run spends real USDC and is not repeatable
One `eduflow:demo` cycle moves 85 USDC (two auto-paid invoices plus the approved aid
portion). The faucet will not refill on demand, so plan one run per funding cycle and
use `LEPTON_DRIVER=fake` for iterating. Note that `migrate:fresh --seed` wipes the ledger
but **not** the chain, so wiping after a run leaves an unexplained on-chain gap.

## Never invent a payment destination
A recipient address must come from a real record, never be synthesised from a hash or an
id. The agent used to build `0xstudent_<md5>`, which was 22 hex characters rather than
40 and was rejected by Circle. Circle requires `0x` plus exactly 40 hex digits; EIP-55
checksum casing is *not* required. A malformed or missing address must escalate, never
fall back to a generated one — a rejected address is recoverable, an accepted one the
recipient does not control is not.

## Keep user-facing explanations sourced from live policy
Explanations that restate fixed figures (`AskEduFlow`, dashboard copy, suggested
questions) drift the moment a threshold changes, and then contradict the decision they
are describing. Read the active `AssistancePolicyVersion` and the recorded decision, and
assert in tests that the only USDC figure in a split explanation is the live auto-limit.

## Settlement verdicts are never blame-assigning
Only the `verified` verdict means money moved, and only after successful execution in a committed block with matching sender, recipient and amount. Every other verdict (pending, dropped, not_found, unreadable, mismatched, reverted, fee_exceeded) must keep `settled: false` and `fabricated: false`. Never derive `fabricated` from a null/failed lookup — an unreadable chain is unresolved, not evidence of wrongdoing.

## The executor is the only signer and reports uncertainty honestly
Gated on EDUFLOW_SUBMISSION_ENABLED plus an Arc-testnet-only rail check with no mainnet fallback. A provider timeout or malformed response is `unknown`, never `failed` — a blind resend risks paying twice, so reconcile the existing attempt instead. It decides nothing about which bill, what amount or which recipient; those arrive bound in the outbox snapshot.

## Each hold is verified against its own window
`verify()` resolves every historical hold's funding window from its own approval, never the current window. Judging an old hold by a newer window's re-observed cash would report validly-taken capacity as corrupt whenever a treasury balance moved. Admission for a NEW hold is judged separately, against the current window, in ReserveVendorPayment.

## The autonomous lane stops at a recorded disposition
The scanner is sessionless and holds no payment authority: it creates no PaymentIntent, reserves nothing, never calls a rail, and never passes a fabricated human actor to a staff action. Turning a `release` occurrence into a payment needs explicit institution-scoped service authority, which does not exist yet — do not reach for an Admin user to satisfy a policy check.

## A mandate may only pay bills inside a reviewed closed set
A bill is only a candidate if it appears in a current, valid BudgetSnapshot's bill_ids for the mandate's budget, with a matching snapshot digest. Without this a standing mandate would widen its own reach to every invoice the vendor ever sends — the planner's closed-set rule has to extend to the mandate.

## Retrospective feedback is never an approval
The only 'did the admin agree' measure for the autonomous lane is retrospective: a supervisor is shown a sample of RELEASED occurrences afterwards and records whether they would have decided the same. It is one value per occurrence and append-only, so a rate cannot be inflated or rewritten. MandateRetrospectiveReview::isApproval() returns false by design, and evidence() carries is_retrospective_feedback=true / is_approval=false. Never compute an approval-as-is rate for this lane: that metric needs a human-reviewed original proposal, and an automatic release has none.

## Prove Arc USDC movement by emitter and log, not by transaction envelope
Two chain facts, both verified against the Arc docs and real ARC-TESTNET receipts.

1. Decimals come from the log's emitter, never from the payload shape. The EIP-7708 native system emitter 0xfffffffffffffffffffffffffffffffffffffffe logs USDC at 18 decimals; NativeFiatToken 0x3600000000000000000000000000000000000000 logs at 6. A real 30 USDC transfer logged 30000000000000000000; read as 6 decimals that is 30,000,000,000,000 USDC. Skip emitters of unknown scale rather than guessing.

2. Circle agent wallets do not transact from their own address. Real receipts show from=relayer 0x9ae75f..., to=delegated account 0x0000000071..., value=0. The treasury appears ONLY as the `from` topic of the Transfer log. Never require tx.from == sender; take the sender from whichever stream carries the movement. Requiring it refuses every genuine agent-wallet payment as mismatched.

Also: Arc has deterministic finality. Receipts are immediately authoritative, no reorgs, confirmations=1. The block read is corroborating evidence (block hash, timestamp) and must never gate settlement.

Corroborating streams describe one movement, not a double count. An ERC-20 transfer emits two logs; a plain native send emits a system log plus a non-zero value. Record them all as agreement in matched_interfaces.

## Circle agent-wallet transfers are asynchronous; no sync tx hash exists
`circle wallet transfer` without `--quiet` does NOT broadcast. It returns a fee preview only -- gasLimit, networkFee, networkFeeRaw, baseFee, priorityFee, maxFee -- byte-for-byte the same shape as `--estimate`. Observed on ARC-TESTNET 2026-10-11: the executor recorded `unknown`, and `circle transaction list` afterwards showed no new transaction at all.

With `--quiet`, the CLI returns a transaction ID for agent wallets ("transaction hash for local wallets, transaction ID for agent wallets"). Our wallet is `"type": "agent"`, so that is a UUID like 116c7ce7-8f2f-53ac-8b06-f18aac151ce3, not 0x...

`CircleCliGateway::transfer()` requires txHash and throws without it. That is correct -- it must never record a fake reference -- but it means every real agent-wallet payment ends `unknown`. The working path is: quiet submit -> Circle transaction id -> poll `circle transaction list` until COMPLETE/FAILED -> take txHash -> ArcSettlementVerifier.

Do not 'fix' this by recording the Circle id as if it were a hash, and do not retry a `unknown` by resending: the payment may already have been accepted.
