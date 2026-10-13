<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StudentBillCategory;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentWallet;
use App\Services\BillPaymentPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A settled bill payment, minted whole.
 *
 * Every attribute below is derived from the same coherent set — one student,
 * one bill, one wallet — and the digest-bound snapshot is assembled *last* from
 * the columns that were actually written. That is deliberate: the model refuses
 * to create a row whose snapshot does not re-derive from its own columns, so a
 * factory that invented independent values could only ever produce rows that
 * throw.
 *
 * @extends Factory<StudentBillPayment>
 */
class StudentBillPaymentFactory extends Factory
{
    protected $model = StudentBillPayment::class;

    /** A realistic Arc-native gas fee, at 18 decimals. */
    private const string NETWORK_FEE = '0.02945113436514256';

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'request_key' => (string) Str::uuid(),
            'movement_key' => hash('sha256', (string) Str::uuid()),
            'organization_id' => Organization::factory(),
            'student_id' => Student::factory(),
            'quoted_for' => fn (array $attributes): int => $this->student($attributes)->user_id,
            'student_bill_id' => fn (array $attributes): int => StudentBill::factory()->create([
                'student_id' => $attributes['student_id'],
                'category' => StudentBillCategory::LABORATORY,
                'amount_base_units' => 150_000000,
                'paid_base_units' => 0,
            ])->id,
            'student_wallet_id' => fn (array $attributes): int => $this->wallet($attributes)->id,
            'payer_address' => fn (array $attributes): string => (string) $this->student($attributes)->payout_address,
            'recipient_address' => '0x'.str_repeat('2', 40),
            'chain' => StudentBillPayment::CHAIN,
            'amount_base_units' => 5_000000,
            'network_fee' => self::NETWORK_FEE,
            'tx_hash' => '0x'.fake()->regexify('[0-9a-f]{64}'),
            'tx_verified_on_chain' => true,
            'simulated' => false,
            'applied_base_units' => 5_000000,
            'overpayment_base_units' => 0,
            'allocated_at' => now(),
            'executed_at' => now(),
            'policy_decision' => 'auto_approve',
            'policy_code' => BillPaymentPolicy::CODE_AUTO_PAYMENT,
            'requires_human_approval' => false,
            'snapshot' => fn (array $attributes): array => $this->snapshot($attributes),
            'snapshot_digest' => fn (array $attributes): string => PaymentIntent::digest($attributes['snapshot']),
        ];
    }

    /**
     * A quote that has been shown but not yet accepted.
     *
     * No consent, no proof and nothing applied: the state a payment is in the
     * moment after `quote()` and before the payer answers.
     */
    public function quoted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'movement_key' => null,
            'consent_snapshot' => null,
            'consent_digest' => null,
            'consented_at' => null,
            'submitted_at' => null,
            'settlement_evidence' => null,
            'settlement_evidence_digest' => null,
            'settlement_verified_at' => null,
            'network_fee' => null,
            'tx_hash' => null,
            'tx_verified_on_chain' => false,
            'applied_base_units' => 0,
            'overpayment_base_units' => 0,
            'allocated_at' => null,
            'executed_at' => null,
        ]);
    }

    /**
     * A rehearsal on a driver that cannot move money.
     *
     * Recorded so the journey can be exercised and never so it can be counted:
     * nothing settled, so nothing may be applied to the bill.
     */
    public function rehearsal(): static
    {
        return $this->quoted()->state(fn (array $attributes): array => ['simulated' => true]);
    }

    /** @param array<string, mixed> $attributes */
    private function student(array $attributes): Student
    {
        return Student::query()->findOrFail($attributes['student_id']);
    }

    /** @param array<string, mixed> $attributes */
    private function wallet(array $attributes): StudentWallet
    {
        $student = $this->student($attributes);

        return StudentWallet::query()->firstOrCreate(
            ['student_id' => $student->id],
            [
                'address' => (string) $student->payout_address,
                'provider' => 'circle',
                'network' => 'arc',
                'chain' => StudentBillPayment::CHAIN,
                'status' => 'active',
                'generated_at' => now(),
            ],
        );
    }

    /**
     * The quote, assembled from the columns that were actually written.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(array $attributes): array
    {
        $bill = StudentBill::query()->findOrFail($attributes['student_bill_id']);
        $feeCeiling = self::feeCeiling(self::NETWORK_FEE);
        $total = $attributes['amount_base_units'] + $feeCeiling;

        return [
            'schema_version' => 1,
            'purpose' => StudentBillPayment::PURPOSE,
            'plane' => 'pilot',
            'asset' => 'USDC',
            'request_key' => $attributes['request_key'],
            'organization_id' => $attributes['organization_id'],
            'quoted_for' => $attributes['quoted_for'],
            'student_id' => $attributes['student_id'],
            'student_bill_id' => $attributes['student_bill_id'],
            'student_wallet_id' => $attributes['student_wallet_id'],
            'bill_category' => $bill->category->value,
            'payer_address' => $attributes['payer_address'],
            'recipient_address' => $attributes['recipient_address'],
            'amount_base_units' => $attributes['amount_base_units'],
            'quoted_network_fee' => self::NETWORK_FEE,
            'fee_ceiling_base_units' => $feeCeiling,
            // Comfortably above the total, because the quote lane refuses an
            // amount the balance cannot cover once the fee ceiling is added.
            'available_base_units' => $total + 15_000000,
            'total_base_units' => $total,
            'chain' => StudentBillPayment::CHAIN,
            'chain_id' => StudentBillPayment::CHAIN_ID,
            'simulated' => $attributes['simulated'],
            'policy_decision' => $attributes['policy_decision'],
            'policy_code' => $attributes['policy_code'],
            'requires_human_approval' => $attributes['requires_human_approval'],
            'quoted_at' => now()->utc()->toIso8601String(),
            'expires_at' => now()->utc()->addSeconds(120)->toIso8601String(),
            'consent_text' => StudentBillPayment::CONSENT_TEXT,
        ];
    }

    /** The lane's own fee ceiling rule: an 18-decimal fee in 6-decimal units, rounded UP. */
    private static function feeCeiling(string $fee): int
    {
        return BigDecimal::of($fee)->withPointMovedRight(6)->toScale(0, RoundingMode::Ceiling)->toInt();
    }
}
