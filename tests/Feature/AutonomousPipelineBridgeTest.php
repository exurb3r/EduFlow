<?php

declare(strict_types=1);

use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\Jobs\AssessInstitutionPolicy;
use App\Models\InstitutionPolicyAssessment;
use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionOutbox;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Ai\AiProviderResolver;
use App\Services\AutonomousPipelineBridge;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyInterpreter;
use App\Services\InstitutionPolicyWorkspace;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\PaymentSettlementReconciler;
use App\Services\StudentTuitionPaymentProcessor;
use App\Services\StudentTuitionPaymentService;
use App\Settings\AiSettings;
use Brick\Math\BigInteger;
use Illuminate\Support\Str;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

use function Pest\Laravel\travelTo;

function setupTestPublishedPolicy(array $c): void
{
    $input = [
        'request_key' => (string) Str::uuid(),
        'title' => 'Reviewed institution purchasing policy',
        'predecessor_id' => null,
        'expected_current_digest' => null,
        'clauses' => [['id' => 'purchases', 'title' => 'IT obligations', 'text' => 'Reviewed IT service bills belong to Technology Operations.']],
        'categories' => [[
            'id' => 'technology',
            'name' => 'Technology Operations',
            'description' => 'Approved IT services.',
            'inclusions' => 'Service invoices',
            'exclusions' => 'Personal purchases',
            'clause_ids' => ['purchases'],
        ]],
        'examples' => [['description' => 'Approved IT service invoice', 'category_id' => 'technology']],
        'controls' => ['minimum_reserve' => '0', 'max_auto_payment' => '100', 'max_daily_disbursement' => '500', 'max_fee' => '2'],
        'priorities' => 'Review near-due approved bills first.',
        'clarifications' => [],
    ];
    $workspace = app(InstitutionPolicyWorkspace::class);
    $policy = $workspace->save($c['maker'], $input);
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    $answer = [
        'category_id' => 'technology',
        'citations' => [['clause_id' => 'purchases', 'quote' => 'Reviewed IT service bills belong to Technology Operations.']],
        'explanation' => 'The institution categorizes approved IT service invoices as Technology Operations.',
        'questions' => [],
        'missing_evidence' => [],
        'priority' => 'high',
        'needs_review' => false,
    ];
    InstitutionPolicyAnalyst::fake([$answer, $answer, $answer, $answer, $answer, $answer, $answer, $answer]);
    config(['queue.connections.database.retry_after' => 360]);

    foreach (['authoring', 'examples'] as $kind) {
        $assessment = InstitutionPolicyAssessment::query()->create([
            'organization_id' => $policy->organization_id,
            'institution_policy_version_id' => $policy->id,
            'requested_by' => $c['maker']->id,
            'request_key' => (string) Str::uuid(),
            'kind' => $kind,
            'input' => [],
            'input_digest' => PaymentIntent::digest([]),
            'policy_digest' => $policy->content_digest,
        ]);
        (new AssessInstitutionPolicy($assessment->id))->handle(
            app(InstitutionPolicyInterpreter::class),
            app(InstallationInstitution::class),
            app(AiProviderResolver::class)
        );
    }
    $workspace->publish($c['reviewer'], $policy, $policy->content_digest, null, 'Authored policy and exact finance controls independently checked.');
}

test('autonomous pipeline bridge reconciles tuition and automatically disburses to approved vendor on arc testnet', function (): void {
    travelTo(now()->utc()->startOfSecond());
    $c = paymentAuthorizationContext(withFunding: false);
    setupTestPublishedPolicy($c);
    PaymentIntent::query()->where('organization_id', $c['institution']->id)->delete();

    config([
        'eduflow.autonomous_pipeline.enabled' => true,
        'eduflow.autonomous_pipeline.auto_disburse' => true,
        'eduflow.autonomous_pipeline.max_auto_disburse_usdc' => 50.00,
        'eduflow.autonomous_pipeline.maker_id' => $c['maker']->id,
        'eduflow.autonomous_pipeline.reviewer_id' => $c['reviewer']->id,
        'eduflow.student_tuition.intake_owner_id' => $c['maker']->id,
        'eduflow.submission.enabled' => true,
        'eduflow.submission.transport' => 'circle_agent_wallet',
        'lepton.default' => 'circle',
        'lepton.arc.treasury' => $c['wallet']->address,
    ]);

    // Create a student tuition payment on Arc testnet for 25.000001 USDC
    $payer = consentContext($c['institution']);
    config(['lepton.arc.treasury' => $c['wallet']->address]);
    $payer['student']->wallet->update(['address' => '0x'.str_repeat('3', 40)]);
    $payer['student']->update(['payout_address' => '0x'.str_repeat('3', 40)]);
    $payer['student']->refresh();
    $payer['arc'] = tuitionArcGateway(200_000000);
    app()->instance(ArcNetworkGateway::class, $payer['arc']);

    $tuitionService = app(StudentTuitionPaymentService::class);
    $quote = $tuitionService->quote($payer['student'], $payer['account'], 30_000000);
    $attempt = $tuitionService->pay($payer['student'], $payer['account'], 30_000000, $quote['request_key'], $quote['request_digest'], true);

    mockExpectation($payer['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    mockExpectation($payer['transport'], 'resolve')->andReturn(TransportResolution::completed('0x'.str_repeat('e', 64)));
    mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionReceipt', ['0x'.str_repeat('e', 64)])->andReturn([
        'transactionHash' => '0x'.str_repeat('e', 64), 'blockNumber' => '0x64', 'status' => '0x1',
        'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => [],
    ]);
    mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionByHash', ['0x'.str_repeat('e', 64)])->andReturn([
        'from' => $attempt->payer_address, 'to' => $attempt->recipient_address,
        'value' => '0x'.BigInteger::of('30000000000000000000')->toBase(16), 'blockNumber' => '0x64',
    ]);
    mockExpectation($payer['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);

    $processor = app(StudentTuitionPaymentProcessor::class);
    $processor->process($attempt->id);
    $processor->process($attempt->id);

    $tuitionPayment = StudentTuitionPayment::query()->where('request_key', $attempt->request_key)->firstOrFail();
    expect($tuitionPayment->tx_verified_on_chain)->toBeTrue();

    // Now stub the Treasury Arc balance for funding window & reservation
    stubPaymentAuthorizationBalance('100000000000000000007');

    // Script transport and stubArc for the vendor payment submission
    $disbursedHash = '0x'.str_repeat('d', 64);
    scriptedTransport(
        fn (): TransportAcceptance => new TransportAcceptance('226c7ce7-8f2f-53ac-8b06-f18aac151ce4'),
        fn (): TransportResolution => TransportResolution::completed($disbursedHash),
    );

    // Run the Autonomous Pipeline Bridge on the tuition payment
    $bridge = app(AutonomousPipelineBridge::class);
    $bill = $c['drafts'][0]->invoice;

    // First draft bill is 25.000001 USDC, which is <= 50 USDC
    $telemetry = $bridge->bridgePayment($tuitionPayment, $bill->id);

    expect($telemetry['success'])->toBeTrue()
        ->and($telemetry['stage'])->toBe('disbursement_submitted')
        ->and($telemetry['payment_id'])->toBe($tuitionPayment->id)
        ->and($telemetry['invoice_id'])->toBe($bill->id)
        ->and($telemetry['outbox_state'])->toBe('submitted')
        ->and($telemetry['provider_handle'])->toBe('226c7ce7-8f2f-53ac-8b06-f18aac151ce4');

    // Now reconcile settlement to complete on-chain verification
    $outbox = PaymentSubmissionOutbox::query()->findOrFail($telemetry['submission_outbox_id']);
    stubArc(settledReceiptFor($outbox));

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($outbox);
    expect($verdict['settled'])->toBeTrue();

    app(PaymentSettlementReconciler::class)->mirrorSettlement($outbox, $verdict);
    $bill->update(['status' => 'paid']);

    expect($bill->fresh()->status)->toBe('paid')
        ->and(Transaction::query()->where('recipient_address', $telemetry['vendor_payout_address'])->exists())->toBeTrue();
});

test('autonomous pipeline pauses when bill exceeds max_auto_disburse_usdc threshold', function (): void {
    travelTo(now()->utc()->startOfSecond());
    $c = paymentAuthorizationContext(withFunding: false);
    setupTestPublishedPolicy($c);

    config([
        'eduflow.autonomous_pipeline.enabled' => true,
        'eduflow.autonomous_pipeline.auto_disburse' => true,
        'eduflow.autonomous_pipeline.max_auto_disburse_usdc' => 10.00, // Ceiling is 10 USDC
        'eduflow.autonomous_pipeline.maker_id' => $c['maker']->id,
        'eduflow.autonomous_pipeline.reviewer_id' => $c['reviewer']->id,
        'eduflow.student_tuition.intake_owner_id' => $c['maker']->id,
        'lepton.arc.treasury' => $c['wallet']->address,
    ]);

    $payer = consentContext($c['institution']);
    config(['lepton.arc.treasury' => $c['wallet']->address]);
    $payer['student']->wallet->update(['address' => '0x'.str_repeat('3', 40)]);
    $payer['student']->update(['payout_address' => '0x'.str_repeat('3', 40)]);
    $payer['student']->refresh();
    $payer['arc'] = tuitionArcGateway(200_000000);
    app()->instance(ArcNetworkGateway::class, $payer['arc']);

    $tuitionService = app(StudentTuitionPaymentService::class);
    $quote = $tuitionService->quote($payer['student'], $payer['account'], 25_000001);
    $attempt = $tuitionService->pay($payer['student'], $payer['account'], 25_000001, $quote['request_key'], $quote['request_digest'], true);

    mockExpectation($payer['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    mockExpectation($payer['transport'], 'resolve')->andReturn(TransportResolution::completed('0x'.str_repeat('e', 64)));
    mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionReceipt', ['0x'.str_repeat('e', 64)])->andReturn([
        'transactionHash' => '0x'.str_repeat('e', 64), 'blockNumber' => '0x64', 'status' => '0x1',
        'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => [],
    ]);
    mockExpectation($payer['arc'], 'rpc')->with('eth_getTransactionByHash', ['0x'.str_repeat('e', 64)])->andReturn([
        'from' => $attempt->payer_address, 'to' => $attempt->recipient_address,
        'value' => '0x'.BigInteger::of('25000001000000000000')->toBase(16), 'blockNumber' => '0x64',
    ]);
    mockExpectation($payer['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);

    $processor = app(StudentTuitionPaymentProcessor::class);
    $processor->process($attempt->id);
    $processor->process($attempt->id);

    $receipt = StudentTuitionPayment::query()->where('request_key', $attempt->request_key)->firstOrFail();

    // Now stub the Treasury Arc balance for funding window & reservation
    stubPaymentAuthorizationBalance('100000000000000000007');

    $bridge = app(AutonomousPipelineBridge::class);
    $bill = $c['drafts'][0]->invoice; // 25.000001 USDC bill

    $telemetry = $bridge->bridgePayment($receipt, $bill->id);

    expect($telemetry['success'])->toBeTrue()
        ->and($telemetry['stage'])->toBe('awaiting_manual_authorization')
        ->and($telemetry['invoice_id'])->toBe($bill->id)
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0);
});
