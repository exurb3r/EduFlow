<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssistanceDecisionStatus;
use App\Models\Budget;
use App\Models\Organization;
use App\Models\StudentAssistanceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AssistanceAgent;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for the student-facing financial flow (plan.md section 25).
 * Idempotent: skips if the demo organization already exists.
 */
final class DemoFinancialSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['name' => 'Northstar Learning Center'],
            [
                'type' => 'school',
                'currency' => 'USDC',
                'minimum_reserve' => 10000.00,
                'max_auto_payment' => 1000.00,
                'max_daily_disbursement' => 5000.00,
                'human_approval_threshold' => 1000.00,
            ],
        );

        $wallet = Wallet::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'address' => '0x00000000000000000000000000000000northstar',
            ],
            [
                'provider' => 'circle',
                'network' => 'arc',
                'balance' => 25420.00,
                'status' => 'active',
            ],
        );

        Budget::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'category' => 'scholarships',
            ],
            [
                'name' => 'Scholarships & Student Assistance',
                'allocated_amount' => 2000.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 2000.00,
                'status' => 'active',
            ],
        );

        $student = User::query()->firstOrCreate(
            ['email' => 'student@northstar.test'],
            [
                'name' => 'Roven Dela Cruz',
                'password' => 'password',
                'wallet_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
                'email_verified_at' => now(),
            ],
        );

        if (User::query()->where('email', 'student@northstar.test')->exists() === false) {
            return;
        }

        $agent = app(AssistanceAgent::class);
        $payments = app(PaymentService::class);

        $scenarios = [
            ['requested' => 80.00, 'reason' => 'Emergency medicine costs after a clinic visit.'],
            ['requested' => 150.00, 'reason' => 'Replacement laptop charger and one week of meals.'],
            ['requested' => 60.00, 'reason' => 'Lab materials fee due before the midterm week.'],
            ['requested' => 400.00, 'reason' => 'Emergency travel to attend a family matter.'],
        ];

        foreach ($scenarios as $scenario) {
            $existing = StudentAssistanceRequest::query()
                ->where('user_id', $student->id)
                ->where('reason', $scenario['reason'])
                ->exists();

            if ($existing) {
                continue;
            }

            $request = StudentAssistanceRequest::create([
                'user_id' => $student->id,
                'organization_id' => $organization->id,
                'request_type' => 'emergency_assistance',
                'reason' => $scenario['reason'],
                'requested_amount' => $scenario['requested'],
                'status' => AssistanceDecisionStatus::AUTO_APPROVED->value,
                'reference_number' => 'FIN-'.strtoupper(Str::random(6)),
                'created_at' => now()->subDays(random_int(1, 20)),
            ]);

            $evaluation = $agent->review($request, $organization);
            $agent->record($request, $organization, $evaluation);

            $request->update([
                'approved_amount' => $evaluation['approved_amount'],
                'status' => $evaluation['decision'] === 'auto_approve'
                    ? AssistanceDecisionStatus::PAID->value
                    : AssistanceDecisionStatus::ESCALATED->value,
            ]);

            if ($evaluation['approved_amount'] > 0.0) {
                $payments->disburse($request, $student, $organization, $evaluation['approved_amount']);
            }
        }

        // Keep budgets consistent with the simulated payouts.
        $spent = (float) Transaction::query()
            ->where('organization_id', $organization->id)
            ->where('type', 'student_assistance')
            ->where('status', 'confirmed')
            ->sum('amount');

        $budget = Budget::query()
            ->where('organization_id', $organization->id)
            ->where('category', 'scholarships')
            ->first();

        if ($budget !== null) {
            $budget->update([
                'spent_amount' => $spent,
                'remaining_amount' => max(0.0, (float) $budget->allocated_amount - $spent),
            ]);
        }

        // Confirm the treasury reflects the payouts too.
        $wallet->update([
            'balance' => 25420.00 - $spent,
        ]);
    }
}
