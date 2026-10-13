<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionAttempt;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use App\Services\IsolatedPaymentExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Picks up one durably owed submission and, when an executor is enabled,
 * submits it once through the isolated component.
 *
 * Submission and settlement are separate acts. This job can only reach
 * `submitted`, which records a provider reference and nothing more. Whether
 * that reference became money on Arc is decided later by
 * `ReconcilePaymentSubmission` through `ArcSettlementVerifier`, and only a
 * `verified` verdict moves an entry to `completed`. A stored hash is a claim.
 */
class ProcessPaymentSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $outboxId)
    {
        $this->onConnection((string) config('eduflow.submission.queue_connection', 'database'));
        $this->onQueue((string) config('eduflow.submission.queue', 'finance-submission'));
        $this->afterCommit();
    }

    public function handle(IsolatedPaymentExecutor $executor): void
    {
        $entry = $this->claim();

        if (! $entry instanceof PaymentSubmissionOutbox) {
            return;
        }

        $blocked = $this->blockingReason($entry);

        if ($blocked !== null) {
            [$stage, $reason] = $blocked;
            $this->conclude($entry, 'blocked', $stage, $reason);

            return;
        }

        if (! config('eduflow.submission.enabled', false)) {
            $this->conclude($entry, 'blocked', 'runtime_disabled',
                'Payment submission is not enabled for this installation. The payment remains authorized, durably owed and unsent.');

            return;
        }

        try {
            // Fee preflight before anything is broadcast.
            $estimate = $executor->estimate($entry);
            $entry->stage = 'fee_preflight_passed';
            $entry->save();

            $submission = $executor->submit($entry);
        } catch (ValidationException $exception) {
            $this->conclude($entry, 'blocked', 'preflight_refused', $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->conclude($entry, 'unknown', 'executor_failure',
                'Executor failed before a provider outcome was known: '.$exception->getMessage());

            return;
        }

        if ($submission['outcome'] === 'unknown') {
            // The provider's own words are the whole value of an `unknown`
            // outcome. Discarding them and recording a generic message would
            // make "the rail refused this submission" indistinguishable from
            // "we could not reach the rail", which are different
            // investigations with different remedies.
            $this->conclude($entry, 'unknown', 'provider_unknown',
                (string) ($submission['detail']['error']
                    ?? 'Provider outcome is unknown. Reconcile the existing attempt before any retry; a blind resend risks paying twice.'),
                $submission['detail'], $estimate);

            return;
        }

        if ($submission['outcome'] === 'blocked') {
            $this->conclude($entry, 'blocked', 'simulation', (string) ($submission['detail']['reason'] ?? 'Simulation only.'));

            return;
        }

        // Accepted by the rail, not yet on chain. The rail holds the transfer and has
        // issued its own identifier; no on-chain hash exists, so none is
        // recorded. Reconciliation observes the rail and promotes the entry to
        // `submitted` only once a real hash appears. It is in flight, not
        // settled, and never dispatchable again.
        if ($submission['outcome'] === 'accepted') {
            $handle = (string) ($submission['provider_handle'] ?? '');

            // The handle is written before the attempt, because the attempt
            // binds to it. An append-only record of an acceptance that could
            // not later be traced back to the rail's own identifier would be
            // an acceptance nobody could act on.
            $entry->state = 'accepted';
            $entry->stage = 'awaiting_rail_completion';
            $entry->provider_handle = $handle;
            $entry->next_attempt_at = null;
            $entry->result = ['provider_handle' => $handle, 'provider_reference' => null,
                'settled' => false, 'can_execute' => false, 'payments_submitted' => 1];
            $entry->result_digest = PaymentIntent::digest($entry->result);
            $entry->save();

            $this->record($entry, 'accepted', null, $submission['detail'], $estimate);

            return;
        }

        // Submitted, not settled. The reference is recorded and verified
        // separately; nothing here concludes that money moved.
        $this->record($entry, 'submitted', (string) $submission['provider_reference'], $submission['detail'], $estimate);

        $entry->state = 'submitted';
        $entry->stage = 'awaiting_settlement_verification';
        $entry->next_attempt_at = null;
        $entry->result = ['provider_reference' => $submission['provider_reference'],
            'settled' => false, 'can_execute' => false, 'payments_submitted' => 1];
        $entry->result_digest = PaymentIntent::digest($entry->result);
        $entry->save();
    }

    /** Claim once before external I/O; duplicate delivery may only observe, never sign. */
    private function claim(): ?PaymentSubmissionOutbox
    {
        return DB::transaction(function (): ?PaymentSubmissionOutbox {
            $entry = PaymentSubmissionOutbox::query()->whereKey($this->outboxId)->lockForUpdate()->first();

            if ($entry === null || $entry->state !== 'queued' || $entry->attempts !== 0
                || $entry->provider_handle !== null || ($entry->result['provider_reference'] ?? null) !== null
                || $entry->attempts()->exists()) {
                return null;
            }

            $entry->update(['state' => 'running', 'stage' => 'revalidating_authority',
                'heartbeat_at' => Carbon::now(), 'attempts' => 1]);

            return $entry;
        }, 3);
    }

    /**
     * Everything that must hold at execution time, re-read rather than
     * trusted.
     *
     * Returns a distinct stage per refusal so an operator can see *which*
     * gate held the work, rather than a single opaque "blocked".
     *
     * @return array{0: string, 1: string}|null
     */
    private function blockingReason(PaymentSubmissionOutbox $entry): ?array
    {
        if (! $entry->hasValidSnapshot() || ! $entry->bindsCurrentAuthorization()) {
            return ['authorization_stale', 'Authorization evidence is no longer intact or current. Fresh review is required; nothing is submitted.'];
        }

        $authorization = $entry->authorization?->evidence() ?? [];

        if (($authorization['payment_approved'] ?? false) !== true) {
            return ['approval_expired', 'Approval is no longer current. An expired authorization is never submitted on its original authority.'];
        }

        if (($authorization['is_fake'] ?? false) === true) {
            return ['simulation_evidence', 'Bound evidence is from the fake driver. Simulation is never submitted to a network rail.'];
        }

        if (config('eduflow.submission.stop_switch', false) === true) {
            return ['stop_switch', 'Submission stop switch is engaged. Queued work is held; in-flight reconciliation and evidence are preserved.'];
        }

        return null;
    }

    /** @param array<string, mixed> $detail */
    private function record(PaymentSubmissionOutbox $entry, string $outcome, ?string $reference,
        array $detail = [], ?array $estimate = null): void
    {
        $attempt = new PaymentSubmissionAttempt([
            'organization_id' => $entry->organization_id,
            'payment_submission_outbox_id' => $entry->id,
            'attempt_number' => $entry->attempts,
            'provider_idempotency_key' => $entry->provider_idempotency_key,
            'outcome' => $outcome,
            'provider_reference' => $reference,
            'request_snapshot' => [
                'request_key' => $entry->request_key,
                'payment_intent_id' => $entry->payment_intent_id,
                'amount_base_units' => $entry->snapshot['amount_base_units'] ?? null,
                'chain' => $entry->snapshot['chain'] ?? null,
                'recipient_address' => $entry->snapshot['recipient_address'] ?? null,
            ],
            'response_snapshot' => ['detail' => $detail, 'estimate' => $estimate],
            'observed_at' => Carbon::now(),
        ]);
        $attempt->save();
    }

    /** @param array<string, mixed> $detail */
    private function conclude(PaymentSubmissionOutbox $entry, string $state, string $stage, string $error,
        array $detail = [], ?array $estimate = null): void
    {
        $this->record($entry, $state === 'unknown' ? 'unknown' : 'blocked', null, $detail, $estimate);

        $entry->state = $state;
        $entry->stage = $stage;
        $entry->last_error = mb_substr($error, 0, 1000);
        $entry->next_attempt_at = null;
        $entry->result = ['stage' => $stage, 'settled' => false, 'can_execute' => false,
            'payments_submitted' => $state === 'unknown' ? null : 0];
        $entry->result_digest = PaymentIntent::digest($entry->result);
        $entry->save();

        activity('finance')->performedOn($entry)->event('payment_submission_'.$state)
            ->withProperties([
                'payment_intent_id' => $entry->payment_intent_id,
                'stage' => $stage,
                'can_execute' => false,
                'payments_submitted' => $state === 'unknown' ? null : 0,
            ])
            ->log($error);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }

    /** Nothing in this build may ever produce a transaction row from this job. */
    public static function transactionCount(): int
    {
        return Transaction::query()->count();
    }
}
