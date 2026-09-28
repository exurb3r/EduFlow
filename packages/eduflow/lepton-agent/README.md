# EduFlow Lepton Agent

Laravel bridge for Lepton Agents (Circle Agent Stack + Arc). App code never hardcodes `circle` / `arc-canteen` strings.

## Why

`EduFlowAgent` and `CircleWalletService` should depend on contracts:

```php
use Eduflow\Lepton\Contracts\WalletGateway;
use Eduflow\Lepton\Support\Amounts;

// 450.00 USDC -> 450_000000 base units, no floats
$result = $wallets->transfer($from, $to, Amounts::fromDecimalString('450.00'));

// or via facade
use Eduflow\Lepton\Facades\Lepton;
Lepton::transferDecimal($from, $to, '450.00');
```

## Drivers

- `circle` (default): `CircleCliGateway` + `ArcCanteenGateway` via Symfony `Process`
- `fake`: `FakeLeptonGateway` in-memory ledger for `testing` / offline demo

Set via `.env`:

```env
LEPTON_DRIVER=circle
LEPTON_CIRCLE_BIN=circle
LEPTON_ARC_BIN=arc-canteen
LEPTON_CHAIN=ARC-TESTNET
LEPTON_CHAIN_ID=5042002
LEPTON_TREASURY_ADDRESS=0x...
LEPTON_RPC_URL=
LEPTON_DRY_RUN=false
```

## Commands

```bash
php artisan lepton:status --address=0x...
php artisan lepton:transfer 0xDEST --amount=1.00 --from=0xSRC
```

## Notes

- Arc Testnet chain ID `5042002`, mainnet `5042`. Gas token is USDC.
- `circle wallet limit` is mainnet-only; on testnet `limits()` is declared-only.
- Amounts are always integers (USDC 6 decimals). Floats are prohibited.
- Octane-safe: bindings use `scoped()`, no request state in singletons.
