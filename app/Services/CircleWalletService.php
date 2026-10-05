<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\Amounts;

class CircleWalletService
{
    public function __construct(
        private readonly WalletGateway $wallets,
        private readonly ArcNetworkGateway $arc,
    ) {}

    /**
     * Execute a USDC payment on Arc network from the organization's Circle Wallet.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function executePayment(
        Wallet $wallet,
        string $recipientAddress,
        float $amount,
        TransactionType $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = []
    ): Transaction {
        $decimals = (int) config('lepton.usdc_decimals', 6);
        $baseUnits = Amounts::fromDecimalString(number_format($amount, $decimals, '.', ''), $decimals);

        return $this->executePaymentBaseUnits(
            wallet: $wallet,
            recipientAddress: $recipientAddress,
            baseUnits: $baseUnits,
            type: $type,
            referenceType: $referenceType,
            referenceId: $referenceId,
            metadata: $metadata,
        );
    }

    /**
     * Execute a payment in exact base units without float conversion.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function executePaymentBaseUnits(
        Wallet $wallet,
        string $recipientAddress,
        int $baseUnits,
        TransactionType $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = []
    ): Transaction {
        if ($baseUnits <= 0) {
            throw new InvalidArgumentException("Payment base units must be positive, got {$baseUnits}.");
        }

        $decimals = (int) config('lepton.usdc_decimals', 6);
        $amountFloat = (float) Amounts::toDecimalString($baseUnits, $decimals);
        $walletBalanceBaseUnits = Amounts::fromDecimalString(number_format((float) $wallet->balance, $decimals, '.', ''), $decimals);

        if ($walletBalanceBaseUnits < $baseUnits) {
            throw new InvalidArgumentException("Insufficient wallet balance ({$wallet->balance} USDC) for payment of {$amountFloat} USDC.");
        }

        $chain = $this->resolveChain($wallet);
        $rpcUrl = $this->resolveRpcUrl();

        $this->ensureFakeFunds($wallet, $baseUnits);

        $idempotencyKey = $metadata['idempotency_key']
            ?? (isset($metadata['ticket']) ? 'eduflow_ticket_'.preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $metadata['ticket']) : null);

        $transferOptions = array_filter([
            'chain' => $chain,
            'rpcUrl' => $rpcUrl,
            'idempotencyKey' => $idempotencyKey,
        ]);

        $result = $this->wallets->transfer(
            $wallet->address,
            $recipientAddress,
            $baseUnits,
            $transferOptions,
        );

        // Deduct from wallet balance safely
        $newBalanceBaseUnits = max(0, $walletBalanceBaseUnits - $baseUnits);
        $wallet->balance = (float) Amounts::toDecimalString($newBalanceBaseUnits, $decimals);
        $wallet->save();

        // Record on-chain transaction
        return Transaction::create([
            'organization_id' => $wallet->organization_id,
            'wallet_id' => $wallet->id,
            'type' => $type,
            'recipient_address' => $recipientAddress,
            'amount' => $amountFloat,
            'currency' => 'USDC',
            'status' => TransactionStatus::CONFIRMED,
            'provider_tx_hash' => $result->txHash,
            'network' => 'arc',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata' => array_merge($metadata, [
                'provider' => 'lepton',
                'gateway' => $result->isFake ? 'fake' : 'circle-cli',
                'executed_network' => $chain === 'ARC' ? 'arc-mainnet' : 'arc-testnet',
                'settlement_asset' => 'USDC',
                'amount_base_units' => $baseUnits,
                'explorer_url' => $result->explorerUrl,
                'is_fake' => $result->isFake,
            ]),
            'executed_at' => now(),
        ]);
    }

    /**
     * Receive incoming revenue into the organization's Circle Wallet (e.g. Tuition).
     *
     * Inbound simulation: no outgoing chain transfer is made. The ledger is
     * credited locally and marked explicitly as simulated.
     */
    public function receiveRevenue(
        Wallet $wallet,
        float $amount,
        string $senderAddress = '0xstudent_tuition_payer',
        string $note = 'Tuition Revenue Deposit'
    ): Transaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Revenue amount must be positive, got {$amount}.");
        }

        $wallet->balance += $amount;
        $wallet->save();

        return Transaction::create([
            'organization_id' => $wallet->organization_id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::TUITION_REVENUE,
            'recipient_address' => $wallet->address,
            'amount' => $amount,
            'currency' => 'USDC',
            'status' => TransactionStatus::CONFIRMED,
            'provider_tx_hash' => null,
            'network' => 'arc',
            'metadata' => [
                'sender' => $senderAddress,
                'description' => $note,
                'source' => 'student_portal_gateway',
                'simulated_inbound' => true,
                'reference' => 'inbound-'.Str::uuid(),
            ],
            'executed_at' => now(),
        ]);
    }

    public function reconcileBalance(Wallet $wallet): float
    {
        $chain = $this->resolveChain($wallet);

        $result = $this->wallets->balance($wallet->address, ['chain' => $chain]);

        $decimals = (int) config('lepton.usdc_decimals', 6);

        return (float) Amounts::toDecimalString($result->amountBaseUnits, $decimals);
    }

    private function resolveChain(Wallet $wallet): string
    {
        if (in_array($wallet->network, ['ARC-TESTNET', 'ARC', 'arc-testnet', 'arc'], true)) {
            return (string) config('lepton.arc.chain', 'ARC-TESTNET');
        }

        return $wallet->network;
    }

    private function resolveRpcUrl(): ?string
    {
        try {
            $url = $this->arc->rpcUrl();
        } catch (\Throwable) {
            return null;
        }

        return $url !== '' && ! str_starts_with($url, 'fake://') ? $url : null;
    }

    private function ensureFakeFunds(Wallet $wallet, int $neededBaseUnits): void
    {
        if (! $this->wallets instanceof FakeLeptonGateway) {
            return;
        }

        $decimals = (int) config('lepton.usdc_decimals', 6);
        $currentBaseUnits = Amounts::fromDecimalString(number_format($wallet->balance, $decimals, '.', ''), $decimals);

        if ($currentBaseUnits < $neededBaseUnits) {
            return;
        }

        $this->wallets->seedBalance($wallet->address, $currentBaseUnits);
    }
}
