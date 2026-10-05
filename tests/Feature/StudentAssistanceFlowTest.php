<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

test('financial assistance page renders policy and history for students', function (): void {
    $user = User::factory()->create([
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);

    $response = $this->actingAs($user)->get(route('financial-assistance.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('financial-assistance')
        ->has('requests')
        ->has('policy')
        ->where('policy.auto_limit', 100)
        ->where('policy.currency', 'USDC')
        ->where('wallet.address', '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678')
    );
});

test('request within the automatic limit is auto-approved and paid out', function (): void {
    $organization = Organization::factory()->create([
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);
    Wallet::factory()->create([
        'organization_id' => $organization->id,
        'balance' => 25420.00,
    ]);
    Budget::factory()->create([
        'organization_id' => $organization->id,
        'category' => 'scholarships',
        'allocated_amount' => 2000.00,
        'remaining_amount' => 2000.00,
    ]);

    $user = User::factory()->create([
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);

    $response = $this->actingAs($user)->post(route('financial-assistance.store'), [
        'amount' => 80,
        'reason' => 'Emergency medicine costs after a clinic visit.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $request = DB::table('student_assistance_requests')
        ->where('user_id', $user->id)
        ->first();

    expect($request)->not->toBeNull();
    expect((float) $request->approved_amount)->toBe(80.0);
    expect($request->status)->toBe('paid');

    $transaction = Transaction::query()
        ->where('reference_type', 'student_assistance_request')
        ->where('reference_id', $request->id)
        ->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->status->value)->toBe('confirmed');
    expect((float) $transaction->amount)->toBe(80.0);
    expect($transaction->provider_tx_hash)->toStartWith('0x');

    $decision = DB::table('agent_decisions')
        ->where('reference_type', 'student_assistance_request')
        ->where('reference_id', $request->id)
        ->first();

    expect($decision)->not->toBeNull();
    expect($decision->decision)->toBe('auto_approve');
    expect($decision->policy_checked)->toBe('EMERGENCY_ASSISTANCE_V1');
});

test('request above the automatic limit is partially approved and escalated', function (): void {
    $organization = Organization::factory()->create([
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);
    Wallet::factory()->create([
        'organization_id' => $organization->id,
        'balance' => 25420.00,
    ]);
    Budget::factory()->create([
        'organization_id' => $organization->id,
        'category' => 'scholarships',
        'allocated_amount' => 2000.00,
        'remaining_amount' => 2000.00,
    ]);

    $user = User::factory()->create([
        'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);

    $response = $this->actingAs($user)->post(route('financial-assistance.store'), [
        'amount' => 150,
        'reason' => 'Replacement laptop charger and one week of meals.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $request = DB::table('student_assistance_requests')
        ->where('user_id', $user->id)
        ->first();

    expect($request)->not->toBeNull();
    expect((float) $request->approved_amount)->toBe(100.0);
    expect($request->status)->toBe('escalated');

    $decision = DB::table('agent_decisions')
        ->where('reference_type', 'student_assistance_request')
        ->where('reference_id', $request->id)
        ->first();

    expect($decision)->not->toBeNull();
    expect($decision->decision)->toBe('partial_approval');
    expect((int) $decision->requires_approval)->toBe(1);

    // Only the auto-approved portion is disbursed.
    $paid = Transaction::query()
        ->where('reference_type', 'student_assistance_request')
        ->where('reference_id', $request->id)
        ->sum('amount');

    expect((float) $paid)->toBe(100.0);
});

test('submission without a linked wallet is rejected', function (): void {
    Organization::factory()->create([
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $user = User::factory()->create([
        'wallet_address' => null,
    ]);

    $response = $this->actingAs($user)->post(route('financial-assistance.store'), [
        'amount' => 50,
        'reason' => 'Emergency medicine costs after a clinic visit.',
    ]);

    $response->assertSessionHasErrors(['wallet_address']);

    expect(DB::table('student_assistance_requests')->count())->toBe(0);
});

test('payments page lists confirmed payouts for the linked wallet', function (): void {
    $organization = Organization::factory()->create([
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);
    $walletAddress = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    $user = User::factory()->create([
        'wallet_address' => $walletAddress,
    ]);

    Transaction::factory()->create([
        'organization_id' => $organization->id,
        'type' => 'student_assistance',
        'recipient_address' => $walletAddress,
        'amount' => 80.00,
        'status' => 'confirmed',
    ]);

    Transaction::factory()->create([
        'organization_id' => $organization->id,
        'type' => 'vendor_payment',
        'recipient_address' => '0x9999999999999999999999999999999999999999',
        'amount' => 500.00,
        'status' => 'confirmed',
    ]);

    $response = $this->actingAs($user)->get(route('payments.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('payments')
        ->has('transactions.data', 1)
        ->where('transactions.data.0.amount', 80)
        ->where('totals.confirmed', 80)
    );
});
