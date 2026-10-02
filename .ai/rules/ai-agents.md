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
## Provider keys live in `ai_providers`, encrypted, and never come back to a form
Endpoints are configured in the admin panel (`Settings → AI Providers`), not only in
`.env`. `api_key` uses the model's `encrypted` cast, so the column is ciphertext and a
database dump alone leaks nothing.

The key is stripped in `EditAiProvider::mutateFormDataBeforeFill()`, so panel access is not
enough to read it out of the DOM, and blank means "keep the stored key" rather than
"clear it". Do not render `api_key` in a table column, an infolist, or a notification —
`TextColumn` "masking" still reveals the tail of a secret.

An admin-configured provider takes precedence over `.env`, resolved per request by
`AiProviderResolver`. Keep that precedence deliberate: it is why a panel change takes effect
without a deploy, and why `.env` remains the fallback for config-managed deployments.

## Two switches, not one, before any data leaves the app
`AiSettings::mayCallProvider()` requires `advisory_enabled` **and** `disclosure_accepted`.
Advisory prompts contain the student's own stated reason, so turning AI on is deliberately
two separate actions. Never collapse them into a single toggle.

`allow_settlement_proposals` is a third switch and defaults to false. Annotate-only is the
default because it cannot block a payout.

## `migrate:fresh` destroys admin-configured providers and their keys
`ai_providers` lives in the application database, so `php artisan migrate:fresh --seed`
wipes every provider and its encrypted key. This has already destroyed a hand-configured
9Router provider once. The encrypted column protects a *dump* of the table; it does nothing
once the row is deleted.

Before running `migrate:fresh`, check `ai_providers` and expect to re-enter any key by
hand afterwards. Prefer `migrate:refresh` for a demo, or re-seed the provider from the
admin panel afterwards. Never assume a previously configured provider survived.

## Not every OpenAI-compatible gateway honours `response_format: json_schema`
9Router accepts the parameter and silently ignores it, returning the object as text, so
`HasStructuredOutput` hands back a string. Two consequences, both load-bearing:

- Agents ask for the JSON shape in their instructions as well, and enumerate the allowed
  values verbatim. Enumerating fixed the observed case where the model invented a
  category outside the allowlist, which the sanitizer then correctly discarded.
- `AdvisoryGate::toArray()` decodes a JSON string, and falls through to that path when
  `toArray()` returns an empty array. Returning early on `[]` silently swallows every
  response from such a gateway.

A gateway may also stream SSE regardless of `stream: false`. Setting an
`Accept: application/json` header on the provider forces a normal JSON response.

## Returning a provider *name* loses the model
`AiProviderResolver` returns a built `Provider` instance in an array, not the driver name.
Passing the bare name makes the SDK resolve `config/ai.php`, which has no default text
model, and openai-compatible endpoints fail with "requires a default text model". Return
the instance.
