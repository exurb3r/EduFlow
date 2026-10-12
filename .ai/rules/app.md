---
paths:
  - 'app/**/*.php'
---

# App

## Circle CLI is npm-global here; PATH resolves a broken mise shim first
`which circle` resolves to ~/.local/share/mise/shims/circle, which aborts with 'No version is set for shim'. The working binary is the npm global install at ~/.local/share/mise/installs/node/<ver>/bin/circle. Set LEPTON_CIRCLE_BIN to its absolute path in .env, or every Lepton call fails at the first provider read with a misleading mise error.

Also: `circle wallet limit` is mainnet-only, so `php artisan lepton:status` fails for a testnet wallet with 'Wallet not found ... on ARC'. That is expected, not a fault. Use `isAuthenticatedFor('ARC-TESTNET')` on the AuthGateway to check session state; mainnet and testnet authenticate independently.

The wallet-side gateway is ArcCanteenGateway (binary `arc-canteen`), not CircleCliGateway. Settlement reads and transfers go through two different binaries.
