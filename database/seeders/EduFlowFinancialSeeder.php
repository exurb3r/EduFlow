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
        // Scenario figures are sized so the whole demo settles for real on Arc
        // testnet. The Circle faucet mints exactly 20 USDC per drip and
        // rate-limits after about five, so a funded agent wallet tops out near
        // 120 USDC. Every figure below is chosen to fit inside that ceiling
        // while still exercising each policy branch.
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

        $treasuryAddress = (string) (config('lepton.arc.treasury') ?: '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA');

        // Retire placeholder treasury rows so only the real agent wallet remains.
        $wallet = $org->wallets()->orderBy('id')->first();

        if (! $wallet) {
            $wallet = Wallet::create([
                'organization_id' => $org->id,
                'address' => $treasuryAddress,
                'provider' => 'circle',
                'network' => 'arc',
                'balance' => 120.00,
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
                'allocated_amount' => 400.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 400.00,
                'status' => 'active',
            ]
        );

        $opsBudget = Budget::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'School Operations'],
            [
                'category' => 'operations',
                'allocated_amount' => 1000.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 1000.00,
                'status' => 'active',
            ]
        );

        $aidBudget = Budget::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Student Assistance & Aid'],
            [
                'category' => 'assistance',
                'allocated_amount' => 200.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 200.00,
                'status' => 'active',
            ]
        );

        $equipmentBudget = Budget::firstOrCreate(
            ['organization_id' => $org->id, 'name' => 'Campus Equipment & Lab'],
            [
                'category' => 'equipment',
                'allocated_amount' => 500.00,
                'spent_amount' => 0.00,
                'remaining_amount' => 500.00,
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

        // Invoices representing the demo scenarios. Amounts are chosen so the
        // policy engine takes a different branch for each, given a 120 USDC
        // wallet, a 20 USDC reserve and a 50 USDC autonomous limit. The engine
        // checks vendor, then budget, then reserve, then the auto limit, so
        // these amounts are ordered against those gates deliberately.
        //
        //   30   -> AUTO_PAY  (verified vendor, under the limit)
        //   45   -> AUTO_PAY
        //   60   -> ESCALATE  (unverified vendor, checked first)
        //   90   -> ESCALATE  (over the 50 limit, wallet can still afford it)
        //   200  -> HOLD      (would breach the 20 USDC reserve)
        //   2000 -> REJECT    (the equipment budget only holds 500)
        //
        // Only the two auto-pays move money: 75 USDC in total.
        Invoice::firstOrCreate(
            ['reference' => 'INV-CLOUD-45'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $cloudVendor->id,
                'budget_id' => $techBudget->id,
                'amount' => 45.00,
                'due_date' => Carbon::tomorrow(),
                'category' => 'technology',
                'status' => 'pending',
                'metadata' => ['service' => 'LMS Server & Storage Hosting'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-FIBER-30'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $fiberVendor->id,
                'budget_id' => $opsBudget->id,
                'amount' => 30.00,
                'due_date' => Carbon::now()->addDays(2),
                'category' => 'operations',
                'status' => 'pending',
                'metadata' => ['service' => 'High-Speed Campus Wi-Fi Backhaul'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-UNVERIFIED-60'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $unverifiedVendor->id,
                'budget_id' => $opsBudget->id,
                'amount' => 60.00,
                'due_date' => Carbon::now()->addDays(4),
                'category' => 'books',
                'status' => 'pending',
                'metadata' => ['service' => 'Out-of-Print Literature Anthologies'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-LAB-90'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $labVendor->id,
                'budget_id' => $equipmentBudget->id,
                'amount' => 90.00,
                'due_date' => Carbon::now()->addDays(5),
                'category' => 'equipment',
                'status' => 'pending',
                'metadata' => ['service' => 'Robotics Kits & Oscilloscopes for Engineering Dept'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-SUPPLY-200'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $labVendor->id,
                'budget_id' => $equipmentBudget->id,
                'amount' => 200.00,
                'due_date' => Carbon::now()->addDays(6),
                'category' => 'equipment',
                'status' => 'pending',
                'metadata' => ['service' => 'Semester Lab Consumables Restock'],
            ]
        );

        Invoice::firstOrCreate(
            ['reference' => 'INV-HAZARD-2000'],
            [
                'organization_id' => $org->id,
                'vendor_id' => $labVendor->id,
                'budget_id' => $equipmentBudget->id,
                'amount' => 2000.00,
                'due_date' => Carbon::now()->addDays(7),
                'category' => 'equipment',
                'status' => 'pending',
                'metadata' => ['service' => 'Full Campus HVAC Replacement'],
            ]
        );
    }
}
