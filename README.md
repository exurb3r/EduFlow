<div align="center">

# App

**Bounded departmental finance on Circle Agent Wallets + Arc**

Current pilot target: review collected student fees, propose their use within one
department's approved budget, and link authorized bills to USDC payments on Arc testnet.

</div>

---

## What this is

App is institution-owned finance orchestration using
[Circle Agent Stack](https://developers.circle.com/agent-stack) and the
[Arc](https://docs.arc.io) network. Long-term scope covers collections, budgets and
approved obligations; current hackathon scope is deliberately one departmental workflow,
not an unrestricted AI treasurer or replacement for the college's accounting system.

The current product question is **what should the institution do with fees already
collected from students?** App's target is to review verified collections, protect
restricted funds and reserves, and explain how available cash can cover approved obligations.
The college pilot uses deterministic policy checks to:

- **review verified collections** and distinguish available cash from expected fees,
- **propose and explain** use of funds and payments within the approved department budget,
- **request human approval** for eligible proposals outside a separately authorized capped lane,
- **hold or reject** when policy, evidence, budget or reserve checks fail, and
- **execute and verify** permitted USDC mirror payments on **Arc testnet**, not college bank payments.

Current C0 institution inspection is read-only. C1 includes reviewed aggregate collection
evidence, local departmental planning, exact vendor drafts, reviewed policy/destination and
draft recovery, plus a bounded reviewed USDC funding window and cumulative capacity holds.
Holds do not approve payments or lock Circle funds; execution remains disabled. The connected
college pilot and capped recurring-payment lane below are **delivery targets, not shipped
capabilities**. Existing student-aid features remain available for development but are not
this pilot's focus. Wider operational use cases remain in `PLAN.md` Section 13.

**Production limits:** Exact-money handling exists at intake/conversion, draft/policy boundaries
and Arc observation, but legacy treasury/payment floats and two-decimal storage remain.
Reviewed destinations, bounded atomic capacity holds and independently enrolled MFA-backed
payment review exist. Reservation release/funding rollover, approval renewal/withdrawal and
factor recovery, local-to-testnet mapping authority, durable outbox/attempts, execution recovery
and matching settlement verification are still required. A draft,
policy activation or developer demo is not a certified school treasury system.

### Five design rules and remaining release gates

1. **The LLM never moves money.** Policy decisions live in plain PHP
   (`FinancialPolicyEngine`, `EvaluateAssistancePolicy`). The model explains decisions;
   it never makes them.
2. **Exact money is required.** USDC payments use 6 decimals, so `45.00` is `45_000000`.
   Arc native balance/gas uses 18 decimals. Legacy float balances still require migration.
3. **A tx hash is not proof.** Successful matching movement and finality must be verified.
   Legacy `lepton:reconcile` hash-only checks and missing-hash failure labels need hardening;
   a missing RPC result alone does not prove fabrication.
4. **No hardcoded CLI.** All Circle/Arc access goes through
   [`yukazakiri/lepton-agent`](https://github.com/yukazakiri/lepton-agent) gateways.
5. **Demo funding must match the chain.** Use explicit consent for testnet runs and label
   fake results as simulated. Testnet settlement is not proof of mainnet readiness.

---

## College shadow pilot (current hackathon focus)

**Direction:** fee collections and budget planning in shadow/parallel mode. Start smaller:
**one department, its already-approved budget and approved bills for the next couple of
weeks**. The owner reports a local college agreed to explore App; actual usage,
completed pilot results and public endorsement have not been established. Confirm this
is the college's most important feasible problem with its finance owner.

The college keeps collecting fees and paying bills through its existing local-currency
process. Pilot planning can use approved opening funds or staff-verified aggregate realized
receipts; it needs no student records, new fee portal, SIS or bank integration. An allocation
is not cash, and promised tuition is not available money. Budget changes stay with staff.

**Architecture:** shadow/parallel describes how the demo is operated, not a separate app
feature. Use normal invoices, budgets, reviews and payment intents with controlled testnet
configuration. Do not introduce dedicated shadow models, tables, routes or screens.

### Connected workflow and two execution lanes

1. Import or enter permitted anonymized bills, approved allocation, commitments and source
   evidence. Show original amount/currency, due date and exact approved USDC mirror mapping.
2. Propose pay/hold/escalate with policy checks, reasoning and remaining budget/reserve/fee
   impact. Authorized finance staff approve or reject; hard policy failures cannot be overridden.
3. Each eligible admin-approved item produces its **own linked actual USDC payment on
   `ARC-TESTNET` through the existing Circle Agent Wallet/Lepton path**. An unrelated transfer,
   fake receipt or read-only dashboard does not complete this workflow.
4. Verify successful matching Arc movement and finality; link source bill, proposal, decision,
   intent and explorer evidence. Unknown outcomes stay unresolved; retries cannot pay twice.
5. After the human path passes its safety gates, enable a **separately approved, testnet-only
   capped lane** for existing approved recurring bills from allowlisted vendors/destinations,
   within per-payment and cumulative limits, budget, reserve and fee protection. Other valid
   bills go to staff; policy failures stay held. Autonomous allowance starts at zero.

AI may propose or explain, never authorize transfers, change policy or access signing keys.
A staff edit preserves the original proposal and requires a reviewed successor and fresh
checks/approval; it does not mutate an approved intent. Reviewed draft cancellation/replacement
exists; reserved or submitted payments still need separate safe release/recovery work. Application limits remain mandatory; do not claim Circle's mainnet-only native
wallet controls enforce the testnet lane.

### Amount, state and privacy boundaries

- Preserve exact local amount/currency and a staff-approved immutable reference-rate snapshot
  with rounding to six-decimal USDC units. Testnet mapping is not real FX or bank settlement;
  never assume one local-currency unit equals one USDC.
- Use approved, controlled testnet recipients linked offchain to vendor aliases. A mirror
  does not mean the real vendor received money or accepts USDC. Keep local actual-paid state
  separate from testnet mirror state; never clear the college's payable from testnet proof.
- Use verified faucet-funded balances with fee allowance. If funds cannot cover full-value
  mirrors, select smaller real bills or obtain sufficient authorized testnet funding. Never
  silently scale source amounts or seed fictional funds; scaled synthetic demos are separate.
- Keep identities and sensitive documents offchain. Get separate permission for external AI
  processing, testnet execution, staff recording and public college name/logo/quotes.
  Public amounts/addresses can still be linkable. Mainnet and real college funds are excluded.

### Evidence and expansion

Collect college-authorized **video of staff using the workflow and collaboration proof**,
linked reasoning/audit records and verified testnet transaction URLs. Track proposals
approved as-is versus edited/rejected, holds/pending separately, review time against an agreed
baseline including correction effort, and automatic versus escalated outcomes. Count staff
agreement with automatic decisions separately from per-item approvals. Report sample sizes,
feedback and limitations; no invented savings, adoption or guaranteed organizer promotion.

Keep **Circle Agent Wallets** for this hackathon; no mid-build switch to Developer-Controlled
Wallets. Validate with the college, expand departments first, then deeper collections and
later optional assistance. Grades, attendance, hardship, discounts/installments, student
employment, payroll execution, bank/FX adapters and Gateway/x402/Earn/Borrow are deferred.
Preserve existing modules; deferred does not mean deleted.

### For implementation agents

1. Read [PLAN.md Section 14](PLAN.md#14-college-shadow-pilot--hackathon-execution-contract)
   for authoritative pilot scope, delivery order, safety gates and metric definitions.
2. Confirm college problem/consent and minimum source records. Reuse C0 institution context
   and partial C1 draft/policy foundations; do not restart with a student-dependent workflow.
3. Finish exact source/treasury handling, verified destinations, authenticated review,
   reservations, successor/outbox/attempt recovery and matching receipt/finality checks.
   C0 `can_execute=false` and current C1 execution denial remain intact until new gates pass.
4. Prove human-approved source-bill-to-testnet-settlement workflow without duplicates, then
   separately authorize/test capped recurring lane and collect user evidence.

This documentation update enables no transfers. Sections below describe existing developer
commands; they are not a ready-made college pilot or permission to use legacy execution.

---

## Table of contents

- [College shadow pilot](#college-shadow-pilot-current-hackathon-focus)
- [What you'll see](#what-youll-see)
- [Requirements & Prerequisites](#requirements--prerequisites)
- [Setup](#setup)
  - [Development setup (local)](#development-setup-local)
  - [Self-hosted (production) setup](#self-hosted-production-setup)
    - [Option A — Docker deployment (recommended)](#option-a--docker-deployment-recommended)
    - [Option B — Bare-metal production setup](#option-b--bare-metal-production-setup)
- [Lepton agent package (Circle + Arc)](#lepton-agent-package-circle--arc)
- [Authenticate your agent wallet](#authenticate-your-agent-wallet)
- [Fund the wallet](#fund-the-wallet)
- [Run the demo](#run-the-demo)
- [The seeded scenarios](#the-seeded-scenarios)
- [Using the app: fee collections and budget decisions](#using-the-app)
- [Student fee-payment role](#student-side--fee-payment-and-confirmation)
- [Agent collections-to-budget workflow](#agent-side--review-collected-fees-and-propose-their-use)
- [Verify it really settled](#verify-it-really-settled)
- [Institution finance preview](#institution-finance-preview-no-students-required)
- [Commands](#commands)
- [Configuration](#configuration)
- [How the money moves](#how-the-money-moves)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Project layout](#project-layout)

---

## What you'll see

**Intended college workflow, not a claim that the complete UI/execution path ships today:**
Students pay through the college's existing process; staff confirms realized fee receipts.
App reviews those funds alongside approved budgets and commitments, proposes which
approved departmental bills can be funded, and explains any holds or shortfalls. Finance
staff reviews the proposals. Each eligible authorized bill is then linked to a verified
USDC payment on Arc testnet while the college continues paying locally in parallel.

No student assistance request is needed to start this workflow. The first slice uses
staff-verified aggregate collections or approved opening funds, not individual student
records or a new payment portal.

### Legacy seeded developer demo

The existing seed/command below still includes optional assistance fixtures. It is retained
for development and does not represent the fee-collections pilot or its planned student UI:

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

With explicitly authorized and funded Circle testnet execution, this seeded cycle is
intended to submit two invoice payments and part of an aid request; fake runs are simulated.
The other items stop for specific policy reasons. This is developer-demo output, not proof
of college usage or successful matching settlement. The current college target needs
real permitted source records, staff review and connected verified testnet evidence.

---

## Requirements & Prerequisites

### System prerequisites & installation links

| Tool | Minimum Version | Installation / Official Documentation |
|---|---|---|
| **Docker** (Self-hosting) | 24.0+ (Compose v2) | [Docker Engine](https://docs.docker.com/engine/install/) · [Docker Desktop](https://www.docker.com/products/docker-desktop/) |
| **PHP** (Bare-metal) | 8.4.1+ (8.5 recommended) | [PHP Installation Guide](https://www.php.net/downloads) — Required extensions: `pdo_sqlite`, `mbstring`, `openssl`, `curl` |
| **Composer** | 2.x | [Composer Download](https://getcomposer.org/download/) |
| **Node.js & npm** | 20.18.2+ | [Node.js Downloads](https://nodejs.org/en/download) |
| **Circle CLI** | 1.1.4+ | [Circle CLI Documentation](https://developers.circle.com/agent-stack/circle-cli) · `npm i -g @circle-fin/cli` |
| **uv** (Astral) | Latest | [Astral uv Documentation](https://docs.astral.sh/uv/) · Python package & tool manager |
| **arc-canteen** | Latest | [Arc Network Docs](https://docs.arc.io) · [The Canteen App](https://arc-node.thecanteenapp.com/) · CLI for Arc testnet RPC & wallet |

### Installing the prerequisites

1. **Install Node.js & Circle CLI:**
   ```bash
   # Install Circle CLI globally via npm
   npm install -g @circle-fin/cli

   # Verify installation
   circle --version
   ```

2. **Install `uv` and `arc-canteen` CLI:**
   ```bash
   # Install Astral uv (fast Python tool installer)
   curl -LsSf https://astral.sh/uv/install.sh | sh
   # Or via homebrew / package manager: brew install uv

   # Install arc-canteen using uv
   uv tool install arc-canteen

   # Authenticate arc-canteen to provision your Arc testnet RPC endpoint
   arc-canteen login
   ```

3. **Check the toolchain:**
   Run the doctor command inside the repository to verify that all binaries and configurations are recognized:

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
git clone https://github.com/koamishin/App.git
cd App

composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate:fresh --seed
```

`migrate:fresh --seed` now seeds only roles and (in local/testing) developer accounts.
It no longer creates the legacy organization, wallets, budgets, invoices, vendors or
assistance requests: those records and any treasury transactions now come through the
Circle/Lepton agent path (CLI/testnet), not through the database. Student assistance
remains an upcoming feature.

Start the app:

```bash
composer run dev
```

That runs the PHP server, Vite, and the queue worker together. Open the printed URL.

### Development setup (local)

Use this for everyday development. It assumes the **disposable local demo** commands above
(SQLite, seeded demo data, hot-reload):

**Option A — one command (recommended for a fresh clone):**

```bash
composer run setup
```

This installs PHP/JS deps, generates `APP_KEY`, enables the `Blog` module, migrates +
seeds, builds frontend assets, and generates Filament Shield permissions.

**Option B — manual:**

```bash
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database (SQLite, zero config)
touch database/database.sqlite
php artisan module:enable Blog --no-interaction
php artisan migrate:fresh --seed
php artisan module:migrate --force --no-interaction

# 4. Frontend build (or `npm run dev` for HMR during development)
npm run build   # or: npm run dev

# 5. Run everything (app server + queue + logs + Vite)
composer run dev
```

- `composer run dev` starts `php artisan serve`, `queue:listen`, `pail`, and Vite together.
- Keep `LEPTON_DRIVER=fake` while developing without Circle credentials:
  ```env
  LEPTON_DRIVER=fake
  ```
- Run tests: `composer test` (lint + Pest). Run lint only: `composer lint`.
- Re-run `php artisan lepton:doctor` after changing `.env`.

### Self-hosted (production) setup

App is self-hostable. Do **not** use `migrate:fresh --seed` on a real instance; it destroys data.
Use the idempotent installer instead.

#### Option A — Docker deployment (recommended)

Production Docker containers bundle FrankenPHP, Octane, PostgreSQL 16, Redis 7, and Supervisor (scheduler & queue workers). Images are published to GitHub Container Registry.

##### 1. One-line quick start (Docker Compose)

To start App with sensible production defaults:

```bash
docker compose -f https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/docker-compose.yml up -d
```

> **Customization:** Pass environment variables before the command to customize ports or settings, e.g. `APP_PORT=8080 docker compose ... up -d`.

##### 2. Interactive TUI installer

For a guided terminal wizard that prompts for application URL, database credentials, Redis, mail, school identity, Circle/Arc wallet settings, and AI configuration before starting containers:

- **macOS / Linux:**
  ```bash
  bash <(curl -fsSL https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/install.sh)
  ```
- **Windows (PowerShell):**
  ```powershell
  irm https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/install.ps1 | iex
  ```

##### 3. Updating an existing Docker deployment

To pull the newest container images, create an automated PostgreSQL database backup in `./backups/`, run new migrations, and restart services:

- **macOS / Linux:**
  ```bash
  bash <(curl -fsSL https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/install.sh) --update
  ```
- **Windows (PowerShell):**
  ```powershell
  iwr https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/install.ps1 -OutFile install.ps1; .\install.ps1 -Update
  ```
  Or as a one-liner:
  ```powershell
  & ([scriptblock]::Create((irm https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/install.ps1))) --update
  ```

##### 4. Bootstrap the first administrator

On initial boot, the container automatically runs database migrations, enables required modules, and initializes the school identity. Once healthy, create the first super administrator interactively:

```bash
docker exec -it eduflow php artisan eduflow:bootstrap-admin --name="School Operator" --email="operator@example.org"
```

> **Data Persistence:** Database data, uploaded files, and application cache are safely persisted in Docker volumes (`app_db`, `app_storage`, `app_cache`, `app_redis`).

---

#### Option B — Bare-metal production setup

For environments running PHP and web servers directly on host infrastructure:

1. **Server prerequisites.** Install PHP 8.4+/8.5 with extensions (`pdo`, `mbstring`, `openssl`,
   `curl`, `fileinfo`, `dom`, `xml`, `zip`, `bcmath`), Composer, Node.js 20.18+, and (for treasury
   features) the Circle CLI + `uv` + `arc-canteen` (see
   [Lepton agent package (Circle + Arc)](#lepton-agent-package-circle--arc)).
2. **Application code.**
   ```bash
   git clone https://github.com/koamishin/App.git /var/www/eduflow
   cd /var/www/eduflow
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   ```
3. **Environment.**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Set at minimum:
   ```env
   APP_NAME="Your School"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://eduflow.example.org

   DB_CONNECTION=pgsql        # or mysql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=eduflow
   DB_USERNAME=eduflow
   DB_PASSWORD=change-me

   QUEUE_CONNECTION=database   # or redis
   SESSION_DRIVER=database      # or redis
   CACHE_STORE=database         # or redis
   ```
4. **Database & storage.**
   ```bash
   php artisan module:enable Blog --no-interaction
   php artisan migrate --force
   php artisan module:migrate --force --no-interaction
   php artisan storage:link
   ```
5. **School identity and first administrator** (idempotent; safe to repeat as a no-op):
   ```bash
   php artisan eduflow:install --institution="Pilot School" --country=PH --timezone=Asia/Manila --currency=PHP --no-interaction
   php artisan eduflow:bootstrap-admin --name="School Operator" --email="operator@school.example"
   ```
   Bootstrap prompts for a password interactively (hidden). Verify the admin email and
   configure MFA before opening access.
6. **Optimise for production.**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```
7. **Process management.** Run a long-lived queue worker (and scheduler) under Supervisor/systemd:
   ```bash
   php artisan queue:work --sleep=3 --tries=3
   php artisan schedule:work
   ```
   The included Docker image (`docker/Dockerfile`) runs FrankenPHP + Octane + Supervisor
   (queue, scheduler, horizon/pulse when installed). See `docker/start-container`.
8. **Revenue/agent config.** Set `LEPTON_TREASURY_ADDRESS`, `LEPTON_CHAIN`, and the finance
   env flags only after reviewing [Configuration](#configuration) and the release-safety notes.

> **Never** regenerate `APP_KEY`, reset the database, or delete financial records as
> credential cleanup. Preview builds use the `preview` tag only; official releases come from
> `main` after CI. See `docs/getting-started/installation.mdx` and `docker/Dockerfile`.

### Lepton agent package (Circle + Arc)

This application depends on [`yukazakiri/lepton-agent`](https://github.com/yukazakiri/lepton-agent)
 (`composer require yukazakiri/lepton-agent`, already declared in `composer.json`). It bridges
Circle Agent Wallets and the Arc network behind injected contracts; application code never
shells out to `circle`/`arc-canteen` directly.

**What it needs on the host:**
- `circle` CLI (`npm i -g @circle-fin/cli`) — Circle owns the agent session; the package stores no
  credentials.
- `arc-canteen` (`uv tool install arc-canteen && arc-canteen login`) — RPC + optional funded testnet wallet.
- A Circle **agent** wallet (Circle MPC), not a local wallet, so Circle can sign.

**Authentication (OTP, mainnet/testnet independent):**
```bash
# send OTP (request ID printed)
php artisan lepton:login you@example.com
# complete
php artisan lepton:login --request=<id> --otp=B1X-123456
php artisan lepton:login --status
```

**Configure `.env`:**
```env
LEPTON_DRIVER=circle            # circle = real; fake = in-memory (default under testing)
LEPTON_CIRCLE_BIN=circle
LEPTON_ARC_BIN=arc-canteen
LEPTON_CHAIN=ARC-TESTNET
LEPTON_CHAIN_ID=5042002
LEPTON_TREASURY_ADDRESS=0x...   # Circle agent wallet address
LEPTON_RPC_URL=                 # optional override
LEPTON_DRY_RUN=false
LEPTON_ALLOW_RAW_CIRCLE=false
```

**Verify:**
```bash
php artisan lepton:doctor       # binaries, session, treasury, chain reads, ledger parity
php artisan lepton:status --address=0x...
```

**Key rules** (from the package; do not violate):
- Amounts are integer base units; USDC = 6 decimals, Arc native = 18 decimals. Use
  `Yukazakiri\Lepton\Support\Amounts`, never floats or `hexdec()`.
- A transaction hash is a claim, not proof — verify with `eth_getTransactionByHash`.
- Prefer Circle agent wallet addresses; local wallets make transfers fail.
- Mainnet and testnet sessions are independent.

For full API (transfers, balances, x402, Gateway, bridges, testing with the fake
driver), see `vendor/yukazakiri/lepton-agent/README.md` or the package docs.
"""

### Demo accounts

| Role | Email | Password |
|---|---|---|
| Student | `juan@eduflow.test` | `password` |
| Finance officer | `finance@eduflow.test` | `password` |

These accounts belong to the existing developer demo. The student account still exposes
legacy assistance screens; it is not a fee-payment portal or a prerequisite for the college
pilot. Use authorized finance staff and permitted source records for pilot validation.

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

### Money and rate evidence

Request intake parses plain decimal USDC strings exactly, rejects precision/range errors,
and preserves six-decimal minor units. The new `Money` DTO formats without floats and
serializes minor units as strings; currency conversion uses checked arbitrary-precision
intermediates. Legacy treasury/payment APIs and money columns still need migration.

Rate snapshots preserve source time/expiry and reject future, expired, non-positive and
old sources. `EDUFLOW_MAX_RATE_AGE_SECONDS` defaults to 900. Static fallback rates remain
indicative display values. `requireFreshQuote()` requires a non-fallback source with expiry;
it is not an executable provider offer, fee guarantee, or FX/settlement certification.
USD source rates may differ from 1:1 USDC parity. No payment adapter is newly enabled.

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

The package stores no credentials. The Circle CLI owns the session; App only reads it.

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

Once funded, point App at that wallet and sync the ledger:

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
php artisan lepton:reconcile   # legacy hash lookup; stronger pilot verification required
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

**Current focus: fee collections and decisions about their use, not student assistance.**
The workflow below defines the intended user experience. Existing source-evidence and
planning foundations are documented later in this README; the complete student collection
portal, staff review UI and safe payment-execution lifecycle are not yet shipped.

### Student side — fee payment and confirmation

- Students pay tuition or other fees through the college's existing approved payment process.
  The pilot does not require students to hold USDC or use a new App payment portal.
- Students retain the official receipt/reference. Authorized staff verifies received funds
  before including them in the institution's collections data; an expected payment or
  uploaded receipt alone is not confirmed cash.
- A later student-facing view may show fee balances, verified payment status and receipt
  history. Those collection views and individual payment-matching workflows are future
  work, not capabilities of the current legacy student dashboard.
- Students do not request assistance, approve departmental budgets or decide institutional
  spending as part of this pilot. Assistance remains a separate deferred workload.

### Cashier side — transaction intake and reconciliation

Cashier is the institution's intake/reconciliation hub for tuition and other approved
receipts, not a personal wallet or an unrestricted spending approver. Students pay through
existing college channels; cashier records/imports authoritative references, exact amounts,
currencies, categories and received evidence. Separate authorized staff reviews collection
batches. Pending/unmatched receipts, refundable deposits and restricted income cannot be
treated as unrestricted money. Donations, grants and other institution income can follow
reviewed categories later; tuition is the first example, not the only future source.

Cashier entry, independent reconciliation, business approval, payment authorization and
accounting posting remain separate acts. No new cashier portal or complete cash-management
system ships from this plan. Aggregate inputs keep the pilot independent of student rows.

### Agent side — review collected fees and propose their use

1. **Observe confirmed collections.** Read staff-verified aggregate realized receipts,
   approved opening funds, actual outflows and approved bills. Keep source references,
   original currency and observation times; unresolved receipts stay outside spendable cash.
2. **Determine available funds.** Account for restricted cash, protected reserves and existing
   commitments without double-counting them. Check cash availability separately from the
   department's remaining approved allocation; a budget is not proof of money received.
3. **Propose use within approved budgets.** Compare bill due dates, categories and policy
   with available funds. Recommend which approved obligations can be funded now, which need
   human review and which must wait. Show exact amounts, remaining headroom and reasons.
4. **Route business decisions to staff.** A new allocation, transfer between departments or
   change in priority requires the college's authorized decision-maker. The agent cannot
   invent percentage splits, redirect restricted funds or override a hard policy failure.
5. **Prepare authorized testnet payments.** Once the remaining execution gates pass, connect
   each eligible admin-approved bill to its exact approved USDC mirror through the existing
   Circle Agent Wallet. Only separately approved recurring obligations qualify for the
   small capped autonomous testnet lane; everything else needs the appropriate review.
6. **Verify and audit.** Verify successful matching Arc testnet movement and finality; link
   the collection/budget evidence, proposal, policy checks, approval and payment record.
   Unknown outcomes remain unresolved. A testnet mirror never marks the college's real
   local-currency bill paid or creates new institutional income.

For example, verified fees may support an approved internet renewal and laboratory-supply
bill. The agent explains whether both fit the department's allocation and available cash,
while preserving reserves and commitments. If they do not both fit, staff decides the
trade-off. Payroll reserves can be protected in planning; payroll execution remains deferred.

### Background work — no chat prompt required

**Delivered opt-in planning runtime:** cashier capture and budget snapshots create durable
finance runs; scheduler recovery dispatches bounded planning/review jobs without chat or an
open browser. Finance Supervisor shows progress and queued review alerts. Background payment
execution, recurring occurrences and settlement reconciliation remain later gated work.
Optional AI must receive bounded system-generated evidence prompts, never an endless loop,
invented obligations, self-set schedules or self-granted authority.

Enable `EDUFLOW_BACKGROUND_FINANCE=true` only with a durable `database` or `redis` connection
configured through `EDUFLOW_FINANCE_QUEUE_CONNECTION`. Keep a supervised queue worker on
`finance-planning` and invoke Laravel scheduler every minute. The scheduled
`eduflow:dispatch-finance-workflows` recovers committed work; `sync` is refused. Background
finance defaults off, and no scheduler task submits money.

One institution workflow service coordinates specialist tasks; several unrestricted LLMs
are not required. Deterministic PHP decides financial eligibility. Human-approved recurring
mandates let eligible bills from reviewed vendors/destinations run automatically on
`ARC-TESTNET`, within exact per-payment and cumulative caps, allocation, cash/reserve and
fees. Larger/one-off otherwise-valid bills need human approval; hard failures stay blocked.
Human approval of a standing mandate is distinct from a click for each occurrence.

Use supervised durable queue workers plus scheduler, durable event/outbox recovery,
deduplicated occurrences and bounded retries. Do not schedule the legacy developer agent
cycle: it is not the safe new lifecycle. Current funding windows cannot refresh/release
unattended; reviewed rollover, complete payment authorization lifecycle and execution/evidence gates must pass
before this automatic lane is enabled. See `PLAN.md` Sections 14.7–14.10 for delivery order.

### Staff side — review proposals and retain control

- Confirm collection evidence, available funding and the department's approved allocation.
- Inspect proposed use of funds, due dates, policy results and resulting budget/cash headroom.
- Approve or reject eligible payment proposals. Material edits require a reviewed successor
  and fresh checks/authorization, not mutation of an approved payment intent.
- Investigate holds, missing evidence and uncertain outcomes; approval cannot bypass policy.
- Compare verified testnet transaction evidence with each authorized bill, while keeping
  actual local collections/payments in the college's existing accounting process.
- Supervise logical worker runs from a finance dashboard: active/queued/waiting/blocked/failed
  tasks, triggering evidence, policy results, cash/budget holds and automatic mandate usage.
- Receive role-scoped in-app approval/investigation alerts, with optional queued email and
  bounded reminders. Reading a notification is not approval; silence never authorizes payment.
- Pause new submissions or suspend a mandate while preserving in-flight reconciliation and
  audit. View recorded rule results and evidence summaries, not hidden model reasoning.

Finance Supervisor and Collections pages now expose durable planning/review runs and queued
in-app review alerts. Background planning is opt-in; a durable queue worker and scheduler must
run separately. Payment Reviews adds exact reserved-payment evidence and fresh password/TOTP
review. Automatic execution, reminder escalation, assignment and operational health summaries
remain pending. Dashboard access never grants payment permission.

**Implementation boundary:** C0 inspection is read-only; current invoice evidence and
budget planning do not authorize transfers. Existing student-assistance screens and
assistance approval endpoints must not be presented as a fee-collection or vendor-payment
workflow. Reuse ordinary invoice, budget, review and intent foundations as the remaining
[Section 14 pilot gates](PLAN.md#14-college-shadow-pilot--hackathon-execution-contract) are built.

---

## Verify it really settled

A hash in your database is a claim. The command below is **legacy hash reconciliation**,
not the successful receipt, expected-payment matching and finality verification required
for the college pilot. Its `verified` label alone cannot complete pilot settlement.

```bash
php artisan lepton:reconcile
```

```
  ✓  #7    vendor_payment      45.00  verified     0xcecac1d9520c4815
  ✗  #3    student_assistance 100.00  fabricated   0x59cbf4983d0e6ff1

  1 verified · 1 fabricated · 0 unverifiable · 0 ledger-only
```

Legacy output uses **fabricated** for absent hashes, but a missing RPC result alone does
not prove fabrication or failed settlement. Keep uncertain outcomes unresolved and reconcile
without blind resubmission. Fake-driver receipts are simulated, never onchain proof.

For reviewed disposable demo records only, the legacy mutation command is:

```bash
php artisan lepton:reconcile --fix
```

It skips rows already marked reconciled, but its legacy evidence rules remain insufficient
for college payment decisions. Do not run `--fix` on pilot records solely because a hash
lookup is missing. Harden and test receipt/movement/finality checks first.

To inspect one hash yourself (inspection, not settlement proof):

```php
app(Yukazakiri\Lepton\Contracts\ArcNetworkGateway::class)
    ->rpc('eth_getTransactionByHash', [$hash]);
```

---

## Institution finance preview (no students required)

```bash
php artisan eduflow:finance-preview --days=30 --no-interaction
```

Read-only C0 entry point: binds the installation institution, observes its recorded
Circle treasury on the configured Arc network, shows indicative vendor-policy reviews
and a stored-ledger forecast. No students, tuition accounts, assistance fund, assistance
policy or AI provider needed. An empty institution produces an audited `no_op` report.

The report always sets `can_execute=false`: no invoice status changes, budget spending,
reservations or transfers. Native USDC observations preserve 18-decimal quantities and
sub-micro residuals as strings; fake observations say `simulated`, never settled. Invalid
network/address or unavailable RPC produces no inferred chain balance. Legacy policy and
forecast float APIs remain indicative; newer exact evidence and bounded capacity holds are
separate normal workflows, not authority granted by this preview.
SQLite floating-point money storage is refused rather than presented as exact. A preview
is not live-payment readiness, signing authority or proof of custody/settlement. It is the
first read-only step, **not** the complete hackathon pilot: eligible approved college bills
must later be connected to actual verified Arc testnet payments through the gated lifecycle.

`SettlementOperatorFactory` now builds an institution operator without aid setup. Its
`InspectInstitutionFinance` tool is read-only; aid tools appear only with an active local
fund and valid aid policy. The existing aid approval endpoint remains aid-only.

## Vendor-payment draft preparation (C1 foundation)

`eduflow:prepare-vendor-payment` prepares one immutable, full-bill USDC draft. Operator
supplies an existing invoice ID, institution-owned Circle wallet ID, authorized staff ID,
exact fee ceiling and a UUID intent key retained for retries. Required options:
`--actor`, `--wallet`, `--max-fee`, `--intent-key`; inspect available syntax with
`php artisan eduflow:prepare-vendor-payment --help`.

Draft preparation is not human sign-off or proof that the named staff member personally
ran the CLI: host operator has attribution responsibility. No browser/AI tool can call this
path. Policy allows eligible finance staff to prepare, but **everyone is denied execution**.

The draft requires an independently activated immutable finance-policy version and binds
source/recipient, bill, budget allocation, Arc network, amount, fee ceiling, exact policy
content and activation evidence with a canonical SHA-256 snapshot digest. Identical retry returns
the existing identity without duplicate audit; changed fields or a new key for the same
full bill require review. `VerifyVendorPaymentDraft` reloads stored and current evidence;
verification never reserves or sends funds. Digest is change detection, not a signature,
approval, recipient-ownership proof or payment receipt.

Migration is additive and not run against the school database by this change. Existing
money is not backfilled or guessed; inexact SQLite monetary values are refused. Legacy
exact treasury migration, cryptographic destination/control certification, payment
approval, reservation release/rollover, outbox/attempts and actual settlement remain open. Foreign keys retain source
documents, wallet, budget, vendor, preparer and reviewed policy evidence; model updates/deletes are refused.
Rollback drops the new table only when empty; populated-table rollback refuses to erase
draft evidence and requires reviewed archival/forward migration. Malformed snapshot shape
fails integrity validation. Factory fixtures explicitly bind existing source documents with
`PaymentIntent::factory()->forVendorBill($invoice, $wallet, $preparer)`.
Database admins/raw writes remain outside the model guard, and restored drafts must be
reconciled/reviewed before any future execution. `finance.payment-intent-changes.*` provides
independently reviewed terminal cancellation or one fresh non-executable successor. Original
snapshots/reasons remain intact; another key cannot reopen cancellation. Reserved drafts
require future reviewed release first. Recovery review never grants payment authority.

## Reviewed institution finance policy (C1 foundation)

`eduflow:prepare-finance-policy` creates an immutable version without activating it.
Required: version identifier, `--actor`, explicit `--reserve` in exact USDC. Options
`--auto-limit`, `--daily-limit`, `--max-fee` default to zero. Auto limit cannot exceed daily
limit. Inspect syntax with `php artisan eduflow:prepare-finance-policy --help`.

`eduflow:activate-finance-policy` requires policy version ID, separate authorized
`--reviewer`, and explicit `--expected-activation=none` for first activation or current
activation ID for replacement. It never infers the latest review context. Same review
retry returns existing evidence; stale context, self-review (including super admins),
reactivation of superseded versions and corrupt history are refused. Inspect syntax with
`php artisan eduflow:activate-finance-policy --help`.

Activations are append-only, bind predecessor digest and validate actual policy content
through the complete recorded history (up to 10,000 activations; beyond that fails closed
pending reviewed archival design). Policy replacement makes existing payment drafts stale.
New drafts persist restrictive policy version/activation references; upgraded legacy drafts
retain nullable context and are not backfilled; intact historical drafts can now use reviewed
replacement to bind current evidence without mutating the originals. SQLite table rebuilds restore exact-money bounds triggers.

**Policy activation is not payment approval or permission to execute.** Limits record
reviewed configuration for the future intent engine; they do not retrofit legacy payment
paths. CLI staff IDs provide operator attribution, not proof of personal authentication or
MFA. Restrict shell access. Hashes detect changes, not a database administrator rewriting
history and recomputing hashes; externally anchored audit/signing remains a release gate.

## Invoice source evidence and departmental planning

These are normal finance workflows, not a dedicated pilot or demo module. `InvoiceVersion`
binds exact source amount/currency, source and business-approval references, department/period
and explicit USDC reference valuation to an existing `Invoice`. Source money comes from
staff-verified records, not legacy float columns. `CurrencyValuationCalculator` supports
all installed currencies, including six-decimal USDC with identity rate `1`. Valuation is
not executable FX, payment approval or a settlement receipt; evidence has no network or
demo-payment state.

Verified finance staff capture through `POST finance/invoice-versions`
(`finance.invoice-versions.store`), supplying `invoice_id`, retry UUID `capture_key`, exact
string `source_amount`, `source_currency`, `source_evidence`, `business_approval_reference`,
`department`, `period_start`, `period_end`, exact string `source_per_usdc`, `rate_source`,
ISO-8601 `rate_observed_at`, and explicit `rounding` (`down`, `half_up`, `up`).
A separate verified admin reviews via `POST finance/invoice-versions/{invoiceVersion}/review`
with `expected_digest`, `decision` (`approve_evidence`, `reject`, `hold`) and `reason`.
`GET finance/invoice-versions/{invoiceVersion}` shows evidence. Identity comes from the
session; caller-supplied staff IDs are prohibited. Reviews are evidence validation, not
permission to pay. Retries retain one audit; changed evidence requires successor work.

`BudgetSnapshot` captures exact allocation, spending and commitments separately from
verified opening funds, realized aggregate receipts, actual outflows, restricted cash,
protected reserve and other cash commitments. Allocation is never added to cash; forecast
revenue is not available funds. `DepartmentBudgetPlanner` checks an explicitly bound closed
bill set cumulatively by due date and ID. Staff attests other commitments exclude selected
bills and cash protection buckets are disjoint. New snapshots also require opening funds to exclude
selected reviewed collections. Negative headroom stays visible. Source,
review, budget drift or expired/corrupt evidence blocks the complete plan.

Capture with `POST finance/budget-snapshots` (`finance.budget-snapshots.store`). Context:
`budget_id`, `capture_key`, `currency`, `department`, `period_start`, `period_end`, `as_of`,
`valid_until`, selected `bill_ids`, `budget_evidence`, `cash_evidence`, `commitment_evidence`.
Required exact decimal strings: `allocation`, `already_spent`, `other_budget_commitments`,
`opening_funds`, `realized_receipts`, `actual_outflows`, `restricted_cash`, `protected_reserve`,
`other_cash_commitments`. Accept `commitments_exclude_selected_bills`, `cash_buckets_disjoint`
and `opening_funds_exclude_collections`. Supply `collection_review_ids` for nonzero receipts;
`realized_receipts` must equal their exact reviewed gross sum, in the same currency, received
by `as_of`. `restricted_cash` must include all bound collection restrictions.
Inspect through `GET finance/budget-snapshots/{budgetSnapshot}`; plan through
`POST finance/budget-snapshots/{budgetSnapshot}/plan`.

Immutable records, canonical digests, restrictive FKs and populated-rollback refusal retain
evidence. Legacy amount/budget fingerprints detect changes without recovering lost precision.
Versioned invoices are blocked from legacy float-based payment execution. Independently
reviewed exact USDC invoice versions now bind non-executable payment drafts; local reference
valuations do not authorize USDC transfers. Input/review/planning changes no invoice payment state, budget or wallet.
Every response retains `can_execute=false`; plans reserve nothing and verify no settlement
funding. Plans cannot be combined as a funded institution-wide plan. Staff attestation is
not independent bank reconciliation or source/rate authenticity proof.

Staff UI/imports, destination suspension/cooling-off, source/collection corrections, reviewed
reservation release/rollover, local-to-testnet authority, approval renewal/withdrawal and factor
recovery, durable submission/recovery and verified Arc settlement remain open. Existing capacity holds are
bounded to one approved USDC funding window, not a production treasury ledger.
The college shadow demo uses these ordinary workflows in a separately authorized testnet
environment; it is not a parallel application domain or production-readiness claim.

## Reviewed aggregate fee collections

`CollectionBatch` records aggregate received fee amounts and restrictions, not individual
student payments. Verified finance staff use `POST finance/collection-batches` with UUID
`capture_key`, stable `source_stream`, `source_reference`, SHA-256 `source_document_digest`,
`currency`, exact string `received_amount` / `restricted_amount`, ISO-8601 `collected_from`
and `collected_until`, and `cash_evidence_reference`. Accept `source_stream_disjoint` and
`received_not_forecast`. Intervals are half-open: adjacent reports are allowed, overlapping
reports in one stream are refused. Duplicate document digests/references cannot be retried
with new keys. Document digests detect duplicates, not report authenticity.

Separate verified admin uses `POST finance/collection-batches/{collectionBatch}/review`
with `expected_digest`, `decision` (`approve_receipts`, `reject`, `hold`),
`verification_reference`, `reason` and accepted `received_and_restrictions_verified`.
`GET finance/collection-batches/{collectionBatch}` exposes source/review evidence. Self-review
and changed review feedback are refused. Pending, held or rejected receipts cannot supply
planning cash. Corrections/revocation and student receipt matching remain future work.

New budget snapshots bind approved collection records/reviews and exact sums. Missing or
changed bindings block the entire plan. Original schema-v1 snapshots remain readable as
`legacy_staff_attestation`, never silently upgraded; they cannot create new funding windows.
Opening funds stay separately staff-attested and must exclude selected receipts. Streams
must represent disjoint underlying receipts; relabeling/repackaging the same receipts cannot
be reliably detected without later source integrations. Alternative read-only plans may
reference the same receipts; they are not new income or funded department allocations.

Collection approval is independent **staff attestation**, not verified bank balance, Arc
funding, executable FX, receipt issuance or authorization to spend. No gateway call, cash
posting or student record is required. Local fees stay local; testnet uses separate funding.

## Bounded funding and capacity reservations

`finance.funding-windows.*` prepares/shows/independently approves one exact USDC funding
window per institution. It binds schema-v2 budget evidence, approved bills, current policy,
Circle treasury and a fresh block-bound Arc native balance. `finance.payment-reservations.store`
holds each current draft's bill plus maximum fee against cumulative allocation/cash capacity.
Exact native units and residuals stay strings; legacy wallet floats never supply funding.

Maximum window lifetime is 15 minutes. Expiry or drift blocks new holds, never releases old
ones. No new window/department/wallet can reset capacity until reviewed rollover exists.
Reserved drafts cannot be cancelled/replaced without future reviewed release. These are
application holds, not Circle wallet locks. Fake holds are labeled; payment approval and
execution remain false. SQLite two-worker contention is tested; production PostgreSQL/provider
concurrency and outside-wallet withdrawal races are not certified. Local reference valuations
cannot enter this USDC funding lane; separately approved mirror authority remains pending.

## Independent payment review (partial B4)

Finance panel **Payment Reviews** lists held vendor payments, exact six-decimal USDC amounts,
fee ceilings, treasury/recipient, funding bindings and recorded decisions. It requires zero
student records. Actions record `approve_payment`, `reject_payment` or `hold_payment` through
`ReviewVendorPayment`; neither approval nor notification acknowledgement sends a transfer.

A payment reviewer needs a directly assigned `AuthorizePayment:PaymentIntent` permission,
verified email and a finance/admin role. Role inheritance or reseeding alone is insufficient.
A different verified admin must first pin that reviewer's existing Filament app or confirmed
Fortify authenticator through **Enroll payment reviewer** or `finance.payment-reviewers.enroll`, using their own fresh
password/TOTP and an independent identity/authenticator verification reference. This is staff
attestation, not hardware-key or identity-provider certification. Account MFA replacement or
disablement invalidates payment eligibility; factor recovery/rotation needs a later reviewed
workflow. Checker and reviewer cannot share an authenticator secret.

`finance.payment-authorizations.store` and `.show` expose payment-review operations/evidence.
A new review binds displayed draft/reservation digests, exact amount/fee and destination;
fresh password and an unused six-digit TOTP are required. Payment and enrollment replay
steps persist atomically; login replay markers are checked and updated after durable commit. Failed transactions
retain authentication rate-limit attempts. Production needs one shared authoritative cache
for rate limiting/login replay state; PostgreSQL concurrent review and cross-endpoint MFA
races are not certified. Passwords, OTPs and factor fingerprints are excluded from exported
review evidence, notifications and activity logs; validation never flashes `mfa_code`.

Approval expires within five minutes and cannot outlive funding. Current bill, policy,
destination, treasury, closed-set evidence and cumulative holds must still fit observed Arc
cash. Same-key retries return existing evidence, never renew expiry or create another review.
Changed reviewer permission/factor invalidates current authority without rewriting history.
Reject/hold retains capacity. This first slice allows one decision per reserved bill; expired
approval, changed decision, withdrawal and factor rotation require future append-only recovery
before execution can be enabled. No bypass by a new request key or deleting history.

Only `ARC-TESTNET`/`5042002` is accepted. Fake reviews say `simulation_only` and never grant
network payment authority. All outputs retain `can_execute=false`; no submission job,
Circle transfer, ledger posting, invoice-paid mutation or local/testnet conversion is enabled.
B4 remains incomplete until reviewed release/rollover, renewal/withdrawal, local mirror
authority, durable attempts and successful matching final settlement evidence pass.

## Reviewed vendor destinations

Verified finance staff prepare an immutable `VendorDestinationVersion` through
`POST finance/vendor-destinations` (`finance.vendor-destinations.store`) with `vendor_id`,
stable `version`, recorded `address`, explicit `chain` (`ARC` or `ARC-TESTNET`) and
`control_evidence`. Chain ID is derived, not supplied. Address syntax does not prove control.

A separate verified admin approves through
`POST finance/vendor-destinations/{vendorDestinationVersion}/approve`, supplying
`expected_digest`, explicit `expected_approval` (`none` initially, otherwise current approval
ID as string), `verification_reference` and accepted `control_verified`. Identity comes from
authenticated session; self-review is forbidden even for super admins. This records staff
attestation of independent offchain verification, not cryptographic ownership proof.
`GET finance/vendor-destinations/{vendorDestinationVersion}` shows evidence.

Append-only approvals bind predecessor digest and validate historical destination content.
Retries retain one review/audit; stale context, foreign records, tampering, revoked vendor
status or reactivation of superseded versions are refused. No wallet/provider call occurs.
Destination approval is not payment approval and never enables transfers.

New vendor drafts require current destination approval matching configured network and
recorded vendor address. Replacement makes old drafts stale. Exact reviewed USDC invoice
versions bind six-decimal source/review evidence; local reference valuation remains blocked
from executable USDC intent. Additive FK migration leaves old drafts nullable/unapproved
rather than inventing evidence, restores SQLite monetary guards and refuses populated
rollback. Destination revocation/suspension workflow, cooling-off, source corrections,
reservation release/rollover, approval renewal/withdrawal, durable recovery and verified settlement remain open.

## Commands

| Command | What it does |
|---|---|
| `php artisan lepton:doctor` | **Run this first.** Verifies binaries, session, treasury, chain, ledger |
| `php artisan lepton:login --status` | Circle session state per network |
| `php artisan lepton:login <email>` | Send the OTP, print the request ID |
| `php artisan lepton:login --request=<id> --otp=<code>` | Complete login |
| `php artisan lepton:reconcile` | Legacy hash lookup; not complete pilot settlement proof |
| `php artisan lepton:reconcile --fix` | Legacy reconciliation mutation; review missing/unknown evidence before use |
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

`laravel/ai` is installed. Optional advisory, student conversation and assistance
approval-resume wiring exist; financial authority remains in deterministic PHP rules.
The institution inspection tool is read-only. Existing assistance approval endpoints
are not a departmental vendor-payment approval/execution path.

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

**App works with none of this.** Every model call is fail-closed: a missing key, an
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

### Ask App: conversation with a figure check

The student chat has two answers to every question. The deterministic one comes first and is
always computed by PHP. A model may then *rephrase* it, and never replaces it.

```
"What is my tuition balance?"
        │
        ▼
  AskApp::answer()          ← deterministic, live policy, integer math
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
until successful matching movement and finality are verified. Legacy `lepton:reconcile`
hash lookup alone does not provide that guarantee.

> **Never `app(SettlementOperator::class)`.** Its constructor takes an `Organization`, a fund and
> a policy version, and Eloquent models take no constructor arguments — so the container hands
> back **blank, non-existent records**. The agent then refuses with "no active wallet": the right
> outcome for the wrong reason. Use `SettlementOperatorFactory::makeOrFail()`.

---

## How the money moves

**Target collections-to-budget lifecycle; complete execution remains gated:**

```text
Student fees paid through the college's existing local process
        |
        v
Staff-verified realized collections or approved opening funds
        |
        v
Available cash, restrictions, reserves and commitments
        |
        v
Agent proposes use within the department's approved budget
        |
        v
Deterministic checks: permit review, hold or reject
        |
        v
Staff authorization or separately approved capped recurring policy
        |
        v
Reserved payment intent and recoverable Circle Agent Wallet submission
        |
        v
Matching successful Arc testnet settlement and linked audit evidence
```

Budget allocation is an accounting decision, not automatically a wallet transfer.
Collected local fees are **not** automatically converted or bridged into USDC. The pilot
uses a separately funded testnet wallet and a staff-approved exact mirror mapping; real
local receipts and bill-payment status remain in the college's existing process.

### Legacy developer execution path

This existing invoice/assistance path is not the fee-collections intake, departmental
review UI or the safe new payment lifecycle:

```
Invoices + Aid requests
        │
        ▼
  AppAgent::runAutonomousCycle()
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
  lepton:reconcile  →  eth_getTransactionByHash        ← legacy lookup, not full proof
```

The base-unit gateway path uses integers: `Amounts::fromDecimalString('45.00')` gives
`45_000000`. Legacy treasury/payment APIs and storage still use floats or two-decimal
values; those gaps must be removed from the pilot path. The target lifecycle adds durable
authorization/reservation/attempt recovery before submission and successful matching
receipt/finality evidence before **testnet mirror** settlement, without clearing local bills.

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
  Agents/AppAgent.php            the autonomous cycle
  Policies/                          invoice + assistance policy (deterministic)
  Actions/                           policy evaluation, escalation approval
  Services/
    FinancialPolicyEngine.php        every vendor-payment decision
    CircleWalletService.php          the only path to a real transfer
    LeptonReconciliationService.php  proves settlement on-chain
    LeptonTreasuryService.php        live chain reads vs the ledger
  Console/Commands/
    AppDemo.php                  the end-to-end demo
    LeptonDoctor.php                 the "is it working" check
database/seeders/
  AppFinancialSeeder.php         org, wallets, budgets, the six invoices
  AppPlanSeeder.php              thresholds, assistance fund, aid policy
  EducationDemoSeeder.php           students, tuition, one pending aid request
```

---

## Built on

- [Laravel 13](https://laravel.com) · [Inertia v3](https://inertiajs.com) · [Vue 3](https://vuejs.org) · [Tailwind 4](https://tailwindcss.com) · shadcn/ui
- [Filament 5](https://filamentphp.com) for staff panels
- [yukazakiri/lepton-agent](https://github.com/yukazakiri/lepton-agent) for Circle + Arc
- [Circle Agent Stack](https://developers.circle.com/agent-stack) · [Arc](https://docs.arc.io) · [x402](https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/pay-for-service)

Built for the [Lepton Agents Hackathon](https://arc-node.thecanteenapp.com/).
