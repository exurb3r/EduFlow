<?php

declare(strict_types=1);

use App\Ai\Agents\SettlementOperator;
use App\Ai\Agents\SettlementOperatorFactory;
use App\Ai\Tools\DisburseAssistance;
use App\Ai\Tools\InspectInstitutionFinance;
use App\Ai\Tools\RecordHardshipContext;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Services\InstitutionFinancePreview;
use Laravel\Ai\Tools\Request;

function operatorInstitution(): Organization
{
    config(['eduflow.institution_id' => null]);

    /** @var Organization $institution */
    $institution = Organization::factory()->create();

    return $institution;
}

function operatorFund(Organization $institution, string $status = 'active'): AssistanceFund
{
    return AssistanceFund::query()->create([
        'organization_id' => $institution->id,
        'name' => 'Aid',
        'balance_base_units' => 100_000000,
        'reserve_threshold_base_units' => 0,
        'daily_budget_base_units' => 100_000000,
        'status' => $status,
    ]);
}

function operatorAidPolicy(?Organization $institution = null, bool $active = true): AssistancePolicyVersion
{
    return AssistancePolicyVersion::query()->create([
        'organization_id' => $institution?->id,
        'version' => 'v1',
        'auto_limit_base_units' => 10_000000,
        'semester_cap_base_units' => 100_000000,
        'min_attendance_rate' => 85,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => $active,
    ]);
}

test('operator factory needs institution identity but no students fund or aid policy', function (): void {
    $institution = operatorInstitution();
    $operator = SettlementOperatorFactory::makeOrFail();
    $tools = iterator_to_array((function () use ($operator): Generator {
        yield from $operator->tools();
    })());

    expect($operator->hasAssistanceCapability())->toBeFalse()
        ->and($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(InspectInstitutionFinance::class)
        ->and((string) $operator->instructions())->toContain('does not depend on students');

    $result = $tools[0]->handle(new Request([]));
    $report = json_decode((string) $result, true, flags: JSON_THROW_ON_ERROR);
    expect($report['institution_id'])->toBe($institution->id)
        ->and($report['can_execute'])->toBeFalse()
        ->and($report['activity'])->toBe('no_op');

    foreach ([Student::class, TuitionAccount::class, AssistanceFund::class, AssistancePolicyVersion::class, Transaction::class] as $model) {
        expect($model::query()->count())->toBe(0);
    }
});

test('complete assistance setup adds aid tools without making them institution prerequisites', function (): void {
    $institution = operatorInstitution();
    operatorFund($institution);
    operatorAidPolicy($institution);

    $operator = SettlementOperatorFactory::makeOrFail();
    $tools = collect($operator->tools());

    expect($operator->hasAssistanceCapability())->toBeTrue()
        ->and($tools->map(fn (object $tool): string => $tool::class)->all())->toBe([
            InspectInstitutionFinance::class,
            DisburseAssistance::class,
            RecordHardshipContext::class,
        ]);
});

test('incomplete or inactive aid setup leaves an institution-only operator', function (string $setup): void {
    $institution = operatorInstitution();

    if (in_array($setup, ['fund_only', 'inactive_policy'], true)) {
        operatorFund($institution);
    }
    if ($setup === 'inactive_fund') {
        operatorFund($institution, 'inactive');
    }
    if (in_array($setup, ['policy_only', 'inactive_fund', 'inactive_policy'], true)) {
        operatorAidPolicy($institution, $setup !== 'inactive_policy');
    }

    $operator = SettlementOperatorFactory::makeOrFail();
    expect($operator->hasAssistanceCapability())->toBeFalse()
        ->and(collect($operator->tools())->map(fn (object $tool): string => $tool::class)->all())->toBe([InspectInstitutionFinance::class]);
})->with(['fund_only', 'policy_only', 'inactive_fund', 'inactive_policy']);

test('directly supplied foreign or unpersisted aid context cannot expose a payment tool', function (): void {
    $institution = operatorInstitution();
    /** @var Organization $foreign */
    $foreign = Organization::factory()->create();
    $fund = operatorFund($foreign);
    $policy = operatorAidPolicy($foreign);
    $operator = new SettlementOperator($institution, $fund, $policy);

    expect($operator->hasAssistanceCapability())->toBeFalse()
        ->and(collect($operator->tools()))->toHaveCount(1);

    $operator = new SettlementOperator($institution, new AssistanceFund, new AssistancePolicyVersion);
    expect($operator->hasAssistanceCapability())->toBeFalse()
        ->and(collect($operator->tools()))->toHaveCount(1);
});

test('factory refuses missing ambiguous foreign and unpersisted institution identity', function (): void {
    config(['eduflow.institution_id' => null]);
    expect(SettlementOperatorFactory::make())->toBeNull();

    $institution = operatorInstitution();
    $foreign = clone $institution;
    $foreign->id = $institution->id + 1;
    expect(SettlementOperatorFactory::make($foreign))->toBeNull()
        ->and(SettlementOperatorFactory::make(new Organization))->toBeNull();

    Organization::factory()->create();
    expect(SettlementOperatorFactory::make($institution))->toBeNull();
});

test('inspection tool cannot switch institutions through model-supplied arguments', function (): void {
    $institution = operatorInstitution();
    $tool = new InspectInstitutionFinance(app(InstitutionFinancePreview::class), $institution);
    $result = $tool->handle(new Request(['organization_id' => 999, 'recipient' => '0x'.str_repeat('9', 40), 'amount' => 1000000]));
    $report = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

    expect($report['institution_id'])->toBe($institution->id)
        ->and($report['payments_submitted'])->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

test('an inspection tool with stale institution identity fails closed', function (): void {
    $institution = operatorInstitution();
    $tool = new InspectInstitutionFinance(app(InstitutionFinancePreview::class), $institution);
    $institution->delete();
    Organization::factory()->create();

    expect($tool->handle(new Request([])))->toContain('Preview unavailable')
        ->and(Transaction::query()->count())->toBe(0);
});
