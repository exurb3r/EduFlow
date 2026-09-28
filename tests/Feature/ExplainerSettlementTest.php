<?php

declare(strict_types=1);

use App\Enums\CurrencyCode;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\Wallet;
use App\Services\AskEduFlow;
use App\Services\CurrencyConverter;
use App\Services\DecisionExplainer;
use App\Services\FinancialPolicyEngine;
use App\Services\TuitionSettlementService;
use Database\Seeders\EduFlowPlanSeeder;

beforeEach(function (): void {
    $this->seed(EduFlowPlanSeeder::class);
});

test('explainer produces dual-currency text and valid json', function (): void {
    $engine = new FinancialPolicyEngine;
    $org = Organization::create([
        'name' => 'Explain Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);
    $wallet = Wallet::create([
        'organization_id' => $org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0xexplain'.bin2hex(random_bytes(8)),
        'balance' => 20000.00,
        'status' => 'active',
    ]);

    $result = $engine->evaluateStudentAssistance(150.00, $org, $wallet, null, 100.00);
    $explainer = app(DecisionExplainer::class);

    $text = $explainer->explain($result, 150_000000, CurrencyCode::PHP);
    $json = $explainer->toStructuredJson($result, 150_000000, app(CurrencyConverter::class)->lockQuote(CurrencyCode::PHP));

    expect($text)->toContain('USDC')->and($text)->toContain('PHP')
        ->and($json['decision'])->toBe('partial_approval')
        ->and($json['policy'])->not->toBeEmpty();
});

test('ask eduflow answers balance and policy questions', function (): void {
    $ask = app(AskEduFlow::class);

    expect($ask->answer('What is my tuition balance?', ['tuition_balance_base_units' => 300_000000]))->toContain('USDC')
        ->and($ask->answer("Why didn't you send the full 150 USDC?"))->toContain('100 USDC')
        ->and($ask->answer('What are assistance guidelines?'))->toContain('Auto-limit');
});

test('tuition settlement offsets ledger with integer math', function (): void {
    $org = Organization::create([
        'name' => 'Settle Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 100.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);
    $wallet = Wallet::create([
        'organization_id' => $org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0xsettle'.bin2hex(random_bytes(8)),
        'balance' => 20000.00,
        'status' => 'active',
    ]);
    $student = Student::factory()->create();
    $term = AcademicTerm::factory()->create();
    $account = TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => $term->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);
    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $term->id,
        'requested_amount' => 100_000000,
    ]);

    $tx = app(TuitionSettlementService::class)->settleToTuition(
        $request,
        $account,
        100_000000,
        $wallet,
        app(CurrencyConverter::class)->lockQuote(CurrencyCode::PHP),
    );

    expect($account->fresh()->paid_amount)->toBe(100_000000)
        ->and($tx->metadata['rail'])->toBe('tuition-offset');
});
