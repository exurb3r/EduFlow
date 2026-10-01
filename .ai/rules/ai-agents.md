---
paths:
  - app/Ai/**/*.php
  - tests/Feature/Ai/**/*.php
  - config/ai.php
---

# AI agent layer

## The model may only tighten a decision, never loosen one
The deterministic engine is authoritative. If it returns `ESCALATE`, no model response may
downgrade it to `AUTO_APPROVE`. Any code path where model output could loosen a decision is
a defect. `DisburseAssistance::needsApproval()` is the enforcement point, not a prompt.

## `handle()` must re-evaluate, not trust the approval-time verdict
`needsApproval()` runs when the model *proposes*; `handle()` runs when the tool *executes*.
The model may propose different arguments in between, so `handle()` calls the same policy
action again and refuses if the outcome changed. Checking only at approval time leaves a
window in which an earlier decision authorises a later, different one.

## The advisory DTO is the boundary, not the filter
`AdvisoryEnvelope` is readonly and has no field for an amount, a verdict or a recipient.
`AdvisorySanitizer` intersects against an allowlist *before* constructing it. Both layers
exist deliberately: mutating either one alone is still caught by the tests, so a regression
in one does not open a hole. Never widen the DTO to make a new field convenient.

Advisory is stored under a namespaced `advisory` key so it can never overwrite
`approved_amount`, `policy_checked` or `status`.

## Fail closed, always
A missing key, unreachable endpoint, timeout, malformed response or schema violation all
resolve to `null`, and the deterministic engine proceeds alone. Advisory must never be on
the critical path of a payment, so it runs via `->queue()` where it is used at all.

## Never invent a payout address
A destination comes from a real record or the call is refused. A synthesised address is
either rejected by Circle or, worse, accepted and unrecoverable. This bit us once: the
agent built `0xstudent_<md5>`, 22 hex characters instead of 40. See `.ai/rules/lepton.md`.

## An agent that pauses a tool must be `Conversational`
Tool approval resumes from the paused turn's history. An agent that implements neither
`Conversational` nor receives history via `withMessages()` throws
`ApprovalNotResumableException` the first time a tool pauses. `SettlementOperator` uses
`RemembersConversations` for this reason.

## `continue()` does not check who owns the conversation
`continue()` and `continueOrStart()` accept any conversation id. A route that resumes one
must call `ConversationStore::conversationBelongsTo()` first, or one student can continue
another's conversation.

## Assert that the safety tests can actually fail
Run a mutation before trusting a new invariant: break the guard, confirm the test goes red,
restore. Two mutations here were silently ineffective because a formatter had moved the
anchor, so the "test" proved nothing — check that the mutation applied before reading the
result.