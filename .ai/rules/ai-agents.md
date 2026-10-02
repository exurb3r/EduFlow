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

## A model may rephrase a brief, never supply a figure
`StudentBrief` is built entirely by PHP from the student's own rows and the live policy, and
`AskEduFlow::answer()` renders the correct explanation before the agent is consulted. That
explanation goes *into* the brief, so the model restates policy-derived reasoning instead of
deriving it. `BriefGuard` then extracts every numeric token from the response and rejects the
whole answer if any figure is absent from the brief. Failing the entire answer rather than
stripping the figure is deliberate: a silently edited answer still reads as authoritative while
quietly omitting what the student asked about.

Compare figures as canonical digit strings, never as floats and never as array keys. PHP 8.5
casts a float array key to int, so keying by `(float) 1000.5` stores `1000` and the guard then
*permits* a fabricated `1000.5` because it collides with a permitted `1000`. This was a live
bug caught by `AskEduFlowMatcherTest`-adjacent coverage; do not reintroduce a numeric key.

## `AskEduFlow` classifies once, not twice
`answer()` and `query()` used to carry independent keyword chains for the same routing decision
and they drifted, so a question could be answered from the policy and then labelled `general`.
`classify()` is now the only router. Adding a keyword means adding it there.

`str_contains` is unsafe for this routing. It matched `usd` inside `USDC`, so any question
quoting an amount in the currency the system transacts in was answered about exchange rates,
and it matched `aid` inside `paid`. Use `mentions()` for whole words and `mentionsStem()` for
truncated stems (`eligib`, `escalat`, `guideline`).

Precedence matters: `attendance` is checked before `currency_conversion`, otherwise "the
attendance rate requirement" is answered with an FX quote.

## Verify conversation ownership inside the gate
`QnaGate` calls `ConversationStore::conversationBelongsTo()` itself rather than leaving it to a
route, because `RemembersConversations::continue()` trusts any id it is handed. A caller cannot
forget a check that lives inside the gate. A mismatched id returns null, so the request falls
back to the deterministic answer and starts a fresh thread rather than leaking the other
student's conversation.

## The approval-resume gate is the only route from a human to a paused tool call
`ApprovalResumeGate` holds six checks, each because the obvious implementation is wrong:

- **Role, via the Gate.** `approveSettlementProposals` on `SettlementApprovalPolicy`, not a role
  name read in the gate. Deliberately narrower than `AssistanceRequestPolicy`: reading requests is
  not authority to release funds, so a reviewer must be granted it separately.
- **Conversation ownership.** `RemembersConversations::continue()` trusts any id. Two officers both
  being allowed to approve does not mean either may answer the other's paused run. Checked inside
  the gate so a route added later cannot skip it.
- **Pending-set subset.** Submitted ids must be a subset of what `pendingApprovalsFor()` reports.
  A stale or foreign id is refused, not passed through.
- **Tool allowlist.** Only `DisburseAssistance`, and `Decision::edit()` is never constructed. A
  reviewer authorising the proposal the model made is a different act from rewriting it.
- **Target provenance.** `assistance_request_id` is read from the *stored* pending approval, never
  from the request body, so a caller cannot redirect an approval at another student.
- **Settings gate.** `mayProposeSettlements()` is checked in `resume()`, deliberately *not* in
  `authorize()`, so listing pending stays available for diagnosis.

`Decision` objects are constructed by the gate, never parsed from input. The payload is only ever
`id => bool`, so there is no field through which an amount or recipient could be smuggled.

A replay finds nothing pending and returns `nothing_pending` without paying again. The response
reports settlements read back from the ledger and always sets
`requires_onchain_verification`: a tx hash is a claim until `lepton:reconcile` proves it.

## Never container-resolve `SettlementOperator`
Its constructor takes `Organization`, `AssistanceFund` and `AssistancePolicyVersion`. Eloquent
models take no constructor arguments, so the container instantiates all three as **blank,
non-existent records** — no error, just wrong ones. The agent then holds an organization whose
`primaryWallet()` is null and the tool refuses with "The organization has no active wallet": the
right outcome for the wrong reason, and it behaves differently once a wallet row exists.

Go through `SettlementOperatorFactory::make()` / `makeOrFail()`, which checks `exists` and
returns null (or throws) naming the actual missing record.

## The AI SDK binds only `ConversationStore`, but implements three interfaces
`DatabaseConversationStore` also implements `VerifiesConversationOwnership` and
`ResolvesPendingApprovals`, and neither is bound — resolving them by interface name failed with
"Target class is not instantiable". `AppServiceProvider::registerAiContracts()` aliases all three
to the same instance. Anything verifying ownership or reading pending approvals needs these.
