<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PrepareVendorPayment;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:prepare-vendor-payment {invoice : Institution invoice ID} {--actor= : Authorized staff ID attributed to preparation} {--wallet= : Institution Circle wallet ID} {--max-fee=0 : Exact USDC fee ceiling} {--intent-key= : UUID reused for retries of this document}', aliases: ['eduflow:prepare-vendor-payment'])]
#[Description('Prepare an immutable vendor-payment draft; does not approve, reserve or submit funds.')]
class PrepareVendorPaymentDraft extends Command
{
    public function handle(PrepareVendorPayment $prepare, InstallationInstitution $institutions): int
    {
        $invoiceId = filter_var($this->argument('invoice'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $walletId = filter_var($this->option('wallet'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($invoiceId === false || $walletId === false || $actorId === false) {
            $this->error('Positive invoice, wallet and authorized staff IDs are required.');

            return self::FAILURE;
        }

        try {
            $institution = $institutions->require();
            /** @var User $actor */
            $actor = User::query()->whereKey($actorId)->firstOrFail();
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->where('organization_id', $institution->id)->whereKey($invoiceId)->firstOrFail();
            /** @var Wallet $wallet */
            $wallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($walletId)->firstOrFail();
            $intent = $prepare->handle($actor, $invoice, $wallet, Money::fromDecimal((string) $this->option('max-fee'), CurrencyCode::USDC), (string) $this->option('intent-key'));
            $this->line(json_encode($intent->evidence(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Draft preparation refused. Check staff authority, institution ownership, exact amounts and document identity; no payment was submitted.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
