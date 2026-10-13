<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessPaymentSubmission;
use App\Models\PaymentSubmissionOutbox;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Hands durably owed submissions to the queue, and recovers the ones a crash
 * left committed but undispatched.
 *
 * This is visibility and durability, not execution. Dispatching an entry means
 * a worker will *look at it*; nothing here authorises a transfer, and the
 * runtime stays off unless an operator turns it on. A `sync` connection is
 * refused outright, because request-bound dispatch would defeat the entire
 * point of an outbox.
 */
final readonly class PaymentSubmissionDispatch
{
    /** States this dispatcher may hand to a submission worker. */
    public const array DISPATCHABLE = PaymentSubmissionOutbox::DISPATCHABLE_STATES;

    public function __construct(private Dispatcher $bus, private InstallationInstitution $institutions) {}

    /**
     * @return int Entries handed to the queue on this pass.
     */
    public function dispatch(int $limit = 50): int
    {
        $this->requireRuntime();

        $institution = $this->institutions->require();
        $now = Carbon::now();

        $entries = PaymentSubmissionOutbox::query()
            ->where('organization_id', $institution->id)
            ->whereIn('state', PaymentSubmissionDispatch::DISPATCHABLE)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('id')
            ->limit($limit)
            ->lockForUpdate()
            ->get();

        $dispatched = 0;

        foreach ($entries as $entry) {
            // This timestamp records dispatch, not a claim that the worker ran.
            // Job uniqueness and execution-time checks remain separate guards.
            $entry->dispatched_at ??= $now;
            $entry->save();

            $this->bus->dispatch(new ProcessPaymentSubmission($entry->id));
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Stale leases are work that was claimed and never concluded.
     *
     * They become unknown, never queued: a signer may already have been reached.
     * Observation or an operator investigation resolves the existing identity.
     */
    public function recoverStaleLeases(int $staleAfterMinutes = 15): int
    {
        $this->requireRuntime();

        $institution = $this->institutions->require();
        $cutoff = Carbon::now()->subMinutes($staleAfterMinutes);

        return PaymentSubmissionOutbox::query()
            ->where('organization_id', $institution->id)
            ->where('state', 'running')
            ->where('heartbeat_at', '<', $cutoff)
            ->update([
                'state' => 'unknown',
                'stage' => 'lease_expired',
                'last_error' => 'Worker lease expired without a recorded outcome. Reconcile the existing attempt before any retry.',
                'updated_at' => Carbon::now(),
            ]);
    }

    private function requireRuntime(): void
    {
        if (! config('eduflow.submission.enabled', false)) {
            throw ValidationException::withMessages([
                'submission' => 'Payment submission runtime is disabled. Enabling it is a separate, reviewed decision.',
            ]);
        }

        if (config('queue.default') === 'sync' || config('eduflow.submission.queue_connection') === 'sync') {
            throw ValidationException::withMessages([
                'submission' => 'Submission work requires a durable queue; a synchronous connection is refused.',
            ]);
        }
    }
}
