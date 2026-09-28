<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Organization;
use App\Models\Wallet;
use App\Services\CircleWalletService;

beforeEach(function (): void {
    $this->circleService = app(CircleWalletService::class);

    $this->org = Organization::create([
        'name' => 'Circle Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $this->wallet = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0xcirclearcaddress',
        'balance' => 1500.00,
        'status' => 'active',
    ]);
});

test('executes payment, decrements balance, and generates on-chain Arc tx hash', function (): void {
    $tx = $this->circleService->executePayment(
        wallet: $this->wallet,
        recipientAddress: '0xrecipient456',
        amount: 300.00,
        type: TransactionType::VENDOR_PAYMENT
    );

    expect($this->wallet->fresh()->balance)->toBe(1200.00)
        ->and($tx->status)->toBe(TransactionStatus::CONFIRMED)
        ->and($tx->network)->toBe('arc')
        ->and($tx->currency)->toBe('USDC')
        ->and($tx->provider_tx_hash)->toStartWith('0x')
        ->and(strlen($tx->provider_tx_hash))->toBe(66); // 0x + 64 hex chars
});

test('receiving revenue increments balance and logs confirmation', function (): void {
    $tx = $this->circleService->receiveRevenue(
        wallet: $this->wallet,
        amount: 5000.00,
        senderAddress: '0xstudent_payer',
        note: 'Fall Semester Tuition'
    );

    expect($this->wallet->fresh()->balance)->toBe(6500.00)
        ->and($tx->type)->toBe(TransactionType::TUITION_REVENUE)
        ->and($tx->amount)->toBe(5000.00)
        ->and($tx->status)->toBe(TransactionStatus::CONFIRMED);
});

test('throws exception when wallet balance is insufficient', function (): void {
    expect(fn () => $this->circleService->executePayment(
        wallet: $this->wallet,
        recipientAddress: '0xrecipient456',
        amount: 2000.00, // wallet has 1500
        type: TransactionType::VENDOR_PAYMENT
    ))->toThrow(InvalidArgumentException::class);
});
