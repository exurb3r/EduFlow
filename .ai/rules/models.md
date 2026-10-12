---
paths:
  - 'app/Services/StudentWallet*.php, app/Services/CircleAgentWalletCli.php, app/Console/Commands/*StudentWallet*.php, database/migrations/*student_wallet*.php, app/Models/StudentWallet*.php'
  - app/Models/PaymentSubmissionOutbox.php
---

# Models

## `submitted` is open but never re-dispatchable
OPEN_STATES means 'work still owed'; DISPATCHABLE_STATES means 'a submission worker may pick this up'. `submitted` is deliberately in the first and not the second: settlement is unproven so capacity stays held, but the payment was already sent to the rail and handing the entry back to a worker is a double submission. Open does not mean unsent.

## Student wallet generation uses unwrapped Circle verbs and spends a capped slot
`circle wallet create` and `circle wallet fund` are the two verbs Lepton does not wrap — run them through app/Services/CircleAgentWalletCli.php, which uses the package's CliRunner (argv, never a shell string) and is bound scoped in AppServiceProvider so tests substitute a fake.

`circle wallet create` is capped at 5 AGENT WALLETS PER BLOCKCHAIN with no delete verb, so a generation permanently spends a slot. Read the remaining count from `circle wallet list --type agent --chain <chain>` immediately before minting and refuse past the cap. Currently 1 slot is used by the treasury, so 4 remain on ARC-TESTNET.

`circle wallet fund` only accepts an address that is one of the CLI's OWN agent wallets — an arbitrary student-supplied address cannot be faucet-funded. This is why generation must mint a real Circle agent wallet rather than store a pasted string.

A newly minted address is found by diffing the wallet list before and after (filtered to the target chain), never synthesised from the record. If the diff finds nothing, throw and record nothing — a fabricated destination is either rejected or, worse, accepted and irretrievable.

One `circle wallet create --testnet` call mints wallets on EVERY supported testnet at once; filter the list to `config('lepton.arc.chain')`.

The faucet drips a fixed 20 USDC per call and 429s after ~5. Funding is therefore an explicit `student:fund-wallet` command, never an unattended button, and each drip is logged in student_wallet_fundings. Funding is a mint, not a transfer: it never debits the treasury and never writes a Transaction row.
