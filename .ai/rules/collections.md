---
paths:
  - app/Actions/CaptureCollectionBatch.php
  - app/Actions/ImportTuitionPaymentAsCollection.php
  - app/Models/CollectionBatch.php
---

# Collections receipts

## A reconciled movement is not a second arrival
A portal payment became cash when it settled. `ImportTuitionPaymentAsCollection` does not *record* a
receipt, it **reconciles** one: `wallets.balance` and the `Transaction` row already moved, and the
receipt brings that money into the reviewed ledger. It writes no `Transaction`, touches no balance,
and its `restricted_minor_units` is the payment's overpayment. Double-counting a payment as both a
chain balance and a new receipt is the specific failure PLAN §14.10 calls "cash counted once".

## The evidence fingerprint is derived, not uploaded
`CollectionBatch::movementDigest()` hashes the movement's own identifying fields. It lands in the
existing `source_document_digest` slot, so the capture-time duplicate check and the
`(organization_id, source_document_digest)` unique index catch a second receipt for the same
settlement with no new rule. Two consequences worth not breaking: it must stay a pure function of the
payment (re-import must reproduce it byte for byte), and `collection_batches.student_tuition_payment_id`
must stay unique (one payment, one receipt).

`evidence_origin` in the snapshot is `verified_chain_movement` for these and `staff_attestation`
otherwise. `hasValidSnapshot()` refuses a receipt that claims movement evidence without a link that
still resolves to a real payment, and refuses an attested receipt that carries a movement link. A
reviewer reads `isMovementSourced()` to know which basis they are approving.

## An installation is single-organization; the treasury address is the boundary
`InstallationInstitution::current()` returns null unless **exactly one** organization exists, so
there is no per-student `organization_id` to scope by — `students` has no such column. "Is this our
money?" is answered by `recipient_address` matching `config('lepton.arc.treasury')`. Do not add an
organization filter to student queries; it cannot be written.

## Staff capture may never hand-type a digital receipt
`CaptureCollectionBatch::assertNotADigitalMovement()` refuses two things before anything else:

- `CollectionBatch::TUITION_PORTAL_STREAM` as `source_stream`, so the reconciliation channel cannot
  be typed into the manual form.
- any `source_reference` matching `^0x[0-9a-f]{64}$` that is a settled payment's `tx_hash`. This is
  the realistic double-count: a cashier holding the Arc explorer hash types it as the receipt number
  and books the same 5 USDC as a manual bank credit.

## Restricted is not a formatting choice
A movement's `overpayment_base_units` becomes `restricted_minor_units`, so the portion owed back to a
student is never offered as unrestricted receipts. The form still refuses an over-limit amount; the
bucket covers the race where the debt shrank after the payer confirmed.

## Adding a referenced column on SQLite drops this table's triggers
Adding a FK column to `collection_batches` forces a SQLite table rebuild, and the rebuild **silently
drops** the `collection_batches_bounds_INSERT`/`_UPDATE` triggers that enforce integer amounts,
positive received, `restricted <= received`, `collected_from < collected_until` and the currency set.
Any migration that alters this table on SQLite must re-issue those triggers. `CollectionBatchTest`'s
`collection evidence is immutable…` test exists to catch exactly this, and it did.