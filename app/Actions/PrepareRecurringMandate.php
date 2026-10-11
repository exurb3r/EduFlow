<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Budget;
use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\PaymentIntent;
use App\Models\RecurringMandate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDestinationVersion;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Record a proposed standing mandate — the *class* of payment a human is asking
 * to automate.
 *
 * This is deliberately a draft. Writing a mandate is not authorizing one: only a
 * different human, through `ReviewRecurringMandate`, may approve it, because
 * §14.7 forbids an agent or a policy maker from authorizing their own mandate.
 *
 * The obligation reference is a business-approved contract or recurring
 * obligation named by a human. Nothing here infers that a bill is recurring —
 * an LLM cannot know that, and so this action takes it as given input and binds
 * it into the digest.
 */
final readonly class PrepareRecurringMandate
{
    public function __construct(private InstallationInstitution $institutions) {}

    /**
     * @param  array{
     *     budget_id:int, vendor_id:int, vendor_destination_version_id:int, wallet_id:int, finance_policy_version_id:int,
     *     obligation_reference:string, obligation_digest:string, starts_at:string, ends_at:string, due_window_days:int,
     *     per_occurrence_ceiling_base_units:int|string, fee_ceiling_base_units:int|string,
     *     daily_limit_base_units:int|string, period_limit_base_units:int|string, reason:string
     * }  $data
     */
    public function handle(User $actor, array $data, string $key): RecurringMandate
    {
        Gate::forUser($actor)->authorize('create', RecurringMandate::class);
        $institution = $this->institutions->require();
        $key = Str::lower($key);

        Validator::make(['key' => $key, ...$data], [
            'key' => ['required', 'uuid'],
            'budget_id' => ['required', 'integer', 'min:1'],
            'vendor_id' => ['required', 'integer', 'min:1'],
            'vendor_destination_version_id' => ['required', 'integer', 'min:1'],
            'wallet_id' => ['required', 'integer', 'min:1'],
            'finance_policy_version_id' => ['required', 'integer', 'min:1'],
            'obligation_reference' => ['required', 'string', 'max:255'],
            'obligation_digest' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'due_window_days' => ['required', 'integer', 'min:1', 'max:365'],
            'per_occurrence_ceiling_base_units' => ['required', 'regex:/^\d{1,18}$/'],
            'fee_ceiling_base_units' => ['required', 'regex:/^\d{1,18}$/'],
            'daily_limit_base_units' => ['required', 'regex:/^\d{1,18}$/'],
            'period_limit_base_units' => ['required', 'regex:/^\d{1,18}$/'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $institution, $key, $data): RecurringMandate {
            /** @var RecurringMandate|null $existing */
            $existing = RecurringMandate::query()->where('request_key', $key)->first();

            if ($existing !== null) {
                foreach (['budget_id', 'vendor_id', 'vendor_destination_version_id', 'wallet_id', 'finance_policy_version_id',
                    'obligation_digest', 'chain', 'chain_id', 'per_occurrence_ceiling_base_units',
                    'fee_ceiling_base_units', 'daily_limit_base_units', 'period_limit_base_units'] as $column) {
                    if ($existing->{$column} !== $this->expected($data, $column)) {
                        throw ValidationException::withMessages(['key' => 'Mandate identity already binds different evidence.']);
                    }
                }

                return $existing;
            }

            $this->requireSameInstitution($institution->id, $data);
            $this->requireCurrentPolicy($actor, $data);
            $this->requireReviewedDestination($data);
            $this->requireBounds($data);

            /** @var Budget $budget */
            $budget = Budget::query()->where('organization_id', $institution->id)->whereKey($data['budget_id'])->firstOrFail();
            /** @var Vendor $vendor */
            $vendor = Vendor::query()->where('organization_id', $institution->id)->whereKey($data['vendor_id'])->firstOrFail();
            /** @var VendorDestinationVersion $destination */
            $destination = VendorDestinationVersion::query()->where('organization_id', $institution->id)
                ->where('vendor_id', $vendor->id)->whereKey($data['vendor_destination_version_id'])->firstOrFail();
            /** @var Wallet $wallet */
            $wallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($data['wallet_id'])->firstOrFail();

            $snapshot = [
                'schema_version' => 1,
                'purpose' => 'recurring_vendor_payment_mandate',
                'request_key' => $key,
                'institution_id' => $institution->id,
                'budget_id' => $budget->id,
                'vendor_id' => $vendor->id,
                'vendor_destination_version_id' => $destination->id,
                'wallet_id' => $wallet->id,
                'finance_policy_version_id' => $data['finance_policy_version_id'],
                'obligation_reference' => $data['obligation_reference'],
                'obligation_digest' => $data['obligation_digest'],
                'chain' => 'ARC-TESTNET',
                'chain_id' => 5042002,
                'currency' => 'USDC',
                'starts_at' => $this->parse($data['starts_at'])?->toIso8601String(),
                'ends_at' => $this->parse($data['ends_at'])?->toIso8601String(),
                'due_window_days' => (int) $data['due_window_days'],
                'per_occurrence_ceiling_base_units' => (string) BigInteger::of($data['per_occurrence_ceiling_base_units']),
                'fee_ceiling_base_units' => (string) BigInteger::of($data['fee_ceiling_base_units']),
                'daily_limit_base_units' => (string) BigInteger::of($data['daily_limit_base_units']),
                'period_limit_base_units' => (string) BigInteger::of($data['period_limit_base_units']),
                'reason' => $data['reason'],
                'prepared_by' => $actor->id,
            ];

            /** @var RecurringMandate $mandate */
            $mandate = RecurringMandate::query()->create([
                'request_key' => $key,
                'organization_id' => $institution->id,
                'budget_id' => $budget->id,
                'vendor_id' => $vendor->id,
                'vendor_destination_version_id' => $destination->id,
                'wallet_id' => $wallet->id,
                'finance_policy_version_id' => $data['finance_policy_version_id'],
                'obligation_reference' => $data['obligation_reference'],
                'obligation_digest' => $data['obligation_digest'],
                'chain' => 'ARC-TESTNET',
                'chain_id' => 5042002,
                'currency' => 'USDC',
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'due_window_days' => (int) $data['due_window_days'],
                'per_occurrence_ceiling_base_units' => BigInteger::of($data['per_occurrence_ceiling_base_units'])->toInt(),
                'fee_ceiling_base_units' => BigInteger::of($data['fee_ceiling_base_units'])->toInt(),
                'daily_limit_base_units' => BigInteger::of($data['daily_limit_base_units'])->toInt(),
                'period_limit_base_units' => BigInteger::of($data['period_limit_base_units'])->toInt(),
                'state' => 'draft',
                'prepared_by' => $actor->id,
                'snapshot' => $snapshot,
                'snapshot_digest' => PaymentIntent::digest($snapshot),
            ]);

            activity('finance')->causedBy($actor)->performedOn($mandate)->event('recurring_mandate_prepared')
                ->withProperties([
                    'snapshot_digest' => $mandate->snapshot_digest,
                    'obligation_reference' => $mandate->obligation_reference,
                    'per_occurrence_ceiling_base_units' => (string) $mandate->per_occurrence_ceiling_base_units,
                    'can_execute' => false,
                ])
                ->log('Standing mandate scope prepared for independent review; nothing is authorized by writing it');

            return $mandate;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function requireSameInstitution(int $institutionId, array $data): void
    {
        foreach ([[Budget::class, 'budget_id'], [Vendor::class, 'vendor_id'],
            [VendorDestinationVersion::class, 'vendor_destination_version_id'], [Wallet::class, 'wallet_id']] as [$model, $column]) {
            /** @var class-string $model */
            if (! $model::query()->where('organization_id', $institutionId)->whereKey($data[$column])->exists()) {
                throw ValidationException::withMessages([$column => 'The selected record does not belong to this institution.']);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function requireCurrentPolicy(User $actor, array $data): void
    {
        /** @var FinancePolicyVersion|null $policy */
        $policy = FinancePolicyVersion::query()->where('organization_id', app(InstallationInstitution::class)->require()->id)
            ->whereKey($data['finance_policy_version_id'])->first();

        $activation = FinancePolicyActivation::current(app(InstallationInstitution::class)->require()->id);

        if ($policy === null || ! $policy->hasValidContent() || ! $activation instanceof FinancePolicyActivation
            || $activation->finance_policy_version_id !== $policy->id
            || ! $activation->hasValidHistory()
            || $policy->created_by === $actor->id) {
            throw ValidationException::withMessages(['finance_policy_version_id' => 'Only a currently active policy written by someone else may be bound to a mandate.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function requireReviewedDestination(array $data): void
    {
        $version = VendorDestinationVersion::query()
            ->where('organization_id', app(InstallationInstitution::class)->require()->id)
            ->where('vendor_id', $data['vendor_id'])
            ->whereKey($data['vendor_destination_version_id'])
            ->first();

        // The destination must be this vendor's own approved version, and it
        // must still verify on its own evidence. A destination that no longer
        // validates cannot be bound, because it could not be paid.
        if (! $version instanceof VendorDestinationVersion || ! $version->hasValidContent()) {
            throw ValidationException::withMessages(['vendor_destination_version_id' => 'The destination must be this vendor\'s own reviewed, intact destination version.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function requireBounds(array $data): void
    {
        $starts = $this->parse($data['starts_at']);
        $ends = $this->parse($data['ends_at']);

        if (! $starts instanceof CarbonImmutable || ! $ends instanceof CarbonImmutable || $starts >= $ends) {
            throw ValidationException::withMessages(['ends_at' => 'A mandate must end after it starts.']);
        }

        $per = BigInteger::of($data['per_occurrence_ceiling_base_units']);
        $daily = BigInteger::of($data['daily_limit_base_units']);
        $period = BigInteger::of($data['period_limit_base_units']);

        // A per-payment ceiling above the cumulative limits is incoherent: it
        // would authorize a single payment the period forbids. Caught here
        // rather than discovered on the first occurrence.
        if ($per->isGreaterThan($daily) || $daily->isGreaterThan($period)) {
            throw ValidationException::withMessages(['per_occurrence_ceiling_base_units' => 'Per-occurrence ceiling must fit within the daily limit, and the daily limit within the period limit.']);
        }

        // §18.4's "initial allowance is zero" needs a second, independent gate
        // rather than only a default of zero. Writing a zero ceiling is always
        // allowed; raising one is a separate act that this installation flag
        // authorizes on its own and no reviewer decision does.
        if ($per->isGreaterThan(BigInteger::zero()) && ! config('eduflow.mandate.allow_nonzero_allowance', false)) {
            throw ValidationException::withMessages([
                'per_occurrence_ceiling_base_units' => 'Nonzero standing allowance is disabled for this installation. Record a zero ceiling, or have the allowance separately enabled.',
            ]);
        }
    }

    private function parse(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function expected(array $data, string $column): int|string
    {
        if ($column === 'chain') {
            return 'ARC-TESTNET';
        }
        if ($column === 'chain_id') {
            return 5042002;
        }

        $value = $data[$column];

        return is_numeric($value) ? (int) $value : (string) $value;
    }
}
