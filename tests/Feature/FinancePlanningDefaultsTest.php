<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\CaptureCollectionBatch;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\PrepareVendorPayment;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Jobs\AssessInstitutionPolicy;
use App\Jobs\EvaluateInstitutionPolicy;
use App\Jobs\ProcessFinanceWorkflow;
use App\Models\FinancePolicyActivation;
use App\Models\FinanceWorkflowRun;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentAuthorization;
use App\Models\PaymentReservation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceReviewAlerts;
use App\Services\FinanceWorkflowDispatcher;
use App\Services\FinanceWorkflows;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

test('finance planning ignores legacy opt in environment and inherits the default queue', function (): void {
    $previous = getenv('EDUFLOW_BACKGROUND_FINANCE');
    putenv('EDUFLOW_BACKGROUND_FINANCE=false');
    try {
        $settings = require config_path('eduflow.php');
    } finally {
        putenv($previous === false ? 'EDUFLOW_BACKGROUND_FINANCE' : 'EDUFLOW_BACKGROUND_FINANCE='.$previous);
    }
    expect($settings['background_finance']['enabled'])->toBeTrue();
    foreach ([new ProcessFinanceWorkflow(1), new AssessInstitutionPolicy(1), new EvaluateInstitutionPolicy(1)] as $job) {
        expect($job->connection)->toBeNull()->and($job->queue)->toBeNull()->and($job->afterCommit)->toBeTrue();
    }

    config(['queue.default' => 'database', 'queue.connections.database.queue' => 'default',
        'eduflow.background_finance.enabled' => $settings['background_finance']['enabled'],
        'eduflow.background_finance.queue_connection' => 'sync',
        'eduflow.background_finance.queue' => 'obsolete-planning-queue']);
    Bus::fake();
    Organization::factory()->create();
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $batch = app(CaptureCollectionBatch::class)->handle($maker, [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-fees', 'source_reference' => 'TEST-DEFAULT-QUEUE',
        'source_document_digest' => hash('sha256', 'synthetic-default-queue-proof'), 'currency' => 'USDC',
        'received_amount' => '5.000001', 'restricted_amount' => '0',
        'collected_from' => now()->subDays(2)->toIso8601String(), 'collected_until' => now()->subDay()->toIso8601String(),
        'cash_evidence_reference' => 'Synthetic test receipt', 'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);
    $run = FinanceWorkflowRun::query()->where('collection_batch_id', $batch->id)->sole();
    app(FinanceWorkflowDispatcher::class)->dispatchRun($run->id);
    Bus::assertDispatched(ProcessFinanceWorkflow::class, fn (ProcessFinanceWorkflow $job): bool => $job->runId === $run->id
        && $job->connection === null && $job->queue === null && $job->afterCommit === true);

    (new ProcessFinanceWorkflow($run->id))->handle(app(FinanceWorkflows::class), app(FinanceReviewAlerts::class));
    expect($run->refresh()->state)->toBe('waiting_for_review')
        ->and($run->hasValidResult())->toBeTrue()
        ->and($run->result['received_minor_units'])->toBe('5000001')
        ->and($run->result['can_execute'])->toBeFalse()
        ->and(PaymentReservation::query()->count())->toBe(0)
        ->and(PaymentAuthorization::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

test('zero fee policy permits a zero fee vendor draft without creating payment authority', function (): void {
    $context = paymentAuthorizationContext(withFunding: false);
    $zero = new Money(0, CurrencyCode::USDC);
    $policy = app(CreateFinancePolicyVersion::class)->handle($context['maker'], 'sponsored-zero-fee-policy', $zero, $zero, $zero, $zero);
    app(ActivateFinancePolicy::class)->handle($context['reviewer'], $policy, FinancePolicyActivation::current($context['institution']->id)->id);
    $invoice = Invoice::query()->create([
        'organization_id' => $context['institution']->id, 'vendor_id' => $context['vendor']->id, 'budget_id' => $context['budget']->id,
        'reference' => 'TEST-ZERO-FEE-SPONSORED', 'amount' => '5', 'due_date' => now()->addDay(), 'category' => 'software', 'status' => 'pending',
    ]);
    $draft = app(PrepareVendorPayment::class)->handle($context['maker'], $invoice, $context['wallet'], $zero, (string) Str::uuid());
    expect($draft->hasValidSnapshot())->toBeTrue()
        ->and($draft->max_fee_base_units)->toBe(0)
        ->and($draft->amount_base_units)->toBe(5_000000)
        ->and(PaymentAuthorization::query()->count())->toBe(0)
        ->and(PaymentReservation::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});
