<?php

declare(strict_types=1);

use App\Actions\ApproveEscalatedRequest;
use App\Agents\EduFlowAgent;
use App\Enums\AgentDecisionType;
use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\CircleWalletService;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);
    Http::preventStrayRequests();
    $gateway = new FakeLeptonGateway(treasuryAddress: '0xagentloopwallet', chainCode: 'ARC-TESTNET');
    app()->instance(WalletGateway::class, $gateway);
    app()->instance(ArcNetworkGateway::class, $gateway);
    $this->agent = app(EduFlowAgent::class);

    $this->org = Organization::create([
        'name' => 'Agent Loop Academy',
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
        'address' => '0xagentloopwallet',
        'balance' => 25420.00,
        'status' => 'active',
    ]);

    $this->techBudget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Cloud Tech',
        'category' => 'tech',
        'allocated_amount' => 4000.00,
        'spent_amount' => 0.00,
        'remaining_amount' => 4000.00,
        'status' => 'active',
    ]);

    $this->aidBudget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Student Assistance Fund',
        'category' => 'assistance',
        'allocated_amount' => 2000.00,
        'spent_amount' => 0.00,
        'remaining_amount' => 2000.00,
        'status' => 'active',
    ]);

    $this->cloudVendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'AWS Cloud',
        'wallet_address' => '0xawscloud',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $this->equipmentVendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Lab Equipment',
        'wallet_address' => '0xlabvendor',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);
});

test('autonomous cycle proposes but never initiates payment, and still escalates and holds', function (): void {
    // The agent records proposals and routes them to the authorized workflow.
    // It no longer moves money, so nothing here is auto-paid, budget-drawn or
    // disbursed -- the assertions below exist to prove exactly that.
    // 1. Invoice 450 USDC -> Should auto pay
    $inv1 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->cloudVendor->id,
        'budget_id' => $this->techBudget->id,
        'reference' => 'INV-LOOP-450',
        'amount' => 450.00,
        'due_date' => now()->addDays(1),
        'status' => 'pending',
    ]);

    // 2. Invoice 2500 USDC -> Exceeds auto limit -> Should escalate
    $inv2 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-LOOP-2500',
        'amount' => 2500.00,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
    ]);

    // 3. Invoice 18000 USDC -> Would breach reserve (25,420 - 450 - 18,000 < 10,000) -> Should hold
    $inv3 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-LOOP-18000',
        'amount' => 18000.00,
        'due_date' => now()->addDays(3),
        'status' => 'pending',
    ]);

    // 4. Student assistance request (eligible: enrolled, qualified, 95% attendance)
    $eligibleStudent = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $eligibleStudent->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $eligibleStudent->id,
        'user_id' => $eligibleStudent->user_id,
        'requested_amount' => 100_000000,
        'status' => AssistanceStatus::PENDING,
        'subject' => 'Emergency book grant',
    ]);

    $events = [];
    $cycleResult = $this->agent->runAutonomousCycle($this->org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect(array_column($events, 'title'))->toBe([
        'Starting liquidity forecast',
        'Liquidity forecast available',
        'Pending invoices observed',
        'Invoice policy result recorded',
        'Payment initiation withheld',
        'Invoice outcome recorded',
        'Invoice policy result recorded',
        'Invoice outcome recorded',
        'Invoice policy result recorded',
        'Invoice outcome recorded',
        'Pending assistance observed',
        'Checking assistance policies',
        'Assistance checks recorded',
        'Assistance policy result recorded',
        'Payment initiation withheld',
        'Assistance outcome recorded',
    ])->and($events[2]['summary'])->toBe('Found 3 pending invoices for policy evaluation.')
        ->and($events[10]['summary'])->toBe('Found 1 pending assistance requests for policy evaluation.')
        ->and($events[1]['summary'])->toContain((string) $cycleResult['forecast']['projected_balance'], $cycleResult['forecast']['health_status']);

    foreach ($events as $event) {
        expect($event)->toHaveKeys(['phase', 'title', 'summary'])
            ->and(array_diff(array_keys($event), ['phase', 'title', 'summary', 'decision_id', 'policy', 'status', 'reference']))->toBe([])
            ->and($event['phase'])->not->toBeEmpty()
            ->and($event['title'])->not->toBeEmpty()
            ->and($event['summary'])->not->toBeEmpty();

        if (isset($event['decision_id'])) {
            $recordedDecision = AgentDecision::query()->findOrFail($event['decision_id']);
            expect($event['decision_id'])->toBeGreaterThan(0)
                ->and($event['policy'])->toBe($recordedDecision->policy_checked)
                ->and($event['reference'])->not->toBeEmpty()
                ->and($event['status'])->not->toBeEmpty();

            if (in_array($event['title'], ['Invoice policy result recorded', 'Assistance policy result recorded'], true)) {
                expect($event['summary'])->toBe($recordedDecision->reasoning_summary)
                    ->and($event['status'])->toBe($recordedDecision->decision->value);
            }
        }
    }

    // Two proposals the policy engine approved, and two that this agent
    // declined to act on. There is no "payment response" at all, because no
    // payment was attempted.
    expect(array_values(array_filter($events, static fn (array $event): bool => $event['title'] === 'Payment response recorded')))->toHaveCount(0);

    $withheld = array_values(array_filter($events, static fn (array $event): bool => $event['title'] === 'Payment initiation withheld'));
    expect($withheld)->toHaveCount(2);
    foreach ($withheld as $event) {
        expect($event['status'])->toBe('requires_authorized_workflow')
            ->and($event['summary'])->toContain('may not initiate payment');
    }

    // The outcome reports the invoice's real state, which is still pending:
    // the proposal was recorded, and nothing was paid.
    expect($events[5])->toMatchArray(['reference' => $inv1->reference, 'status' => 'pending', 'phase' => 'outcome'])
        ->and($events[7])->toMatchArray(['reference' => $inv2->reference, 'status' => 'escalated'])
        ->and($events[9])->toMatchArray(['reference' => $inv3->reference, 'status' => 'held'])
        ->and($events[12]['summary'])->toBe('enrolled: passed; academic_qualified: passed; attendance_ok: passed; has_outstanding_tuition: passed; within_semester_cap: passed; fund_affordable: passed; reserve_protected: passed; within_daily_budget: passed; within_auto_limit: passed.')
        ->and($events[15])->toMatchArray(['reference' => $aid->ticket_number, 'status' => 'resolved', 'phase' => 'outcome'])
        ->and($events[15]['summary'])->toContain('decision status: requires_authorized_workflow');

    // Stats: nothing auto-paid, two proposals withheld for the authorized
    // workflow, and the escalation/hold reasoning still works.
    expect($cycleResult['stats']['auto_paid'])->toBe(0)
        ->and($cycleResult['stats']['requires_authorized_workflow'])->toBe(2) // 450 invoice + 100 aid
        ->and($cycleResult['stats']['escalated'])->toBe(1) // 2500 equipment invoice
        ->and($cycleResult['stats']['held'])->toBe(1) // 18000 reserve breach invoice
        ->and($cycleResult['stats']['total_disbursed_usdc'])->toBe(0.0);

    // The invoice is untouched: not paid, and no budget was drawn down.
    expect($inv1->fresh()->status)->toBe('pending');
    expect($this->techBudget->fresh()->spent_amount)->toBe(0.00);
    expect(Transaction::query()->where('reference_type', Invoice::class)->where('reference_id', $inv1->id)->exists())->toBeFalse();

    // Verify Invoice 2 was escalated to human approval queue
    expect($inv2->fresh()->status)->toBe('escalated');
    $approval = Approval::where('organization_id', $this->org->id)->first();
    expect($approval)->not->toBeNull()
        ->and($approval->status)->toBe('pending');

    // Verify Invoice 3 was held for reserve safety
    expect($inv3->fresh()->status)->toBe('held');

    // The request is closed as decided, but the note says plainly that no
    // money moved, and no disbursement is claimed.
    $updatedAid = $aid->fresh();
    expect($updatedAid->status)->toBe(AssistanceStatus::RESOLVED)
        ->and($updatedAid->admin_notes)->toContain('Payment initiation withheld')
        ->and($updatedAid->admin_notes)->not->toContain('Disbursed', 'on Arc');

    // The treasury is untouched: no invoice and no aid payment left it.
    expect($this->wallet->fresh()->balance)->toBe(25420.00);
    expect(Transaction::query()->count())->toBe(0);
});

test('progress callbacks stay local to each cycle on a reused agent', function (): void {
    $org = Organization::query()->sole();
    $agent = app(EduFlowAgent::class);
    $firstEvents = [];
    $agent->runAutonomousCycle($org, static function (array $event) use (&$firstEvents): void {
        $firstEvents[] = $event;
    });

    $originalEvents = $firstEvents;
    $agent->runAutonomousCycle($org);
    $secondEvents = [];
    $agent->runAutonomousCycle($org, static function (array $event) use (&$secondEvents): void {
        $secondEvents[] = $event;
    });

    expect($firstEvents)->toBe($originalEvents)
        ->and($secondEvents)->toBe($originalEvents)
        ->and(array_column($secondEvents, 'title'))->toBe([
            'Starting liquidity forecast',
            'Liquidity forecast available',
            'Pending invoices observed',
            'Pending assistance observed',
        ]);
});

test('progress reports exact invoice evidence exclusion without executing a payment', function (): void {
    $org = Organization::query()->sole();
    $techBudget = Budget::query()->where('organization_id', $org->id)->where('category', 'tech')->sole();
    $wallet = $org->primaryWallet();
    expect($wallet)->not->toBeNull();
    $vendor = Vendor::query()->where('organization_id', $org->id)->where('name', 'AWS Cloud')->sole();
    $invoice = Invoice::query()->create([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'budget_id' => $techBudget->id,
        'reference' => 'INV-EXACT-PROGRESS',
        'amount' => 450.00,
        'due_date' => now()->addDay(),
        'status' => 'pending',
    ]);
    InvoiceVersion::factory()->forSource($invoice, User::factory()->create())->create();
    $payments = Mockery::mock(CircleWalletService::class);
    $payments->shouldNotReceive('executePayment');
    app()->instance(CircleWalletService::class, $payments);
    $events = [];

    $result = app(EduFlowAgent::class)->runAutonomousCycle($org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect($events[3])->toBe([
        'phase' => 'policy',
        'title' => 'Invoice requires exact evidence',
        'summary' => $result['processed_invoices'][0]['reason'],
        'reference' => $invoice->reference,
        'status' => 'exact_evidence_required',
    ])->and($result['processed_invoices'][0]['decision'])->toBe('exact_evidence_required')
        ->and($invoice->fresh()->status)->toBe('pending')
        ->and(AgentDecision::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($wallet->fresh()->balance)->toBe(25420.00);
});

test('progress records assistance setup escalation without attempting a transfer', function (bool $hasFund, bool $hasPolicy): void {
    $org = Organization::query()->sole();
    if ($hasFund) {
        AssistanceFund::query()->create([
            'organization_id' => $org->id,
            'name' => 'Emergency Assistance Fund',
            'balance_base_units' => 10000_000000,
            'reserve_threshold_base_units' => 5000_000000,
            'daily_budget_base_units' => 1000_000000,
            'status' => 'active',
        ]);
    }

    if ($hasPolicy) {
        AssistancePolicyVersion::query()->create([
            'version' => 'v1',
            'organization_id' => null,
            'auto_limit_base_units' => 100_000000,
            'semester_cap_base_units' => 500_000000,
            'min_attendance_rate' => 85.00,
            'required_enrollment_status' => 'enrolled',
            'required_academic_status' => 'qualified',
            'is_active' => true,
        ]);
    }

    $student = Student::factory()->create();
    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 100_000000,
        'status' => AssistanceStatus::PENDING,
    ]);
    $payments = Mockery::mock(CircleWalletService::class);
    $payments->shouldNotReceive('executePayment');
    app()->instance(CircleWalletService::class, $payments);
    $events = [];

    $result = app(EduFlowAgent::class)->runAutonomousCycle($org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $decision = AgentDecision::query()->where('reference_type', AssistanceRequest::class)->where('reference_id', $aid->id)->sole();
    expect(array_column($events, 'title'))->toBe([
        'Starting liquidity forecast',
        'Liquidity forecast available',
        'Pending invoices observed',
        'Pending assistance observed',
        'Checking assistance policies',
        'Assistance setup escalated',
    ])->and($events[5])->toBe([
        'phase' => 'outcome',
        'title' => 'Assistance setup escalated',
        'summary' => $decision->reasoning_summary,
        'decision_id' => $decision->id,
        'policy' => 'AID_SETUP_REQUIRED_V1',
        'reference' => $aid->ticket_number,
        'status' => 'escalated',
    ])->and($result['stats']['escalated'])->toBe(1)
        ->and(Approval::query()->where('agent_decision_id', $decision->id)->sole()->status)->toBe('pending')
        ->and($aid->fresh()->admin_notes)->toContain('Escalated for setup')
        ->and(Transaction::query()->count())->toBe(0);
})->with([
    'fund missing' => [false, true],
    'policy missing' => [true, false],
    'fund and policy missing' => [false, false],
]);

test('the agent never contacts the payment gateway for an approved invoice', function (): void {
    // This replaces the old provenance test. That one drove the agent through
    // a real gateway call to check how provider receipts were redacted; the
    // agent no longer calls the gateway at all, which is a strictly stronger
    // guarantee than redacting a response that never arrives.
    $org = Organization::query()->sole();
    $vendor = Vendor::query()->where('organization_id', $org->id)->where('name', 'AWS Cloud')->sole();
    $invoice = Invoice::query()->create([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-NO-GATEWAY',
        'amount' => 450.00,
        'due_date' => now()->addDay(),
        'status' => 'pending',
    ]);

    /** @var list<array<string, mixed>> $events */
    $events = [];
    /** @var MockInterface&CircleWalletService $payments */
    $payments = Mockery::mock(CircleWalletService::class);
    $payments->shouldNotReceive('executePayment');
    app()->instance(CircleWalletService::class, $payments);

    $arc = Mockery::mock(ArcNetworkGateway::class);
    $arc->shouldNotReceive('rpc');
    app()->instance(ArcNetworkGateway::class, $arc);

    $result = app(EduFlowAgent::class)->runAutonomousCycle($org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $invoiceEvents = array_values(array_filter($events, static fn (array $event): bool => ($event['reference'] ?? null) === $invoice->reference));

    expect(array_column($invoiceEvents, 'title'))->toBe([
        'Invoice policy result recorded',
        'Payment initiation withheld',
        'Invoice outcome recorded',
    ])->and($invoiceEvents[1]['status'])->toBe('requires_authorized_workflow')
        ->and($invoiceEvents[2]['summary'])->toContain('Local invoice status: pending')
        ->and($invoice->fresh()->status)->toBe('pending')
        ->and($result['stats']['auto_paid'])->toBe(0)
        ->and($result['stats']['requires_authorized_workflow'])->toBeGreaterThan(0)
        // No provider response exists, so no provider secret can leak.
        ->and(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain('sk-private-response', 'rpc-secret', 'provider_trace', '--rpc-url');
});

test('a gateway that would throw is never reached, and the cycle completes', function (): void {
    // The old version of this test made the gateway throw mid-cycle and proved
    // earlier progress survived. That failure mode cannot occur any more,
    // because the agent does not call the gateway -- so the stronger guard is
    // that a gateway rigged to explode is never touched, and the cycle still
    // finishes and still records proposals.
    $org = Organization::query()->sole();
    $vendor = Vendor::query()->where('organization_id', $org->id)->where('name', 'AWS Cloud')->sole();
    $invoice = Invoice::query()->create([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-GATEWAY-UNTOUCHED',
        'amount' => 450.00,
        'due_date' => now()->addDay(),
        'status' => 'pending',
    ]);

    /** @var list<array<string, mixed>> $events */
    $events = [];
    /** @var MockInterface&CircleWalletService $payments */
    $payments = Mockery::mock(CircleWalletService::class);
    $payments->shouldNotReceive('executePayment');
    app()->instance(CircleWalletService::class, $payments);

    $result = app(EduFlowAgent::class)->runAutonomousCycle($org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect($result['stats']['auto_paid'])->toBe(0)
        ->and($result['stats']['requires_authorized_workflow'])->toBeGreaterThanOrEqual(1)
        ->and($invoice->fresh()->status)->toBe('pending')
        ->and(Transaction::query()->count())->toBe(0)
        ->and(array_column($events, 'title'))->toContain('Payment initiation withheld');
});

test('finance officer can approve escalated transaction', function (): void {
    $inv = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-ESCALATE-TEST',
        'amount' => 2500.00,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
    ]);

    // Trigger cycle to escalate
    $this->agent->runAutonomousCycle($this->org);

    $approval = Approval::first();
    expect($approval)->not->toBeNull();

    $financeOfficer = User::factory()->create(['name' => 'Finance Director Jane']);

    // Human approves the escalated transaction!
    $approved = $this->agent->approveEscalation($approval, $financeOfficer, 'Approved after verifying departmental budget.');

    expect($approved)->toBeTrue()
        ->and($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->approver_id)->toBe($financeOfficer->id)
        ->and($inv->fresh()->status)->toBe('paid');
});

test('autonomous cycle splits 150 USDC aid into 100 auto plus 50 escalated', function (): void {
    $student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 150_000000,
        'status' => AssistanceStatus::SUBMITTED,
        'subject' => 'Emergency assistance 150',
    ]);

    $events = [];
    $result = $this->agent->runAutonomousCycle($this->org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $aidEvents = array_values(array_filter($events, static fn (array $event): bool => ($event['reference'] ?? null) === $aid->ticket_number));
    expect(array_column($aidEvents, 'title'))->toBe([
        'Checking assistance policies',
        'Assistance checks recorded',
        'Assistance policy result recorded',
        'Payment initiation withheld',
        'Assistance outcome recorded',
    ])->and($aidEvents[1]['summary'])->toContain('within_auto_limit: failed')
        ->and($aidEvents[2]['status'])->toBe('partial_approval')
        ->and($aidEvents[2]['policy'])->toBe('BOUNDED_EMERGENCY_AID_V1')
        ->and($aidEvents[2]['summary'])->toBe('100 USDC approved automatically. Remaining 50 USDC escalated for review.')
        ->and($aidEvents[3]['status'])->toBe('requires_authorized_workflow')
        ->and($aidEvents[3]['summary'])->toContain('may not initiate payment')
        ->and($aidEvents[4]['status'])->toBe('in_progress')
        // The partial decision keeps its escalated status: the remainder is
        // still waiting on a human, which is the only way that money moves.
        ->and($aidEvents[4]['summary'])->toContain('decision status: escalated', 'does not verify on-chain settlement');

    $updated = $aid->fresh();

    // The partial approval is still computed and still escalated, but nothing
    // was disbursed: the treasury and the fund are untouched by this agent.
    expect($updated->status instanceof AssistanceStatus ? $updated->status->value : $updated->status)->toBe(AssistanceStatus::IN_PROGRESS->value)
        ->and($result['stats']['auto_paid'])->toBe(0)
        ->and($result['stats']['requires_authorized_workflow'])->toBe(1)
        ->and($result['stats']['escalated'])->toBe(1)
        ->and($this->wallet->fresh()->balance)->toBe(25420.00)
        ->and($fund->fresh()->balance_base_units)->toBe(10000_000000);

    $decision = AgentDecision::where('reference_id', $aid->id)
        ->where('reference_type', AssistanceRequest::class)
        ->first();

    expect($decision)->not->toBeNull()
        ->and($decision->decision)->toBe(AgentDecisionType::PARTIAL_APPROVAL)
        ->and($decision->input_snapshot['locked_quote']['quote'])->toBe('PHP');

    $approval = Approval::where('agent_decision_id', $decision->id)->first();
    expect($approval)->not->toBeNull()->and($approval->status)->toBe('pending');

    $officer = User::factory()->create(['name' => 'Finance Officer']);
    app(ApproveEscalatedRequest::class)->handle($aid, $decision, $officer, $fund, 'Remainder approved.');

    // Only the escalated remainder moves, and only because a human approved
    // it. The 100 USDC the policy engine proposed was never paid by the agent.
    expect($aid->fresh()->status instanceof AssistanceStatus ? $aid->fresh()->status->value : $aid->fresh()->status)->toBe(AssistanceStatus::RESOLVED->value)
        ->and($this->wallet->fresh()->balance)->toBe(25370.00)
        ->and($approval->fresh()->status)->toBe('approved');
});

/**
 * The agent used to build a recipient from a hash of the user id
 * ("0xstudent_<md5>"), which the Circle CLI rejects as an invalid destination.
 * A fabricated address is worse than a rejected one: if it were ever accepted,
 * the money would be unrecoverable. So the agent must never invent a payout
 * target, and must escalate instead.
 */
test('a student with no payout address is escalated, never paid a fabricated address', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 100_000000,
        'status' => AssistanceStatus::SUBMITTED,
        'subject' => 'Emergency assistance without a payout address',
    ]);

    $events = [];
    $result = $this->agent->runAutonomousCycle($this->org, static function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $aidEvents = array_values(array_filter($events, static fn (array $event): bool => ($event['reference'] ?? null) === $aid->ticket_number));
    expect(array_column($aidEvents, 'title'))->toBe([
        'Checking assistance policies',
        'Assistance checks recorded',
        'Assistance policy result recorded',
        'Assistance payout address escalated',
    ])->and($aidEvents[3])->toMatchArray([
        'phase' => 'outcome',
        'policy' => 'STUDENT_PAYOUT_ADDRESS_MISSING_V1',
        'status' => 'escalated',
    ])->and($aidEvents[3]['summary'])->toContain('no valid payout address');

    $decision = AgentDecision::where('reference_id', $aid->id)
        ->where('reference_type', AssistanceRequest::class)
        ->firstOrFail();

    // Escalated with a distinct policy code so the cause is legible in the UI.
    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->policy_checked)->toBe('STUDENT_PAYOUT_ADDRESS_MISSING_V1')
        ->and((float) $decision->approved_amount)->toBe(0.0)
        ->and($decision->requires_approval)->toBeTrue();

    // Nothing moved, and nothing was invented.
    expect(Transaction::where('reference_type', AssistanceRequest::class)->where('reference_id', $aid->id)->count())->toBe(0)
        ->and($this->wallet->fresh()->balance)->toBe(25420.00)
        ->and($fund->fresh()->balance_base_units)->toBe(10000_000000)
        ->and($result['stats']['escalated'])->toBe(1)
        ->and($aid->fresh()->admin_notes)->toContain('no valid payout address');
});

test('a malformed payout address is treated as missing', function (): void {
    $student = Student::factory()->withInvalidPayoutAddress()->create();

    expect($student->payout_address)->toBe('0xnot-a-real-address')
        ->and($student->hasValidPayoutAddress())->toBeFalse();
});

test('a well-formed payout address is accepted in any casing', function (string $address): void {
    $student = Student::factory()->create(['payout_address' => $address]);

    expect($student->hasValidPayoutAddress())->toBeTrue();
})->with([
    '0x4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f607182',
    '0x4B1C0D2E3F4A5B6C7D8E9F0A1B2C3D4E5F607182',
    '0x4b1C0D2e3F4a5B6C7d8E9f0A1b2C3d4E5F607182',
]);

test('addresses that are not 40 hex digits are rejected', function (?string $address): void {
    $student = Student::factory()->create(['payout_address' => $address]);

    expect($student->hasValidPayoutAddress())->toBeFalse();
})->with([
    'too short' => ['0x4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f60718'],
    'too long' => ['0x4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6071822'],
    'no prefix' => ['4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f607182'],
    'non hex' => ['0xzzzz0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f607182'],
    'the old fabricated form' => ['0xstudent_eccbc87e4b5ce2fe'],
    'null' => [null],
]);

test('finance officer can approve student assistance escalation via agent', function (): void {
    $student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 150_000000,
        'status' => AssistanceStatus::SUBMITTED,
    ]);

    $this->agent->runAutonomousCycle($this->org);

    $decision = AgentDecision::where('reference_id', $aid->id)->first();
    $approval = Approval::where('agent_decision_id', $decision->id)->first();
    expect($approval)->not->toBeNull();

    $officer = User::factory()->create(['name' => 'Finance Director']);
    $success = $this->agent->approveEscalation($approval, $officer, 'Approved remaining 50 USDC');

    expect($success)->toBeTrue()
        ->and($aid->fresh()->status instanceof AssistanceStatus ? $aid->fresh()->status->value : $aid->fresh()->status)->toBe(AssistanceStatus::RESOLVED->value)
        ->and($approval->fresh()->status)->toBe('approved')
        // Only the human-approved amount moves; nothing the agent proposed was
        // paid before the officer acted.
        ->and($this->wallet->fresh()->balance)->toBe(25370.00);
});

test('finance officer can reject student assistance escalation via agent', function (): void {
    $student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 150_000000,
        'status' => AssistanceStatus::SUBMITTED,
    ]);

    $this->agent->runAutonomousCycle($this->org);

    $decision = AgentDecision::where('reference_id', $aid->id)->first();
    $approval = Approval::where('agent_decision_id', $decision->id)->first();
    expect($approval)->not->toBeNull();

    $officer = User::factory()->create(['name' => 'Finance Director']);
    $success = $this->agent->rejectEscalation($approval, $officer, 'Budget allocation exhausted for this category');

    expect($success)->toBeTrue()
        ->and($aid->fresh()->status instanceof AssistanceStatus ? $aid->fresh()->status->value : $aid->fresh()->status)->toBe(AssistanceStatus::CLOSED->value)
        ->and($approval->fresh()->status)->toBe('rejected')
        ->and($decision->fresh()->status)->toBe('rejected')
        // Nothing stands: the agent's proposed 100 was never paid, so the
        // rejection leaves the treasury exactly where it started.
        ->and($this->wallet->fresh()->balance)->toBe(25420.00);
});
