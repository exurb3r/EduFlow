---
paths:
  - 'app/Services/StudentBillPaymentQuote.php'
  - 'app/Models/StudentBillPayment.php'
---

# Services Models

## An incoming student receipt never carries spend authority
This lane is student -> college. `BillPaymentPolicy` (institution -> vendor) is NOT
evaluated here: its reserve, vendor-destination, ceiling and daily-cap checks are
institutional facts an inbound caller cannot answer honestly. Record a
classification ASSESSMENT only (`AgentDecisionType::ADVISORY_CLASSIFICATION`,
`approvedAmountBaseUnits = 0`, `requiresHumanApproval = true`) under
`SPEND_ASSESSMENT_SCOPE` = `outgoing_spend_advisory_only`.

New snapshots carry `authorizes_outgoing_spend => false`; both scope keys are
OPTIONAL in `hasValidSnapshot()` so pre-marker rows (incl. the factory's
`auto_approve` + `requires_human_approval = false`) still validate.
`authorizesOutgoingSpend()` and `isEligibleForUnattendedSubmission()` return false
unconditionally, for rows of any age. PLAN 17.2 correction 4.

A bill with an UNRESOLVED consent (consented, unsettled, unallocated, non-rehearsal)
refuses a NEW request key; replaying the SAME key returns the stored quote.
`allocate()` credits a VOID bill nothing — the whole arrival is overpayment.
