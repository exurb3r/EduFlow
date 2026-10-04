<div align="center">

# EduFlow

**Autonomous school finance on Circle Agent Wallets + Arc**

An agent that pays vendors and students in USDC — and stops at a human when policy says it should.

</div>

---

## What this is

EduFlow runs a school's money on [Circle Agent Stack](https://developers.circle.com/agent-stack) and the [Arc](https://docs.arc.io) network. Every cycle it reads invoices and student aid requests, evaluates them against a deterministic policy engine, and either:

- **pays immediately** in USDC over Arc,
- **escalates** to a finance officer, or
- **holds** to protect the school's reserve.

The point of the demo isn't the AI. It's that an autonomous system moving real money is
built so that **every decision is reproducible, every amount is integer-exact, and a
stored transaction hash is treated as a claim rather than proof.**

### The five rules this codebase actually enforces

1. **The LLM never moves money.** Policy decisions live in plain PHP
   (`FinancialPolicyEngine`, `EvaluateAssistancePolicy`). The model explains decisions;
   it never makes them.
2. **Money is integer base units.** USDC is 6 decimals, so `45.00` is `45_000000`. No
   floats anywhere near a balance.
3. **A tx hash is not proof.** `lepton:reconcile` re-derives settlement from the chain.
   Fabricated receipts get marked failed.
4. **No hardcoded CLI.** All Circle/Arc access goes through
   [`yukazakiri/lepton-agent`](https://github.com/yukazakiri/lepton-agent) gateways.
5. **Testnet fixtures match what the faucet can fund.** The demo settles in real USDC.

---

## Table of contents

- [What you'll see](#what-youll-see)
- [Requirements](#requirements)
- [Setup](#setup)
- [Authenticate your agent wallet](#authenticate-your-agent-wallet)
- [Fund the wallet](#fund-the-wallet)
- [Run the demo](#run-the-demo)
- [The seeded scenarios](#the-seeded-scenarios)
- [Using the app](#using-the-app)
- [Verify it really settled](#verify-it-really-settled)
- [Commands](#commands)
- [Configuration](#configuration)
- [How the money moves](#how-the-money-moves)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Project layout](#project-layout)

---

## What you'll see

A full cycle looks like this:

```
$ php artisan eduflow:demo

Cycle: auto-paid 3, escalated 2, held 1, disbursed 85 USDC.

  Invoice  30.00 USDC   AUTO_APPROVE      VENDOR_AUTO_PAYMENT_V1
  Invoice  45.00 USDC   AUTO_APPROVE      VENDOR_AUTO_PAYMENT_V1
  Invoice  60.00 USDC   ESCALATE          VENDOR_COMPLIANCE_V1
  Invoice  90.00 USDC   ESCALATE          HIGH_VALUE_DISBURSEMENT_V1
  Invoice 200.00 USDC   HOLD              TREASURY_RESERVE_SAFETY_V1
  Invoice 2000.00 USDC  REJECT            BUDGET_EXHAUSTION_V1

  Aid     15.00 USDC   PARTIAL_APPROVAL  BOUNDED_EMERGENCY_AID_V1
    10.00 USDC approved instantly, 5.00 USDC escalated for advisor review.
```

Two invoices and part of an aid request pay for real. The rest are stopped, each for a
different and specific reason — that is the demo.

---

## Requirements

| | |
|---|---|
| PHP | 8.5 recommended; locked dependencies require at least 8.4.1, with `pdo_sqlite`, `mbstring`, `openssl` |
| Composer | 2.x |
| Node.js | 20.18.2+ |
| Circle CLI | `npm install -g @circle-fin/cli` |
| arc-canteen | `uv tool install arc-canteen && arc-canteen login` — gives you an Arc testnet RPC endpoint |

Check the toolchain before anything else:

```bash
php artisan lepton:doctor
```

It verifies the binaries, your Circle session, your treasury address, chain reads and
ledger parity, and tells you which of those are broken. Run it first; it saves a lot of
guessing later.

---

## Setup

**Disposable local demo only:** Commands below include `migrate:fresh`, which deletes
existing database records. Never use this setup on a school database or an instance
holding configured providers or payment history.

```bash
git clone https://github.com/koamishin/EduFlow.git
cd EduFlow

composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate:fresh --seed
```

`migrate:fresh --seed` gives you the organization, the wallets, the budgets, the policy
versions, six invoices, two vendors' worth of vendor records, and one pending student
aid request. It is the whole demo.

Start the app:

```bash
composer run dev
```

That runs the PHP server, Vite, and the queue worker together. Open the printed URL.

### Demo accounts

| Role | Email | Password |
|---|---|---|
| Student | `juan@eduflow.test` | `password` |
| Finance officer | `finance@eduflow.test` | `password` |

Log in as each to see both sides of the approval loop.

### School identity setup and first administrator

OSS production rollout is still in progress. These commands initialize school identity,
conservative settings and administrator access—not native-fiat accounting, a complete aid
workflow, production financial safety or payment certification.

On an operator-controlled instance with reviewed database configuration,
`APP_ENV=production`, `APP_DEBUG=false`, and a valid unique existing `APP_KEY`:

```bash
php artisan migrate --force --no-interaction
php artisan eduflow:install --institution="Pilot School" --country=PH --timezone=Asia/Manila --currency=PHP --no-interaction
php artisan eduflow:bootstrap-admin --name="School Operator" --email="operator@school.example"
```

Migrations are a separate operator task. Installer never migrates, resets data, seeds demo
records, generates keys, creates wallets or calls external providers. It creates roles,
one institution with zero autonomous thresholds, persisted country/locale/timezone/currency,
and disables public registration, impersonation and AI. Country is validated as a two-letter
code format; jurisdiction eligibility is not certified. English is the current supported locale.
School currency metadata does not convert legacy USDC tuition or payment records.

Repeat identical setup is a no-op: credentials, settings, budgets and policies survive.
Changed identity/metadata is refused rather than overwritten. An existing institution
requires explicit `--adopt-institution` with its actual numeric ID and matching name/currency;
review its existing financial data before adoption. Adoption preserves monetary limits,
policies and balances; it is not cleanup of an existing demo or authorization of live payments.
Missing keys, unsafe debug mode, locked unsafe defaults or ambiguous institutions fail setup.

`RolesAndPermissionsSeeder` now creates no users in any environment. Default local demo
accounts live in `DemoUsersSeeder`, invoked only by local/testing demo `DatabaseSeeder`.
Production/staging seed roles and permissions, not demo users or institutions. Bootstrap
asks for the password and confirmation through hidden prompts; passwords must have at
least 12 characters, mixed case, a number, and a symbol. It requires an interactive
terminal, has no password CLI option, leaves email verification pending, and refuses
existing users or a second superadmin. Verify email and configure staff MFA before opening
school access. Configure SMTP for verification/reset flows first.

This change does **not** remove accounts created by older seeders. Operators must review
existing `admin@admin.com` / `user@user.com` accounts, rotate exposed passwords, revoke
sessions and unnecessary access, and preserve required audit evidence. Do not regenerate
`APP_KEY`, reset the database, or delete financial records as credential cleanup.

### Single-institution context

Installer persists `organizations.id` as school identity in installation settings.
Production/staging use that identity without requiring `.env` edits. Legacy instances may
still configure `EDUFLOW_INSTITUTION_ID`; when both exist, they must agree. Database must
contain exactly one institution. Missing, corrupt, stale or ambiguous context disables
treasury actions/operator construction instead of choosing the first row. Local/testing
retain automatic selection only before setup and when exactly one institution exists.

After setup, Eloquent refuses creation of another institution. Raw database access can
bypass that model guard, but ambiguous data still disables selection. Restart long-lived
workers after setup/configuration changes. This is not complete tenant isolation or
ownership enforcement for every existing resource; do not host unrelated schools in one DB.

Public registration GET/POST is denied for installed schools until the existing registration
setting is explicitly enabled. Opt-in registration grants no staff role or verified student
enrollment. Password reset/email verification/MFA still require operator SMTP and access setup.

### Release safety

Preview builds publish only after successful same-repository push CI, from its exact
commit. Preview images use `preview` and version/SHA tags, never stable `latest`.
Manual releases require successful CI for the dispatched `main` commit, reject existing
tags, and reject `latest` for drafts/prereleases. Configure required reviewers on the
GitHub `release` environment before using official publication; an environment name alone
does not enforce approval. Ad hoc Docker Build Check never publishes an image.

A Graphite token previously embedded in the release workflow has been removed with that
integration. Its owner must still revoke/rotate the exposed token and review access and
repository history. Removing source text does not invalidate a credential. These checks
do not certify the current Docker image or live payment rails for production.

---

## Authenticate your agent wallet

Circle agent wallets authenticate with an **email one-time password**. Sessions last
seven days.

```bash
# Check current state first — this never fails and never prompts
php artisan lepton:login --status

# Step 1: send the OTP, get a request ID (expires in 10 minutes, one-shot)
php artisan lepton:login you@example.com

# Step 2: paste the code from the email
php artisan lepton:login --request=<request-id> --otp=B1X-123456
```

> **Mainnet and testnet authenticate independently.** A valid mainnet session does not
> authorise an ARC-TESTNET transfer. If testnet transfers fail, check the *testnet* row
> in `lepton:login --status`.

The package stores no credentials. The Circle CLI owns the session; EduFlow only reads it.

---

## Fund the wallet

```bash
circle wallet fund --address <your-agent-wallet> --chain ARC-TESTNET
```

**This mints exactly 20 USDC per call and ignores `--amount`.** It also rate-limits
after roughly five calls:

```
Error: Faucet drip failed (429): API rate limit error
```

So a realistically funded wallet holds about **100–120 USDC**. That ceiling is why the
demo scenarios are the size they are — see [The seeded scenarios](#the-seeded-scenarios).

Once funded, point EduFlow at that wallet and sync the ledger:

```bash
# LEPTON_TREASURY_ADDRESS=0xYourAgentWallet   in .env
php artisan lepton:doctor        # confirms env, database and Circle all agree
php artisan lepton:reconcile     # proves existing receipts against the chain
```

Use **Sync from chain** on the dashboard to overwrite the ledger balance with the real
figure. Do this before the demo, or the dashboard will show drift and auto-pays will
fail for lack of funds.

> **USDC is the gas token on Arc.** A wallet needs USDC to send anything at all,
> including a zero-value transfer. "My balance reads zero" is sometimes really "no gas".

---

## Run the demo

```bash
php artisan lepton:doctor      # is it wired up?
php artisan eduflow:demo       # run one full autonomous cycle
php artisan lepton:reconcile   # prove every receipt on-chain
```

`eduflow:demo` moves **85 USDC** of real testnet USDC (30 + 45 + the 10 USDC aid
portion). It is not a simulation under the `circle` driver, and it is not repeatable
without re-funding — see [Funding](#fund-the-wallet).

To watch it without touching a chain, use the fake driver:

```env
LEPTON_DRIVER=fake
```

Everything then runs in memory. `is_fake` is set on every receipt and the UI badges them
**Simulated**, so you can never mistake a fake run for settlement.

---

## The seeded scenarios

Given a 120 USDC wallet, a 20 USDC reserve and a 50 USDC autonomous limit. The policy
engine checks **vendor → budget → reserve → auto limit**, so each amount is placed
against those gates deliberately.

| Invoice | Amount | Decision | Why |
|---|---:|---|---|
| `INV-FIBER-30` | 30 | **auto-pay** | verified vendor, under the limit |
| `INV-CLOUD-45` | 45 | **auto-pay** | verified vendor, under the limit |
| `INV-UNVERIFIED-60` | 60 | **escalate** | vendor not verified, checked first |
| `INV-LAB-90` | 90 | **escalate** | over the 50 limit, wallet can still afford it |
| `INV-SUPPLY-200` | 200 | **hold** | would breach the 20 USDC reserve |
| `INV-HAZARD-2000` | 2000 | **reject** | equipment budget only holds 500 |

Plus one student aid request at **15 USDC** against a **10 USDC** auto-limit, which
produces the bounded split: 10 approved instantly, 5 escalated for advisor review.

Only the two auto-pays and the approved aid portion move money: **85 USDC**. Everything
else is a decision, not a payment. `tests/Feature/DemoScenarioScaleTest.php` fails if
these ever stop fitting the faucet ceiling or stop covering all four reachable
decisions.

---

## Using the app

**Student side** (Inertia + shadcn/ui) — log in as `juan@eduflow.test`:

- Submit an assistance request and watch the split explained in plain language.
- Ask the policy questions directly: *"Why didn't you send the full 15 USDC?"*,
  *"What are the assistance guidelines?"*, *"What's the current rate?"*

**Staff side** (Filament) — log in as `finance@eduflow.test` at `/admin`:

- **Dashboard** — live chain state, ledger drift, treasury balance.
- **Approval Center** — approve or reject escalated decisions.
- **Invoices** — settlement status and an explorer link for each.
- **Transactions** — a **Chain Proof** column; run *Verify against Arc* on any row.
- **Agent Decisions** — the recorded input snapshot and reasoning for every call.

---

## Verify it really settled

This is the part that matters. A hash in your database is a claim.

```bash
php artisan lepton:reconcile
```

```
  ✓  #7    vendor_payment      45.00  verified     0xcecac1d9520c4815
  ✗  #3    student_assistance 100.00  fabricated   0x59cbf4983d0e6ff1

  1 verified · 1 fabricated · 0 unverifiable · 0 ledger-only
```

Forged or fake-driver receipts come back **fabricated**. Mark them failed with:

```bash
php artisan lepton:reconcile --fix
```

This is idempotent — it only touches rows not already reconciled, and it will not tell
you to re-run a flag you already passed.

To check one hash yourself:

```php
app(Yukazakiri\Lepton\Contracts\ArcNetworkGateway::class)
    ->rpc('eth_getTransactionByHash', [$hash]);
```

---

## Commands

| Command | What it does |
|---|---|
| `php artisan lepton:doctor` | **Run this first.** Verifies binaries, session, treasury, chain, ledger |
| `php artisan lepton:login --status` | Circle session state per network |
| `php artisan lepton:login <email>` | Send the OTP, print the request ID |
| `php artisan lepton:login --request=<id> --otp=<code>` | Complete login |
| `php artisan lepton:reconcile` | Prove every receipt against the chain |
| `php artisan lepton:reconcile --fix` | Mark fabricated receipts as failed |
| `php artisan eduflow:demo` | Run one full autonomous cycle |
| `php artisan lepton:status --address=0x…` | Chain, block, balance, limits |
| `php artisan lepton:transfer 0x… --amount=1.00 --from=0x…` | Single transfer (`--estimate` to dry-run) |

---

## Configuration

```env
# circle = real Circle CLI + arc-canteen, fake = in-memory ledger
LEPTON_DRIVER=circle
LEPTON_CHAIN=ARC-TESTNET
LEPTON_CHAIN_ID=5042002

# A Circle *agent* wallet. A local arc-canteen wallet cannot be signed for.
LEPTON_TREASURY_ADDRESS=0xYourAgentWallet
```

`LEPTON_DRIVER` defaults to `fake` under `APP_ENV=testing`.

---

## The AI layer (optional)

`laravel/ai` is installed. The agents exist, but **none of them are wired into the request
path yet** — every decision still runs through the deterministic PHP policy engine, and
that is the point.

```bash
# Optional: a local model, so the AI layer runs with no paid API key
ollama serve
ollama pull llama3.1
```

```env
AI_PROVIDER=local
AI_LOCAL_URL=http://127.0.0.1:11434/v1
AI_LOCAL_MODEL=llama3.1
```

Or point it at a hosted provider:

```env
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-...
```

### Configure it from the admin panel instead

Endpoints and keys can be set in the admin UI instead of `.env`, which is what you want
when a deployment has several providers or when the key must not sit in a file.

**Settings → AI** holds the switches:

| Switch | Default | Effect |
|---|---|---|
| Allow advisory model calls | **off** | Master switch. Nothing leaves the application when off. |
| Student data may be sent to the provider | **off** | Second, separate acknowledgement. Both must be on. |
| Let the AI propose disbursements | **off** | Off means annotate-only. See the tiers below. |
| Timeout | 20s | Kept short on purpose: advisory must never delay a payment. |

**Settings → AI Providers** manages endpoints. Any SDK driver works, including
**OpenAI-compatible** for Ollama, LM Studio, vLLM, LiteLLM, Together or a corporate
gateway:

| Field | Notes |
|---|---|
| Driver | `openai-compatible` for anything that speaks the OpenAI wire format |
| Base URL | **Required** for `openai-compatible`; it has no default endpoint |
| Text model | Used as that provider's default model |
| Extra headers | Some gateways need e.g. `X-Tenant-Id` |
| API key | Optional — local endpoints usually need none |

**API keys are encrypted at rest** with `APP_KEY` using Laravel's `encrypted` cast. The
database column holds ciphertext, so a dump of it leaks nothing on its own. The key is
also never rendered back into the edit form: it is stripped before the form is filled, so
panel access is not enough to read a secret out of the DOM. Leaving the field blank keeps
the stored key; rotating it is therefore an explicit act.

There is also a *Test connection* action, which probes `/models` without sending any student
data and without costing a completion.

> **`migrate:fresh` wipes this.** `ai_providers` is an application table, so
> `php artisan migrate:fresh --seed` deletes every provider and its key. Use
> `migrate:refresh` for a demo, or re-enter the key afterwards. The encryption protects a
> database *dump*; it cannot survive the row being deleted.

> **Not every gateway honours `response_format`.** 9Router accepts the parameter and
> ignores it, returning the object as text. The agents therefore also ask for the JSON shape
> in their instructions, and the gate decodes a string response. If a gateway streams SSE
> regardless of `stream: false`, add an `Accept: application/json` extra header.

**EduFlow works with none of this.** Every model call is fail-closed: a missing key, an
unreachable endpoint, malformed output or a timeout all resolve to "no advisory available",
and the deterministic engine proceeds alone. A model outage degrades the product; it never
blocks a payment.

### The three tiers

| Tier | Owner | Can move funds? |
|---|---|---|
| **Deterministic** | PHP — limits, reserve, caps, rate locking, auto-vs-escalate | Only after every check passes |
| **Advisory** | LLM — hardship category, urgency, confidence, narrative, anomaly flags | Never |
| **Prohibited** | — | Approved amount, policy verdict, recipient selection |

The one-way ratchet: the model may only *tighten* a decision. An `ESCALATE` verdict cannot
be downgraded by any response.

### How that is enforced, not just promised

- **Allowlist, not a filter.** `AdvisoryEnvelope` is a readonly DTO with no amount, verdict
  or recipient field. Unknown keys are dropped before it is constructed, so a
  prompt-injected `"approved_amount": 999999` has nowhere to land. Advisory is namespaced
  under a metadata `advisory` key and cannot overwrite `approved_amount`.
- **`DisburseAssistance` is `Approvable`,** and its `needsApproval()` runs the real policy
  evaluation. `handle()` re-evaluates rather than trusting the earlier verdict, because the
  model may propose different arguments between the pause and the resume.
- **27 adversarial tests** cover injected amounts, injected verdicts, malformed output,
  provider failure and prompt injection. All run offline via the SDK's own fakes.

### Ask EduFlow: conversation with a figure check

The student chat has two answers to every question. The deterministic one comes first and is
always computed by PHP. A model may then *rephrase* it, and never replaces it.

```
"What is my tuition balance?"
        │
        ▼
  AskEduFlow::answer()          ← deterministic, live policy, integer math
        │
        ▼
  StudentBrief                  ← those facts + that explanation, assembled by PHP
        │
        ▼
  QnaGate → BriefGuard          ← model answers; every figure checked against the brief
        │
    ┌───┴───────────────┐
    │                   │
 verified            anything else
    │                   │
 assistant          deterministic
```

`BriefGuard` extracts every numeric token from the model's response and discards the whole
answer if any of them is absent from the brief. So "Your balance is 275.00 USDC" is refused and
the student gets the real figure instead. Stripping just the bad number was rejected
deliberately: an edited answer still reads as authoritative while quietly omitting what the
student asked about.

> **Compare figures as digit strings, not floats.** PHP 8.5 casts a float array key to `int`, so
> keying by `(float) 1000.5` stores `1000` — and the guard then *permits* a fabricated `1000.5`
> because it collides with a permitted `1000`. That was a live bug, now covered by a test.

Threads are real and checked. `QnaGate` calls `conversationBelongsTo()` itself rather than
trusting the route, because the SDK's `continue()` accepts any conversation id. Send another
student's id and you get your own deterministic answer and a fresh thread.

The response carries `source: deterministic | assistant`, and the panel shows a badge for the
model-phrased case, so it is always visible whether a reply came from the ledger or from a
model reading it.

### Approving a proposal the agent paused on

When `DisburseAssistance` falls outside the autonomous limit, the run *pauses* and waits for a
person. That decision has an endpoint:

```bash
# What is this conversation waiting on?
curl -X POST http://localhost:8000/finance/approvals/pending \
  -H 'X-CSRF-TOKEN: ...' \
  -d 'conversation_id=<uuid>'

# Answer it. `decisions` is only ever id => true/false.
curl -X POST http://localhost:8000/finance/approvals/resume \
  -H 'X-CSRF-TOKEN: ...' \
  -d 'conversation_id=<uuid>&decisions[call_abc]=true'
```

Six checks stand between that `true` and a transfer, in `ApprovalResumeGate`:

| Check | Why the obvious version is wrong |
|---|---|
| Role via the Gate | Reading a role name in the gate drifts from the panel |
| `conversationBelongsTo()` | `continue()` accepts **any** conversation id |
| Pending-set subset | A stale or foreign tool-call id must not be passed through |
| Tool allowlist | Only `DisburseAssistance`; `Decision::edit()` is never built |
| Target from the stored pause | The request id is never read from your payload |
| `mayProposeSettlements()` | A switch turned off after the pause still refuses |

`decisions` accepts **only** `id => bool`. There is deliberately no field for an amount or a
recipient, and the `assistance_request_id` is read from the stored pause, so an approval cannot
be redirected at another student.

A replayed approval finds nothing pending and returns `nothing_pending` without paying twice.
Any settlement in the response sets `requires_onchain_verification` — a returned hash is a claim
until `php artisan lepton:reconcile` proves it.

> **Never `app(SettlementOperator::class)`.** Its constructor takes an `Organization`, a fund and
> a policy version, and Eloquent models take no constructor arguments — so the container hands
> back **blank, non-existent records**. The agent then refuses with "no active wallet": the right
> outcome for the wrong reason. Use `SettlementOperatorFactory::makeOrFail()`.

---

## How the money moves

```
Invoices + Aid requests
        │
        ▼
  EduFlowAgent::runAutonomousCycle()
        │
        ▼
  FinancialPolicyEngine / EvaluateAssistancePolicy     ← deterministic PHP
        │  auto_approve │ escalate │ hold │ reject
        ▼
  CircleWalletService  →  WalletGateway  →  circle wallet transfer
        │
        ▼
  Transaction row (status + hash)                      ← a claim
        │
        ▼
  lepton:reconcile  →  eth_getTransactionByHash        ← the proof
```

Amounts cross the boundary as integers. `Amounts::fromDecimalString('45.00')` gives
`45_000000`; nothing is ever parsed as a float.

> **Never `hexdec()` a chain quantity.** Arc native USDC is 18 decimals, so 20 USDC is
> 2e19 wei — past `PHP_INT_MAX`. `hexdec()` returns a float there and an `(int)` cast
> silently wraps. Use `Amounts::fromHexQuantity()`. A guard test fails if `hexdec(` is
> reintroduced into a chain-reading file.

---

## Testing

```bash
php artisan test                              # 374 tests
php artisan test --compact --filter=Reconcile
```

The fake driver needs no network, no credentials and no cost, so the whole suite runs
offline. Tests that assert on receipts construct a chain stub that knows about exactly
one hash, so fabricated receipts cannot pass unnoticed.

---

## Troubleshooting

**`no agent session is active`** — the address is not a Circle agent wallet, or you are
not logged in for that network. Check `php artisan lepton:login --status` and
`circle wallet list --type agent --chain ARC-TESTNET`.

**Transfers fail on testnet but the wallet looks funded** — you are authenticated for
mainnet only. Mainnet and testnet are independent sessions.

**`method 'trace_block' not allowed by the proxy`** — the Arc RPC proxy is allowlisted
by design. That method is not exposed; it is not a bug in your setup.

**`Faucet drip failed (429)`** — you hit the per-user faucet cap. Wait, or use a second
agent wallet. There is no way around the ~120 USDC ceiling.

**Auto-pays fail with "asset amount owned by the wallet is insufficient"** — the ledger
claims a balance the chain does not hold. Use **Sync from chain**, and check
`lepton:doctor` for drift.

**A balance reads as 0** — you are probably pointed at an `arc-canteen` local wallet
rather than your Circle agent wallet, or you are querying a chain you are not
authenticated for.

**`eduflow:demo` is not repeatable** — each run spends 85 USDC. Re-fund, or use
`LEPTON_DRIVER=fake`.

---

## Project layout

```
app/
  Agents/EduFlowAgent.php            the autonomous cycle
  Policies/                          invoice + assistance policy (deterministic)
  Actions/                           policy evaluation, escalation approval
  Services/
    FinancialPolicyEngine.php        every vendor-payment decision
    CircleWalletService.php          the only path to a real transfer
    LeptonReconciliationService.php  proves settlement on-chain
    LeptonTreasuryService.php        live chain reads vs the ledger
  Console/Commands/
    EduFlowDemo.php                  the end-to-end demo
    LeptonDoctor.php                 the "is it working" check
database/seeders/
  EduFlowFinancialSeeder.php         org, wallets, budgets, the six invoices
  EduFlowPlanSeeder.php              thresholds, assistance fund, aid policy
  EducationDemoSeeder.php           students, tuition, one pending aid request
```

---

## Built on

- [Laravel 13](https://laravel.com) · [Inertia v3](https://inertiajs.com) · [Vue 3](https://vuejs.org) · [Tailwind 4](https://tailwindcss.com) · shadcn/ui
- [Filament 5](https://filamentphp.com) for staff panels
- [yukazakiri/lepton-agent](https://github.com/yukazakiri/lepton-agent) for Circle + Arc
- [Circle Agent Stack](https://developers.circle.com/agent-stack) · [Arc](https://docs.arc.io) · [x402](https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/pay-for-service)

Built for the [Lepton Agents Hackathon](https://arc-node.thecanteenapp.com/).
