---
paths:
  - 'app/Services/StudentTuitionPaymentService.php, app/Models/StudentTuitionPayment*.php, app/Http/Controllers/StudentTuitionPaymentController.php, tests/Feature/StudentTuitionPayment*.php'
---

# Feature

## Arc tuition payments: the fee comes out of the sender, goes to the network
Arc pays gas in NATIVE USDC, so a transfer's fee is taken from the SENDER's wallet on top of the amount, and it goes to the Arc network's validators — not to Circle, not to the institution treasury, and not to EduFlow. The recipient receives exactly the amount. A wallet therefore holds amount + fee or it cannot send: the payment service quotes the fee first and refuses an amount the balance cannot cover once the fee is added.

The fee is denominated at 18 decimals, so it is NEVER stored as an integer of base units or passed through a float. It is carried as an exact decimal string from `networkFee` in the transfer's raw CLI payload (falling back to the pre-flight `--estimate` quote), and StudentTuitionPayment.network_fee is a string column for that reason. Fees of ~0.03 USDC overflow 6-decimal base-unit arithmetic in neither direction — either loses precision.

The recipient is read from `config('lepton.arc.treasury')` (LEPTON_TREASURY_ADDRESS) on the server and is never taken from the request, so a crafted form cannot redirect tuition. The student is always the authenticated user's own record, resolved through the `student()` relation; the tuition account is looked up on that student's accounts, so another student's account is a 404 rather than an unauthorised write.

A returned tx hash is a claim, not proof: the payment is verified against the chain before the row is written, and the amount plus the fee are recorded only after the chain confirms it.

`circle wallet transfer` is not wrapped by Lepton; it is called through WalletGateway::transfer() with the student's address as the sender, which is why StudentTuitionPaymentService does NOT reuse CircleWalletService::executePaymentBaseUnits (that debits the organization's own Wallet row). The treasury ledger row written alongside is the institution's side only; the student's receipt is the StudentTuitionPayment row.

A payment smaller than what is owed reduces paid_amount and leaves the remainder as student debt — the institution receives the full amount transferred, and the fee is a separate cost borne by the student.
