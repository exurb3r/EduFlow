<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetSnapshot;
use App\Models\FinancePolicyVersion;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\MandateOccurrence;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\RecurringMandate;
use App\Models\VendorDestinationVersion;
use App\Models\Wallet;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The sessionless half of the autonomous lane.
 *
 * Every tick this reads what is currently true and records what the
 * deterministic evaluator concludes. It runs with no browser, no chat prompt,
 * no model call and no human present, and it holds no payment authority: it
 * creates no payment intent, reserves nothing, and never calls a rail.
 *
 * It also never passes a fabricated human actor to a staff action. §14.7 is
 * explicit that a background workflow must not impersonate a person, so this
 * stops at recording a disposition. Turning a `release` into an actual payment
 * needs explicit institution-scoped service authority, which is a separate
 * mechanism — not a synthetic `User` handed to `ReserveVendorPayment`.
 *
 * Re-running a tick is safe. The occurrence identity is derived from the bill,
 * so a second pass finds the existing record and declines rather than paying
 * the same obligation twice.
 */
final readonly class MandateOccurrenceScanner
{
    public function __construct(private MandateReleaseEvaluator $evaluator, private ReservationCapacity $capacity) {}

    /**
     * Keys are the disposition names the evaluator emits, so a caller can
     * index them directly without translating.
     *
     * @return array{scanned:int, release:int, escalate:int, blocked:int, skipped:int}
     */
    public function scan(Organization $institution, ?CarbonImmutable $at = null): array
    {
        // Gated here as well as at the command, so any future caller -- a job,
        // an endpoint, a test -- cannot bypass it by simply not being the CLI.
        // The lane is off for the installation, not merely for one entry point.
        if (! config('eduflow.mandate.runtime_enabled', false)) {
            return ['scanned' => 0, 'release' => 0, 'escalate' => 0, 'blocked' => 0, 'skipped' => 0];
        }

        $at ??= CarbonImmutable::now();
        $summary = ['scanned' => 0, 'release' => 0, 'escalate' => 0, 'blocked' => 0, 'skipped' => 0];

        foreach ($this->liveMandates($institution, $at) as $mandate) {
            $summary['scanned']++;

            /** @var Wallet|null $wallet */
            $wallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($mandate->wallet_id)->first();

            if ($wallet === null) {
                continue;
            }

            foreach ($this->candidateBills($mandate, $at) as $bill) {
                $recorded = $this->record($institution, $mandate, $wallet, $bill, $at);

                if ($recorded === null || ! array_key_exists($recorded, $summary)) {
                    $summary['skipped']++;

                    continue;
                }

                $summary[$recorded]++;
            }
        }

        return $summary;
    }

    /**
     * Record one occurrence's disposition.
     *
     * @return string|null The recorded disposition, or null when none was.
     */
    private function record(Organization $institution, RecurringMandate $mandate, Wallet $wallet,
        InvoiceVersion $bill, CarbonImmutable $at): ?string
    {
        $key = 'bill:'.$bill->id;
        $digest = $this->evaluator->occurrenceDigest($mandate, $key);

        if (MandateOccurrence::query()->where('recurring_mandate_id', $mandate->id)
            ->where('occurrence_digest', $digest)->exists()) {
            return null;
        }

        $checks = $this->judge($institution, $mandate, $wallet, $bill, $key, $digest, $at);

        return DB::transaction(function () use ($institution, $mandate, $bill, $key, $digest, $checks): ?string {
            $occurrence = new MandateOccurrence([
                'request_key' => (string) Str::uuid(),
                'organization_id' => $institution->id,
                'recurring_mandate_id' => $mandate->id,
                'occurrence_digest' => $digest,
                'amount_base_units' => $bill->valuation_base_units,
                'chain' => $mandate->chain,
                'chain_id' => $mandate->chain_id,
                'due_at' => $bill->invoice?->due_date,
                'disposition' => $checks['disposition'],
                'reason' => mb_substr((string) $checks['summary'], 0, 1000),
                'checks' => $checks,
                'checks_digest' => PaymentIntent::digest($checks),
            ]);

            try {
                $occurrence->save();
            } catch (Throwable $exception) {
                // The unique index on (mandate, occurrence) is the real guard.
                // Losing that race is a normal outcome, not a failure.
                if (! str_contains(strtolower($exception->getMessage()), 'unique')) {
                    throw $exception;
                }

                return null;
            }

            activity('finance')->performedOn($occurrence)->event('mandate_occurrence_'.$checks['disposition'])
                ->withProperties([
                    'recurring_mandate_id' => $mandate->id,
                    'obligation_reference' => $mandate->obligation_reference,
                    'occurrence_key' => $key,
                    'disposition' => $checks['disposition'],
                    'amount_base_units' => (string) $bill->valuation_base_units,
                    // Classifying an occurrence is never authority to pay it.
                    'can_execute' => false,
                    'payments_submitted' => 0,
                ])
                ->log((string) $checks['summary']);

            return $checks['disposition'];
        }, 3);
    }

    /**
     * Ask the evaluator, or record unreadable facts as blocked.
     *
     * A fact that cannot be read is unknown, not permission to pay and not
     * permission to guess. It is recorded so the case is visible and
     * investigable rather than silently skipped.
     *
     * @return array<string, mixed>
     */
    private function judge(Organization $institution, RecurringMandate $mandate, Wallet $wallet,
        InvoiceVersion $bill, string $key, string $digest, CarbonImmutable $at): array
    {
        try {
            $checks = $this->evaluator->evaluate($mandate,
                $this->facts($institution, $mandate, $wallet, $bill, $key, $digest, $at));
        } catch (Throwable $exception) {
            Log::warning('Mandate occurrence facts unreadable; recording as blocked.', [
                'mandate_id' => $mandate->id,
                'bill_id' => $bill->id,
                'exception' => $exception::class,
            ]);

            // Shaped like a gate rather than a bare reason, so the shared
            // summary can name it and an operator sees which gate held.
            $checks = [
                'passed' => false,
                'disposition' => 'blocked',
                'facts_readable' => ['passed' => false, 'severity' => 'blocked',
                    'reason' => 'Live evidence could not be read: '.$exception->getMessage()],
            ];
        }

        $checks['checked_bill_id'] = $bill->id;
        $checks['evaluated_at'] = $at->toIso8601String();
        $checks['summary'] = MandateReleaseEvaluator::summary($checks);

        return $checks;
    }

    /**
     * Live facts, read fresh and expressed as exact integers.
     *
     * Nothing is inferred from a previous payment.
     *
     * @return array<string, mixed>
     */
    private function facts(Organization $institution, RecurringMandate $mandate, Wallet $wallet,
        InvoiceVersion $bill, string $key, string $digest, CarbonImmutable $at): array
    {
        $observation = app(ArcBalanceObservation::class)->capture($wallet);
        $chain = $this->capacity->verify($institution->id);
        $destination = VendorDestinationVersion::query()
            ->where('vendor_id', $mandate->vendor_id)
            ->whereKey($mandate->vendor_destination_version_id)->firstOrFail();
        $policy = FinancePolicyVersion::query()
            ->where('organization_id', $institution->id)
            ->whereKey($mandate->finance_policy_version_id)->firstOrFail();

        return [
            'at' => $at,
            'occurrence_key' => $key,
            'occurrence_digest' => $digest,
            'bill_amount_base_units' => BigInteger::of($bill->valuation_base_units),
            'max_fee_base_units' => BigInteger::of($policy->max_fee_base_units),
            'destination_digest' => $destination->content_digest,
            'policy_digest' => $policy->content_digest,
            // What a reviewer recorded as protected is not spendable. With no
            // reviewed window there is nothing to protect by, which is exactly
            // why `funding_window_valid` is reported separately and refuses
            // the release rather than defaulting to "no reserve".
            'available_cash_base_units' => $this->unprotectedCash($institution, $mandate, $observation),
            'consuming_holds_base_units' => $chain['consuming'],
            'daily_released_base_units' => $this->releasedSince($mandate, $at->startOfDay()),
            'period_released_base_units' => $this->releasedSince($mandate, Carbon::instance($mandate->starts_at)->toImmutable()),
            'is_fake' => $observation['is_fake'],
            'stop_switch' => config('eduflow.submission.stop_switch', false) === true,
            'funding_window_valid' => $this->hasReviewedWindow($institution),
        ];
    }

    private function unprotectedCash(Organization $institution, RecurringMandate $mandate, array $observation): BigInteger
    {
        $window = FundingWindow::query()->where('organization_id', $institution->id)
            ->where('budget_id', $mandate->budget_id)->latest('id')->first();

        $protected = $window instanceof FundingWindow
            ? BigInteger::of($window->snapshot['capacity']['protected_base_units'])
            : BigInteger::zero();

        return BigInteger::of($observation['usdc_base_units'])->minus($protected);
    }

    private function hasReviewedWindow(Organization $institution): bool
    {
        $approval = FundingWindowApproval::query()
            ->where('organization_id', $institution->id)
            ->whereNull('superseded_at')
            ->latest('id')
            ->first();

        if (! $approval instanceof FundingWindowApproval) {
            return false;
        }

        /** @var FundingWindow|null $window */
        $window = FundingWindow::query()->where('organization_id', $institution->id)
            ->whereKey($approval->funding_window_id)->first();

        return $window instanceof FundingWindow && ! $window->isExpired() && $window->hasValidSnapshot();
    }

    /**
     * Cumulative already released under this mandate since a point in time.
     *
     * Counts every recorded occurrence, not only ones that went on to settle,
     * so an occurrence released and then never paid still occupies its share of
     * the cap. Caps must not reset themselves.
     */
    private function releasedSince(RecurringMandate $mandate, CarbonImmutable $since): BigInteger
    {
        $total = BigInteger::zero();

        foreach (MandateOccurrence::query()->where('recurring_mandate_id', $mandate->id)
            ->where('disposition', 'release')->get() as $occurrence) {
            if ($occurrence->due_at->lt($since)) {
                continue;
            }
            $total = $total->plus($occurrence->amount_base_units);
        }

        return $total;
    }

    /** @return Collection<int, RecurringMandate> */
    private function liveMandates(Organization $institution, CarbonImmutable $at): Collection
    {
        return RecurringMandate::query()
            ->where('organization_id', $institution->id)
            ->where('state', 'approved')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            // A zero allowance releases nothing, so scanning one is pointless
            // work rather than a blocked occurrence nobody will read.
            ->where('per_occurrence_ceiling_base_units', '>', 0)
            ->orderBy('id')
            ->get();
    }

    /**
     * Approved bills for this mandate's vendor and budget, due inside its
     * window, not yet held for payment.
     *
     * @return Collection<int, InvoiceVersion>
     */
    private function candidateBills(RecurringMandate $mandate, CarbonImmutable $at): Collection
    {
        $earliest = Carbon::instance($mandate->starts_at)->toImmutable()->subDays($mandate->due_window_days);

        return InvoiceVersion::query()
            ->where('organization_id', $mandate->organization_id)
            ->where('vendor_id', $mandate->vendor_id)
            ->where('budget_id', $mandate->budget_id)
            ->orderBy('id')
            ->get()
            ->filter(fn (InvoiceVersion $bill): bool => $this->billIsCandidate($mandate, $bill, $earliest, $at));
    }

    /**
     * Is this bill inside a current, reviewed closed set for the budget?
     *
     * §17.2 is explicit that the planner may only say *of the bills you
     * approved, these fit* — it has no bill discovery. The same has to hold
     * here: a standing mandate may pay only bills a human actually put in a
     * reviewed allocation. Without this a mandate would quietly widen its own
     * reach to every invoice the vendor ever sends.
     */
    private function billIsInReviewedSet(RecurringMandate $mandate, InvoiceVersion $bill): bool
    {
        $snapshots = BudgetSnapshot::query()
            ->where('organization_id', $mandate->organization_id)
            ->where('budget_id', $mandate->budget_id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($snapshots as $snapshot) {
            if (! $snapshot->hasValidSnapshot()) {
                continue;
            }

            $validUntil = (string) ($snapshot->snapshot['valid_until'] ?? '');
            if ($validUntil === '') {
                continue;
            }
            if (Carbon::parse($validUntil)->lt(Carbon::now())) {
                continue;
            }

            foreach ($snapshot->snapshot['bills'] ?? [] as $entry) {
                if (($entry['id'] ?? null) === $bill->id
                    && ($entry['digest'] ?? null) === $bill->snapshot_digest) {
                    return true;
                }
            }
        }

        return false;
    }

    private function billIsCandidate(RecurringMandate $mandate, InvoiceVersion $bill, CarbonImmutable $earliest, CarbonImmutable $at): bool
    {
        $invoice = $bill->invoice;

        if ($invoice === null || ! $bill->hasValidSnapshot()) {
            return false;
        }

        if ($invoice->due_date->lt($earliest) || $invoice->due_date->gt($at)) {
            return false;
        }

        if (! $this->billIsInReviewedSet($mandate, $bill)) {
            return false;
        }

        $review = InvoiceVersionReview::query()
            ->where('invoice_version_id', $bill->id)
            ->where('decision', 'approve_evidence')
            ->latest('id')
            ->first();

        if (! $review instanceof InvoiceVersionReview || ! $review->hasValidEvidence($bill)) {
            return false;
        }

        // An invoice already held for payment is not a fresh occurrence,
        // whatever the schedule says.
        return ! PaymentReservation::query()->where('invoice_id', $invoice->id)->exists();
    }
}
