---
paths:
  - app/Actions/RollOverFundingWindow.php
  - app/Actions/ReserveVendorPayment.php
  - app/Actions/ReviewVendorPayment.php
---

# Actions

## Rollover renews the clock, never the money
Only an expired window may be rolled over, and a predecessor may be superseded at most once (unique lineage). Approving a successor retires the predecessor's approval via retire() — a state transition, not a delete — so at most one approval is live per institution while payments already authorized under the old one stay authorized. Retirement columns are the ONLY ones the model's updating hook permits, and they stay outside content() so retirement cannot break a digest.

## Replay a hold before checking whether its window is still live
The idempotent-replay path (returning an existing hold by invoice/key) must run BEFORE any expired-window or superseded-approval guard. A worker retrying a hold it already recorded would otherwise be refused after its window expired, and identity stability across replays is the whole point of the outbox pattern.

## One authority per bill: an expired approval needs a fresh bill, not a retry
`ReviewVendorPayment` looks up `PaymentAuthorization` by `invoice_id` OR `request_key`. An existing authorization for that invoice is returned only on an exact replay of the same identity, evidence and valid_until; anything else throws 'Payment already reviewed or review identity conflicts'. There is no such thing as re-authorising the same bill.

Consequence for operators and for any script driving this path: to obtain fresh spending authority after a previous approval lapses (approvals live five minutes), you must go through the bill — a fresh reviewed invoice, fresh reviewed destination is reusable, a fresh closed budget snapshot, and a reviewed funding-window rollover (which only accepts an EXPIRED window).

Do NOT cancel a draft to force this. ProposePaymentIntentChange with kind=cancel closes the bill permanently: `PaymentIntentLifecycle` resolves it as cancelled and PrepareVendorPayment refuses 'a new key cannot reopen it'. Cancel is for withdrawing a bill, not for recovering an expired approval. The re-issue-a-bill path works; the cancel path quietly destroys the bill.

Also: drive submit+reconcile inside the same request/process as the authorization. An approval expires within five minutes and the executor re-reads authority at execution time, so a shell round-trip across that boundary reliably concludes 'approval_expired' — correct, but not a payment.
