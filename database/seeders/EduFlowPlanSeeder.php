<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\CurrencyRate;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class EduFlowPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Keep these identical to EduFlowFinancialSeeder: whichever runs second
        // must not leave the organization on a different set of thresholds.
        $org = Organization::updateOrCreate(
            ['name' => 'Northstar Learning Center'],
            [
                'type' => 'school',
                'currency' => 'USDC',
                'minimum_reserve' => 20.00,
                'max_auto_payment' => 50.00,
                'max_daily_disbursement' => 200.00,
                'human_approval_threshold' => 50.00,
            ]
        );

        // All amounts are 6-decimal USDC base units, stored as integers.
        AssistanceFund::firstOrCreate(
            ['organization_id' => $org->id],
            [
                'name' => 'Emergency Assistance Fund',
                'balance_base_units' => 1000_000000,
                'reserve_threshold_base_units' => 500_000000,
                'daily_budget_base_units' => 200_000000,
                'status' => 'active',
            ]
        );

        // A request above the 10 USDC auto-limit is partially approved and the
        // remainder escalated, which is the split the demo narrates.
        AssistancePolicyVersion::firstOrCreate(
            ['version' => 'v1', 'organization_id' => null],
            [
                'auto_limit_base_units' => 10_000000,
                'semester_cap_base_units' => 50_000000,
                'min_attendance_rate' => 85.00,
                'required_enrollment_status' => 'enrolled',
                'required_academic_status' => 'qualified',
                'is_active' => true,
                'rules' => ['note' => 'Initial bounded aid policy'],
            ]
        );

        $rates = [
            'PHP' => 5750,
            'EUR' => 92,
            'GBP' => 79,
            'CAD' => 136,
            'SGD' => 134,
            'INR' => 8300,
            'USD' => 100,
        ];

        foreach ($rates as $code => $units) {
            CurrencyRate::updateOrCreate(
                ['base_code' => 'USDC', 'quote_code' => $code, 'provider' => 'fallback'],
                [
                    'units_per_usdc' => $units,
                    'quoted_at' => now(),
                    'expires_at' => null,
                    'is_fallback' => true,
                ]
            );
        }
    }
}
