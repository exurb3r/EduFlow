---
paths:
  - 'app/Services/StudentTuitionPayment*.php'
  - 'app/Models/StudentTuitionPayment*.php'
  - 'app/Enums/StudentTuitionPaymentState.php'
  - 'app/Services/Payments/*Transport.php'
  - 'app/Jobs/ProcessStudentTuitionPayment.php'
  - 'app/Console/Commands/DispatchStudentTuitionPayments.php'
  - 'app/Http/Controllers/StudentTuitionPaymentController.php'
  - 'database/migrations/*student_tuition_payment*.php'
  - 'tests/Feature/StudentTuition*.php'
---

# Feature

## On a Circle agent wallet the payer pays NO fee — it is a relayed transfer
Arc pays gas in native USDC, but **a Circle agent wallet transfer is relayed**: the receipt's `from` is a relayer, `to` is the W3S relay contract, `value` is zero, and the payer appears only as the `from` topic of a Transfer log. Circle sponsors the gas, so the payer's total debit is the principal and nothing else.

Measured on three real relayed ARC-TESTNET transfers (treasury → vendor):

| tx | principal | payer's balance delta | receipt fee |
| --- | --- | --- | --- |
| `0x9e358757…` | 30.00 | 30.000000000000000000 | 0.006351425 |
| `0x94411f09…` | 45.00 | 45.000000000000000000 | 0.003811550 |
| `0xcecac1d9…` | 0.01 | 0.010000000000000000 | 0.003185650 |

Delta minus principal is **zero** in all three. The receipt fee is real but it is Circle's cost: a second Transfer log shows the relay contract reimbursing the relayer.

Two consequences that are easy to get backwards:

- **Affordability is `amount <= balance`, not `amount + fee <= balance`.** A previous rule required the wallet to hold nothing beyond the total, which made a normally funded 20 USDC wallet unable to send 5 USDC — it was bounding an account that pays no gas.
- **The receipt fee must never be able to refuse a settlement.** It is not the payer's cost, so comparing it against the payer's accepted ceiling can reject a payment that genuinely settled: the student pays, the debt stands, and nothing repairs it. Record the fee as metadata and gate on *who paid* it.

`FeeGuarantee` exists because of this. `SignerEnforced` (the transaction caps the fee) and `RelayerSponsored` (the rail pays it, so the payer's debit is the principal) are **different facts with different arithmetic**, not a strong and a weak form of one thing. `Unbounded` is refused before the signer, and a transport that does not implement the capability interface is read as `Unbounded`, not as "probably fine".

A fee the rail will not report is a refusal, never a figure: `readFee()` throws on an unreadable, malformed, zero or non-string `networkFee` instead of falling back to `'0.000000'`. A payer told a transfer is free will be charged one.

The recipient is read from `config('lepton.arc.treasury')` on the server and is never taken from the request, so a crafted form cannot redirect tuition. The student is always the authenticated user's own record; the tuition account is looked up on that student's accounts, so another student's account is a 404 rather than an unauthorised write.

## A quote is an immutable consent record, and it binds chain, plane, purpose and expiry
`StudentTuitionPaymentIntent` holds a server-minted `request_key` plus a `snapshot` + `snapshot_digest` captured before any signer could be reached. `hasValidSnapshot()` re-derives every field — organisation, student, account, wallet, payer, recipient, amount, fee ceiling, total, `ARC-TESTNET`, chain id `5042002`, `USDC`, `plane = pilot`, `purpose = student_tuition_testnet_demo`, expiry after `quoted_at` — and refuses a row whose digest does not match. Model events make intents non-updatable and non-deletable.

`pay()` requires the client to echo `request_digest` and send `consent: true`; a mismatch, a different amount, an expired quote, a changed wallet or a changed rail is a refusal, not a fresh attempt. A quote is a promise about ONE principal plus a bounded fee, so it may not be silently re-derived from newer state.

## Only `prepared` may sign; every other recovery path observes, never resends
`submission_started_at` is a write-once fence. It is set inside the claim transaction before the external call, and the model event forbids a started attempt returning to a dispatchable `prepared`. `StudentTuitionPaymentProcessor::process()` branches once: `prepared` → submit; `verified` without `posted_at` → post; `submitting`/`accepted`/`awaiting_verification`/`unknown` → observe. There is no path that turns a non-prepared state into a second transfer, and a queue retry re-reads the stored row rather than trusting the job payload.

A Circle agent wallet returns a transaction **id**, not a hash, so `provider_handle` and `provider_reference` are different columns and a hash-only predicate would read an in-flight payment as a fabricated one. `TransportResolution::pending()` is an ordinary state, not a failure; only a completed resolution may set `provider_reference`, and it must match `/^0x[0-9a-fA-F]{64}$/`. Observation is bounded by `max_observations` and requires an explicit `observe_enabled` flag; exhausting it parks the attempt for a human. If the handle was lost, the attempt goes to operator review and is never re-sent.

## A cap the rail cannot apply must be refused, never silently dropped
`CircleAgentWalletTransport::submit()` throws whenever `max_fee_base_units` is present, because the installed `circle wallet transfer` exposes no fee or gas-limit flag — `maxFeePerGas` only appears in the CLI's *local-wallet* path, never the agent relay. An earlier version threw only when the rail claimed `SignerEnforced`, a condition that could never hold there, so the cap travelled to the CLI and was discarded while the caller believed it had been honoured.

An `--estimate` is a prediction, not a spending limit, so quoting one and then sending unbounded is not a bounded payment. Preflight re-reads the fee and refuses a current quote above the accepted ceiling — but that ceiling bounds the *payer's* loss only on a rail where the payer pays, so `assertLossBounded()` selects the required balance from `FeeGuarantee` rather than always adding the ceiling.

## A signer requires two independent human decisions before it may run
Both `eduflow.student_tuition.enabled` and the absence of `stop_switch` must hold, and `investigation_owner_id` must name a verified-email user holding `finance_officer`/`admin`/`super_admin` who is not the payer. Consent alone is not authority to sign: the owner decides recovery, the payer consents to the bounded transfer, and `AuthGateway::isAuthenticatedFor('ARC-TESTNET')` must already be true. Nothing in this lane ever calls `beginLogin()`/`completeLogin()` — the OTP needs a human.

## Posting is derived from persisted proof, and pilot testnet receipt is not official revenue
`hasValidSettlement()` re-checks the consent digest, the hash, the `verified` verdict, chain id, sender, recipient and exact amount against the stored `settlement_evidence` + digest before anything is written. Posting runs under a row lock, refuses a duplicate `movement_key` or `request_key`, and derives `applied_base_units = min(amount, remaining)` with the remainder as `overpayment_base_units` — an over-limit amount is a liability to the student, not settled debt.

Verified evidence is write-once: the model events refuse a rewrite of `settlement_evidence_digest`, the fee, the verification time or the fence once set. A payment posted after a debt shrinks cannot be rewritten; it is resolved forward.

This lane writes NO `Transaction` row and credits no float treasury. Testnet USDC is not official college tuition, so a pilot receipt is a designated-account allocation only; the legacy float ledger would report it as institutional revenue.

## The fake driver is a rehearsal, and says so
Under `FakeLeptonGateway` the attempt is created directly in state `simulated` with no allocation, no `StudentTuitionPayment`, no `paid_amount` change and no ledger row, and `statusData()` renders `Rehearsal — nothing was sent`. The quote still returns a zero fee for a rehearsal because nothing is charged; that is accurate rather than a quote. The processor's `assertRail()` refuses to sign under the fake driver and refuses a mainnet chain, so no fallback exists between real and simulated.