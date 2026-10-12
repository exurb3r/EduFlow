<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\Wallet;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

/**
 * Read-only institution review. No reservation, approval or settlement authority.
 */
final readonly class InstitutionFinancePreview
{
    private const INVOICE_LIMIT = 100;

    public function __construct(
        private InstallationInstitution $institutions,
        private TreasuryForecastService $forecasts,
        private FinancialPolicyEngine $policies,
        private ArcNetworkGateway $arc,
    ) {}

    /** @return array<string, mixed> */
    public function handle(?Organization $organization = null, int $daysAhead = 30): array
    {
        if ($daysAhead < 1 || $daysAhead > 90) {
            throw new InvalidArgumentException('Preview horizon must be between 1 and 90 days.');
        }

        $institution = $this->institutions->require();

        if ($organization instanceof Organization && (! $organization->exists || $organization->getKey() !== $institution->getKey())) {
            throw new RuntimeException('The preview institution does not match the installation.');
        }

        $wallet = $institution->primaryWallet();
        $ledgerBalance = $wallet instanceof Wallet ? $this->money($wallet, 'balance') : null;
        if (CurrencyCode::tryFrom($institution->currency) === null) {
            throw new RuntimeException('Institution reporting currency is unsupported; preview refused.');
        }

        /** @var Collection<int, Invoice> $invoices */
        $invoices = Invoice::query()
            ->where('organization_id', $institution->id)
            ->whereIn('status', ['pending', 'held', 'escalated'])
            ->with(['vendor', 'budget'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::INVOICE_LIMIT + 1)
            ->get();

        $truncated = $invoices->count() > self::INVOICE_LIMIT;
        $invoices = $invoices->take(self::INVOICE_LIMIT);
        $observation = $wallet instanceof Wallet
            ? $this->observe($wallet, $ledgerBalance)
            : ['state' => 'not_configured', 'is_simulated' => false, 'error' => 'No active institution treasury wallet is configured.'];

        $forecast = null;
        if ($wallet instanceof Wallet && $institution->currency === CurrencyCode::USDC->value) {
            $legacyForecast = $this->forecasts->forecast($institution, $wallet, $daysAhead);
            $forecast = [
                'source' => 'legacy_stored_ledger',
                'indicative_only' => true,
                'window_days' => $daysAhead,
                'health_status' => $legacyForecast->healthStatus,
                'days_until_reserve_breach' => $legacyForecast->daysUntilReserveBreach,
            ];
        }

        $reviews = [];
        foreach ($invoices as $invoice) {
            $reviews[] = $this->review($invoice, $institution, $wallet);
        }

        return [
            'institution_id' => $institution->id,
            'mode' => 'preview_only',
            'can_execute' => false,
            'funds_reserved' => false,
            'payments_submitted' => 0,
            'activity' => $reviews === [] ? 'no_op' : 'review',
            'treasury' => [
                'wallet_id' => $wallet?->id,
                'stored_balance' => $ledgerBalance?->jsonSerialize(),
                'observation' => $observation,
            ],
            'policy' => [
                'reporting_currency' => $institution->currency,
                'currency_interpretation' => 'legacy_USDC_unconverted',
                'minimum_reserve' => $this->money($institution, 'minimum_reserve')->jsonSerialize(),
                'max_auto_payment' => $this->money($institution, 'max_auto_payment')->jsonSerialize(),
                'max_daily_disbursement' => $this->money($institution, 'max_daily_disbursement')->jsonSerialize(),
                'source' => 'legacy_institution_settings',
            ],
            'forecast' => $forecast,
            'invoice_reviews' => $reviews,
            'truncated' => $truncated,
            'limitations' => [
                'Independent indicative reviews, not a cumulatively authorized payment plan.',
                'Legacy policy and forecast calculations use stored ledger balances and float APIs; exact-money migration and reservations remain required.',
                'A balance observation does not prove custody, signing readiness, beneficiary ownership or payment settlement.',
                'No student, tuition account, assistance fund, assistance policy or AI provider is required.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function review(Invoice $invoice, Organization $institution, ?Wallet $wallet): array
    {
        $result = [
            'invoice_id' => $invoice->id,
            'reference' => $invoice->reference,
            'status' => $invoice->status,
            'amount' => $this->money($invoice, 'amount')->jsonSerialize(),
            'currency_interpretation' => 'legacy_USDC_unconverted',
            'indicative_only' => true,
            'can_execute' => false,
        ];

        /** @var Vendor|null $vendor */
        $vendor = $invoice->vendor;
        $budget = $invoice->budget;
        $reason = match (true) {
            $institution->currency !== CurrencyCode::USDC->value => 'Reporting currency differs from legacy USDC monetary fields; reviewed denomination and conversion are required.',
            ! $wallet instanceof Wallet => 'No active institution treasury wallet is configured.',
            $vendor === null || $vendor->organization_id !== $institution->id => 'Vendor is missing or belongs to another institution.',
            $invoice->budget_id !== null && ($budget === null || $budget->organization_id !== $institution->id) => 'Budget is missing or belongs to another institution.',
            $this->money($invoice, 'amount')->minorUnits <= 0 => 'Invoice amount must be positive.',
            preg_match('/^0x[0-9a-fA-F]{40}$/D', $vendor->wallet_address) !== 1 || preg_match('/^0x0{40}$/D', $vendor->wallet_address) === 1 => 'No valid nonzero vendor payout address is recorded.',
            default => null,
        };

        if ($reason !== null) {
            return array_merge($result, ['review' => 'blocked', 'reason' => $reason]);
        }

        $evaluation = $this->policies->evaluateInvoice($invoice, $wallet);

        return array_merge($result, [
            'review' => $evaluation->decision->value,
            'policy_code' => $evaluation->policyCode,
            'requires_human_approval' => $evaluation->requiresHumanApproval,
            'reason' => $evaluation->reasoning,
            'checks' => $evaluation->checks,
        ]);
    }

    /** @return array<string, mixed> */
    private function observe(Wallet $wallet, Money $ledgerBalance): array
    {
        $isSimulated = $this->arc instanceof FakeLeptonGateway;
        $unavailable = ['state' => 'unavailable', 'is_simulated' => $isSimulated];
        $address = $wallet->address;

        if ($wallet->provider !== 'circle' || preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) !== 1 || preg_match('/^0x0{40}$/D', $address) === 1) {
            return array_merge($unavailable, ['error' => 'Treasury must be a recorded Circle wallet with a valid nonzero address.']);
        }

        try {
            $chain = $this->arc->chainCode();
            $expectedChainId = match ($chain) {
                'ARC' => 5042,
                'ARC-TESTNET' => 5042002,
                default => null,
            };
            $configuredAddress = $this->arc->treasuryAddress();

            if (($configuredAddress !== null && $configuredAddress !== '' && strcasecmp($configuredAddress, $address) !== 0)
                || ! in_array($wallet->network, ['arc', strtolower($chain), $chain === 'ARC' ? 'arc-mainnet' : 'arc-testnet'], true)) {
                return array_merge($unavailable, ['error' => 'Treasury address or wallet network does not match the configured Arc gateway.']);
            }

            $rpcChainId = $this->arc->rpc('eth_chainId');
            if ($expectedChainId === null || $this->arc->chainId() !== $expectedChainId
                || ChainQuantity::toInt($rpcChainId) !== $expectedChainId) {
                return array_merge($unavailable, ['error' => 'Arc RPC chain identity does not match the configured network.']);
            }

            // Normalised before it is echoed back as an RPC block tag, so a
            // colourised CLI reply can never be handed to eth_getBalance.
            $block = ChainQuantity::normalize($this->arc->blockNumber());
            $blockNumber = ChainQuantity::toDecimalString($block, 0);
            $balance = $this->arc->rpc('eth_getBalance', [$address, $block ?? 'latest']);

            $nativeUnits = ChainQuantity::toDecimalString($balance, 0);

            if ($blockNumber === null || $nativeUnits === null) {
                return array_merge($unavailable, ['error' => 'Arc balance observation is unavailable.']);
            }
            $observedUnits = BigInteger::of($nativeUnits);
            [$paymentUnits, $residual] = $observedUnits->quotientAndRemainder('1000000000000');
            $drift = BigInteger::of($ledgerBalance->minorUnits)->multipliedBy('1000000000000')->minus($observedUnits);

            return [
                'state' => $isSimulated ? 'simulated' : 'observed',
                'is_simulated' => $isSimulated,
                'chain' => $chain,
                'chain_id' => $expectedChainId,
                'block_number' => $blockNumber,
                'observed_at' => now()->toIso8601String(),
                'currency' => CurrencyCode::USDC->value,
                'native_units' => $nativeUnits,
                'native_decimals' => 18,
                'decimal' => (string) BigDecimal::ofUnscaledValue($observedUnits, 18),
                'payment_minor_units' => (string) $paymentUnits,
                'payment_decimals' => 6,
                'residual_native_units' => (string) $residual,
                'ledger_drift_native_units' => (string) $drift,
                'settlement_verified' => false,
            ];
        } catch (Throwable) {
            return array_merge($unavailable, ['error' => 'Arc observation failed; no balance or settlement was inferred.']);
        }
    }

    private function money(Organization|Wallet|Invoice $record, string $attribute): Money
    {
        $amount = $record->getRawOriginal($attribute);

        if (! is_string($amount) && ! is_int($amount)) {
            throw new RuntimeException('Stored money must be an exact decimal string or integer; preview refused.');
        }

        return Money::fromDecimal((string) $amount, CurrencyCode::USDC);
    }
}
