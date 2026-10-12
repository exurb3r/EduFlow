---
paths:
  - 'app/Services/StudentTuitionPaymentService.php, app/Models/StudentTuitionPayment*.php, app/Http/Controllers/StudentTuitionPaymentController.php, tests/Feature/StudentTuitionPayment*.php'
---

# Feature

## Arc tuition payments: the fee comes out of the sender, goes to the network
Arc pays gas in NATIVE USDC, so a transfer's fee is taken from the SENDER's wallet on top of the amount, and it goes to the Arc network's validators — not to Circle, not to the institution treasury, and not to EduFlow. The recipient receives exactly the amount. A wallet therefore holds amount + fee or it cannot send: the payment service quotes the fee first and refuses an amount the balance cannot cover once the fee is added.

The fee is denominated at 18 decimals, so it is NEVER stored as an integer of base units or passed through a float. It is carried as an exact decimal string from `networkFee` in the transfer's raw CLI payload (falling back to the pre-flight `--estimate` quote), and StudentTuitionPayment.network_fee is a string column for that reason. Fees of ~0.03 USDC overflow 6-decimal base-unit arithmetic in neither direction — either loses precision.

The recipient is read from `config('lepton.arc.treasury')` (LEPTON_TREASURY_ADDRESS) on the server and is never taken from the request, so a crafted form cannot redirect tuition. The student is always the authenticated user's own record, resolved through the `student()` relation; the tuition account is looked up on that student's accounts, so another student's account is a 404 rather than an unauthorised write.

A returned tx hash is a claim, not proof. The payment is verified against the chain before the row is written, and the amount plus the fee are recorded only after the chain confirms it. The predicate is `ArcSettlementVerifier` — the same class the outgoing lane uses — not a weaker copy: a receipt that exists, a `status` of `0x1`, or a hash that merely appears on chain all still leave the amount unproven.

## One durable identity per attempt; the receipt is posted exactly once
`StudentTuitionPaymentService::pay()` takes a `requestKey` minted by the server (`StudentTuitionPaymentController` echoes one back on the quote and requires it on the payment; the client holds it in state between quote and pay). That key is both the provider idempotency key (`tuition:<key>`) and the `student_tuition_payments.request_key` unique index, so a repeated confirmation returns the receipt already written instead of minting a second transfer or a second posting. A raw `Str::uuid()` per call — as the merged PR did — makes every retry a new payment.

`StudentTuitionPaymentAttempt` is written BEFORE the signer is asked to move anything (`updateOrCreate` on `request_key`), so a lost HTTP response or a crash after send has something to find. Its `settlement_verdict` is the only column that may answer "did money move"; `provider_reference` is still a claim and a non-`verified` verdict leaves the attempt `awaiting_verification`/`unknown` for a human rather than being resolved by sending again.

Nothing is posted unless the verdict is `verified`. Mismatch, revert, `not_found` and `unreadable` all write no `StudentTuitionPayment`, no `paid_amount` increment and no treasury ledger row — an unreadable chain is unresolved, not evidence of fabrication.

## Tuition allocation is derived, and overpayment is not revenue
`allocateToTuition()` re-reads the account `lockForUpdate()` and applies `min(amount, remaining)`, recording the rest as `overpayment_base_units`. An amount larger than what is owed is a liability to the student: counting it as settled debt is how a budget plans money it may have to give back. The pay form still refuses to take an over-limit amount; this covers the race where the debt shrinks after the payer confirmed.

## The fake driver is a rehearsal, and says so
`pay()` short-circuits to `rehearse()` when the rail is `FakeLeptonGateway`. The receipt is written so the journey is exercisable, but with `simulated = true`, `tx_verified_on_chain = false`, no allocation and no ledger row; `isReal()` answers false for it everywhere it is read. The merged PR posted tuition and credited the treasury from the fake driver and told the student "Payment sent" — that is the specific lie this lane exists to stop. `quote()` still returns a zero fee for a rehearsal because nothing is charged; that is accurate rather than a quote.

A fee the rail will not report is a refusal, never a figure: `quoteFee()` throws on an unreadable, malformed or zero `networkFee` instead of falling back to `'0.000000'`, because a payer told a transfer is free will be charged one. Affordability compares against the fee rounded UP (`feeBaseUnits($fee, roundUp: true)`) so a wallet holding exactly the quoted total is not short by a remainder.

`circle wallet transfer` is not wrapped by Lepton; it is called through WalletGateway::transfer() with the student's address as the sender, which is why StudentTuitionPaymentService does NOT reuse CircleWalletService::executePaymentBaseUnits (that debits the organization's own Wallet row). The treasury ledger row written alongside is the institution's side only; the student's receipt is the StudentTuitionPayment row.

A payment smaller than what is owed reduces paid_amount and leaves the remainder as student debt — the institution receives the full amount transferred, and the fee is a separate cost borne by the student.
