<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class EduFlowFinancialSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['name' => 'Northstar Learning Center'],
            [
                'type' => 'school',
                'currency' => 'USDC',
                'minimum_reserve' => 10000.00,
                'max_auto_payment' => 1000.00,
                'max_daily_disbursement' => 5000.00,
                'human_approval_threshold' => 1000.00,
            ]
        );

        $treasuryAddress = (string) (config('lepton.arc.treasury') ?: '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA');

        // Retire placeholder treasury rows so only the real agent wallet remains.
        $wallet = $org->wallets()->orderBy('id')->first();

        if (! $wallet) {
            $wallet = Wallet::create([
                'organization_id' => $org->id,
                'address' => $treasuryAddress,
                'provider' => 'circle',
                'network' => 'arc',
                'balance' => 25420.00,
                'status' => 'active',
            ]);
        } elseif (strcasecmp($wallet->address, $treasuryAddress) !== 0) {
            $duplicates = $org->wallets()->whereKeyNot($wallet->id)
                ->whereRaw('lower(address) = ?', [strtolower($treasuryAddress)])
                ->pluck('id');

            $wallet->update(['address' => $treasuryAddress]);

            if ($duplicates->isNotEmpty()) {
                $org->wallets()->whereIn('id', $duplicates)->delete();
            }
        }

        // Budgets
        $techBudget = Budget::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Technology & Cloud'],
            [
                'category' => 'technology',
                'allocated_amount' => 4000.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 4000.00,
                'status' => 'active',
            ]
        );

        $opsBudget = Budget::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'School Operations'],
            [
                'category' => 'operations',
                'allocated_amount' => 10000.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 10000.00,
                'status' => 'active',
            ]
        );

        $aidBudget = Budget::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Student Assistance & Aid'],
            [
                'category' => 'assistance',
                'allocated_amount' => 2000.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 2000.00,
                'status' => 'active',
            ]
        );

        $equipmentBudget = Budget::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Campus Equipment & Lab'],
            [
                'category' => 'equipment',
                'allocated_amount' => 5000.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 5000.00,
                'status' => 'active',
            ]
        );

        // Vendors
        $cloudVendor = Vendor::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Cloudflare & AWS Services'],
            [
                'category' => 'hosting',
                'email' => 'billing@cloudprovider.test',
                'wallet_address' => '0x71C8395562473D5414d7990159D8965646f9F2a6',
                'status' => 'verified',
                'risk_level' => 'low',
            ]
        );

        $fiberVendor = Vendor::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Campus FiberNet Telecom'],
            [
                'category' => 'telecom',
                'email' => 'accounts@fibernet.test',
                'wallet_address' => '0x48A1961623573D5414d7990159D8965646f9F8b1',
                'status' => 'verified',
                'risk_level' => 'low',
            ]
        );

        $labVendor = Vendor::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Apex STEM Lab Supplies'],
            [
                'category' => 'equipment',
                'email' => 'sales@apexstem.test',
                'wallet_address' => '0x92F8395562473D5414d7990159D8965646f9F1c4',
                'status' => 'verified',
                'risk_level' => 'medium',
            ]
        );

        $unverifiedVendor = Vendor::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Third-Party Textbook Broker'],
            [
                'category' => 'books',
                'email' => 'support@bookbroker.test',
                'wallet_address' => '0x15B2395562473D5414d7990159D8965646f9F9e2',
                'status' => 'pending',
                'risk_level' => 'high',
            ]
        );

        // Invoices representing the demo scenarios
        Invoice::firstOrCreate(
            ['reference' => 'INV-CLOUD-450'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $cloudVendor->id,
                'budget_id' => $techBudget->id,
                'amount' => 450.00,
                'due_date' => Carbon::tomorrow(),
                'category' => 'technology',
                'status' => 'pending',
                'metadata' => ['service' => 'LMS Server & Storage Hosting'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-FIBER-300'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $fiberVendor->id,
                'budget_id' => $opsBudget->id,
                'amount' => 300.00,
                'due_date' => Carbon::now()->addDays(2),
                'category' => 'operations',
                'status' => 'pending',
                'metadata' => ['service' => 'High-Speed Campus Wi-Fi Backhaul'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-LAB-2500'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $labVendor->id,
                'budget_id' => $equipmentBudget->id,
                'amount' => 2500.00,
                'due_date' => Carbon::now()->addDays(5),
                'category' => 'equipment',
                'status' => 'pending',
                'metadata' => ['service' => 'Robotics Kits & Oscilloscopes for Engineering Dept'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-HAZARD-20000'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $labVendor->id,
                'budget_id' => $equipmentBudget->id,
                'amount' => 20000.00,
                'due_date' => Carbon::now()->addDays(7),
                'category' => 'equipment',
                'status' => 'pending',
                'metadata' => ['service' => 'Full Campus HVAC Replacement'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-UNVERIFIED-600'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $unverifiedVendor->id,
                'budget_id' => $opsBudget->id,
                'amount' => 600.00,
                'due_date' => Carbon::now()->addDays(4),
                'category' => 'books',
                'status' => 'pending',
                'metadata' => ['service' => 'Out-of-Print Literature Anthologies'],
            ]
        );
    }
}
