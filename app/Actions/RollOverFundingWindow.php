<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\BudgetSnapshot;
use App\Models\FundingWindow;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ArcBalanceObservation;
use App\Services\FundingWindowContext;
use App\Services\InstallationInstitution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Replace an expired funding window with a freshly observed, reviewable one.
 *
 * A window lives 15 minutes and is immutable, so an unattended lane that is to
 * keep paying bills needs a way to renew. That is all this does, and the
 * reason it is not just "call prepare again" is capacity.
 *
 * **Rollover does not re-grant money.** The hold chain is institution-wide and
 * cumulative, and `ReservationCapacity` verifies each historical hold against
 * the window it was actually taken under rather than the current one. So a
 * successor may re-observe cash — and will usually see a *different* number —
 * without any reservation that is already held becoming available again. A new
 * hold is admitted only if the cumulative total, less approved releases, still
 * fits inside the successor's own capacity.
 *
 * **Only a dead window may be replaced.** Superseding a live window would
 * strand holds that a supervisor is mid-way through approving against it, so
 * that is refused rather than reinterpreted. Preparation of a first window is
 * still `PrepareFundingWindow`'s job, and its guard against resetting reserved
 * capacity is untouched.
 *
 * What this deliberately does not do: create capacity, release a hold, approve
 * anything, or grant payment authority. The result is an unapproved window
 * that still needs `ApproveFundingWindow` like any other.
 */
final readonly class RollOverFundingWindow
{
    public function __construct(private InstallationInstitution $institutions, private ArcBalanceObservation $balances, private FundingWindowContext $context) {}

    public function handle(User $actor, FundingWindow $predecessor, BudgetSnapshot $budget, Wallet $wallet, string $key, string $validUntil): FundingWindow
    {
        Gate::forUser($actor)->authorize('create', FundingWindow::class);
        Gate::forUser($actor)->authorize('view', $budget);
        Validator::make(['key' => $key, 'valid_until' => $validUntil], ['key' => ['required', 'uuid'],
            'valid_until' => ['required', 'date_format:Y-m-d\TH:i:sP']])->validate();
        $institution = $this->institutions->require();
        $key = Str::lower($key);
        /** @var Wallet $storedWallet */
        $storedWallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($wallet->id)->firstOrFail();

        // External reads precede database locks. No signing or transfer calls occur here.
        $balance = FundingWindow::query()->where('request_key', $key)->exists() ? null : $this->balances->capture($storedWallet);

        return DB::transaction(function () use ($actor, $predecessor, $budget, $storedWallet, $key, $validUntil, $institution, $balance): FundingWindow {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', FundingWindow::class);

            /** @var FundingWindow $prior */
            $prior = FundingWindow::query()->where('organization_id', $institution->id)->whereKey($predecessor->id)->lockForUpdate()->firstOrFail();

            if (! $prior->hasValidSnapshot()) {
                throw ValidationException::withMessages(['funding' => 'The window being replaced has failed integrity checks; it cannot be renewed from corrupt evidence.']);
            }

            // Only a window that has actually ended may be replaced.
            if (! $prior->isExpired()) {
                throw ValidationException::withMessages(['funding' => 'Only an expired funding window may be rolled over; a live window still carries in-flight holds and approvals.']);
            }

            /** @var BudgetSnapshot $storedBudget */
            $storedBudget = BudgetSnapshot::query()->where('organization_id', $institution->id)->whereKey($budget->id)->lockForUpdate()->firstOrFail();

            /** @var Wallet $lockedWallet */
            $lockedWallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($storedWallet->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals(FundingWindowContext::walletFingerprint($storedWallet), FundingWindowContext::walletFingerprint($lockedWallet))) {
                throw ValidationException::withMessages(['wallet' => 'Treasury changed during observation; recapture funding evidence.']);
            }

            /** @var FundingWindow|null $existing */
            $existing = FundingWindow::query()->where('request_key', $key)->first();

            if ($existing !== null) {
                if ($existing->organization_id !== $institution->id || $existing->budget_snapshot_id !== $storedBudget->id
                    || $existing->wallet_id !== $lockedWallet->id || $existing->supersedes_funding_window_id !== $prior->id
                    || $existing->snapshot['valid_until'] !== $validUntil
                    || $existing->prepared_by !== $actor->id || ! $existing->hasValidSnapshot()) {
                    throw ValidationException::withMessages(['key' => 'Funding identity already binds different evidence.']);
                }

                return $existing;
            }

            if (FundingWindow::query()->where('supersedes_funding_window_id', $prior->id)->exists()) {
                throw ValidationException::withMessages(['funding' => 'This window has already been rolled over; renewal is a chain of distinct windows, not a second branch.']);
            }

            if ($balance === null || Carbon::parse($balance['observed_at'])->lt(now()->subSeconds(30))) {
                throw ValidationException::withMessages(['funding' => 'Balance observation expired while waiting for locks; recapture evidence.']);
            }

            // Prepends its own lineage: the renewal names the window it
            // replaces, so the digest covers "what capacity" and "whose
            // capacity" together.
            $snapshot = $this->context->build($actor, $storedBudget, $lockedWallet, $balance, $key, $validUntil, $prior);

            /** @var FundingWindow $window */
            $window = FundingWindow::query()->create(['request_key' => $key, 'organization_id' => $institution->id,
                'budget_snapshot_id' => $storedBudget->id, 'budget_id' => $storedBudget->budget_id, 'wallet_id' => $lockedWallet->id,
                'finance_policy_activation_id' => $snapshot['policy_activation_id'], 'prepared_by' => $actor->id,
                'supersedes_funding_window_id' => $prior->id,
                'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);

            activity('finance')->causedBy($actor)->performedOn($window)->event('funding_window_rolled_over')
                ->withProperties(['snapshot_digest' => $window->snapshot_digest, 'supersedes_funding_window_id' => $prior->id,
                    'is_fake' => $balance['is_fake'], 'can_execute' => false])
                ->log('Expired window replaced by freshly observed one for independent review; reserved capacity unchanged');

            return $window;
        }, 3);
    }
}
