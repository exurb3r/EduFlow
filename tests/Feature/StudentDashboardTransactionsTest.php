<?php

declare(strict_types=1);

use App\Models\AcademicTerm;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPayment;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);
    config(['eduflow.display_currency' => 'PHP']);
    Cache::flush();

    foreach (['student', 'finance_officer', 'admin', 'super_admin', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

test('student dashboard aggregates recent transactions across payouts, tuition and bills', function (): void {
    $address = '0x'.str_repeat('a', 40);
    $user = User::factory()->create();
    $user->assignRole('student');
    $student = Student::factory()->for($user)->create(['payout_address' => $address]);

    app(FakeLeptonGateway::class)->seedBalance($address, 50000000);

    $term = AcademicTerm::factory()->create([
        'starts_on' => today()->subMonth(),
        'ends_on' => today()->addMonths(3),
    ]);

    $account = TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => 300000000,
        'paid_amount' => 25000000,
    ]);

    $wallet = StudentWallet::factory()->create([
        'student_id' => $student->id,
        'address' => $address,
    ]);

    $org = Organization::factory()->create();

    // 1. Inbound payout transaction (disbursement)
    Transaction::factory()->create([
        'organization_id' => $org->id,
        'recipient_address' => $address,
        'type' => 'student_assistance',
        'amount' => 50.00,
        'currency' => 'USDC',
        'status' => 'confirmed',
        'provider_tx_hash' => '0x1111111111111111111111111111111111111111111111111111111111111111',
        'executed_at' => now()->subHours(3),
    ]);

    // 2. Outbound tuition payment
    StudentTuitionPayment::create([
        'student_id' => $student->id,
        'tuition_account_id' => $account->id,
        'student_wallet_id' => $wallet->id,
        'amount_base_units' => 25000000,
        'applied_base_units' => 25000000,
        'overpayment_base_units' => 0,
        'network_fee' => '0.00805',
        'payer_address' => $address,
        'recipient_address' => '0x'.str_repeat('b', 40),
        'chain' => 'ARC-TESTNET',
        'tx_hash' => '0x2222222222222222222222222222222222222222222222222222222222222222',
        'tx_verified_on_chain' => true,
        'simulated' => false,
        'executed_at' => now()->subHours(2),
    ]);

    // 3. Outbound campus bill payment
    StudentBillPayment::factory()
        ->for($student)
        ->for($wallet)
        ->create([
            'organization_id' => $org->id,
            'amount_base_units' => 5000000,
            'payer_address' => $address,
            'chain' => 'ARC-TESTNET',
            'tx_hash' => '0x3333333333333333333333333333333333333333333333333333333333333333',
            'tx_verified_on_chain' => true,
            'submission_state' => 'verified',
            'executed_at' => now()->subHour(),
        ]);

    $this->actingAs($user)->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('student/dashboard')
            ->has('recentTransactions', 3)
            // Most recent is the bill payment (1 hr ago)
            ->where('recentTransactions.0.direction', 'outbound')
            ->where('recentTransactions.0.amount', fn ($amt): bool => (float) $amt === 5.0)
            ->where('recentTransactions.0.type', 'bill_payment')
            ->where('recentTransactions.0.status', 'confirmed')
            ->where('recentTransactions.0.is_verified', true)
            ->where('recentTransactions.0.tx_hash', '0x3333333333333333333333333333333333333333333333333333333333333333')
            ->where('recentTransactions.0.explorer_url', 'https://testnet.arcscan.app/tx/0x3333333333333333333333333333333333333333333333333333333333333333')
            ->where('recentTransactions.0.amount_fiat', fn ($fiat): bool => is_string($fiat) && str_contains($fiat, 'PHP'))
            // Second is tuition payment (2 hrs ago)
            ->where('recentTransactions.1.direction', 'outbound')
            ->where('recentTransactions.1.amount', fn ($amt): bool => (float) $amt === 25.0)
            ->where('recentTransactions.1.type', 'tuition_payment')
            ->where('recentTransactions.1.tx_hash', '0x2222222222222222222222222222222222222222222222222222222222222222')
            // Third is disbursement payout (3 hrs ago)
            ->where('recentTransactions.2.direction', 'inbound')
            ->where('recentTransactions.2.amount', fn ($amt): bool => (float) $amt === 50.0)
            ->where('recentTransactions.2.type', 'student_assistance')
            ->where('recentTransactions.2.tx_hash', '0x1111111111111111111111111111111111111111111111111111111111111111')
        );
});

test('student with no transactions receives an empty recentTransactions list', function (): void {
    $user = User::factory()->create();
    $user->assignRole('student');
    $student = Student::factory()->for($user)->create([
        'payout_address' => '0x'.str_repeat('e', 40),
    ]);

    $this->actingAs($user)->get(route('student.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('student/dashboard')
            ->has('recentTransactions', 0)
        );
});
