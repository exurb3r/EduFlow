<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Money;
use App\Jobs\EvaluateInstitutionPolicy;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\InstitutionPolicyPublication;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Admin-requested fresh advice. Never reviews receipts or changes financial records. */
final readonly class InstitutionPolicyEvaluations
{
    public function __construct(private InstallationInstitution $institutions, private InstitutionPolicyWorkspace $policies) {}

    public function queueReady(): bool
    {
        $connection = config('eduflow.background_finance.queue_connection', 'database');

        return in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            && (int) config('queue.connections.'.$connection.'.retry_after', 0) > 180;
    }

    /** @return array<string, mixed> */
    public function source(User $actor, string $type, int $id): array
    {
        Gate::forUser($actor)->authorize('create', InstitutionPolicyEvaluation::class);
        $institutionId = $this->institutions->require()->id;

        if ($type === 'collection') {
            $batch = CollectionBatch::query()->where('organization_id', $institutionId)->findOrFail($id);
            Gate::forUser($actor)->authorize('view', $batch);
            if (! $batch->hasValidSnapshot()) {
                throw ValidationException::withMessages(['source' => 'Collection evidence is damaged. Investigate before requesting advice.']);
            }
            $review = CollectionBatchReview::query()->where('collection_batch_id', $batch->id)->first();
            $reviewed = $review !== null && $review->decision === 'approve_receipts' && $review->hasValidEvidence($batch);
            $evidence = ['description' => 'Institution collection '.$batch->source_reference.' from '.$batch->source_stream
                .'. Received '.Money::formatExact($batch->received_minor_units, $batch->currency)
                .'; restricted '.Money::formatExact($batch->restricted_minor_units, $batch->currency).'. Receipt review: '.($reviewed ? 'approved' : 'not approved').'.',
                'reference' => $batch->source_reference, 'source_stream' => $batch->source_stream, 'currency' => $batch->currency,
                'received_minor_units' => (string) $batch->received_minor_units, 'restricted_minor_units' => (string) $batch->restricted_minor_units,
                'batch_digest' => $batch->snapshot_digest, 'review_digest' => $review?->review_digest,
                'reviewed_receipt' => $reviewed, 'movement_sourced' => $batch->isMovementSourced(), 'funding_context_bound' => false];

            return $this->envelope($type, $id, 'Collection #'.$id.' · '.$batch->source_reference, $evidence);
        }

        if ($type === 'transaction') {
            $transaction = Transaction::query()->where('organization_id', $institutionId)->findOrFail($id);
            Gate::forUser($actor)->authorize('view', $transaction);
            $evidence = ['description' => 'Recorded institution transaction of type '.$transaction->type->value
                .' with status '.$transaction->status->value.'. Ledger amount '.$transaction->getRawOriginal('amount')
                .' '.$transaction->currency.'. This is classification evidence only, not proof of collected cash.',
                'type' => $transaction->type->value, 'status' => $transaction->status->value,
                'recorded_amount' => (string) $transaction->getRawOriginal('amount'), 'currency' => $transaction->currency,
                'network' => $transaction->network, 'provider_tx_hash' => $transaction->provider_tx_hash,
                'recipient_address' => $transaction->recipient_address,
                'reconciliation' => $transaction->metadata['reconciliation'] ?? null,
                'is_fake' => ($transaction->metadata['is_fake'] ?? false) === true,
                'simulated_inbound' => ($transaction->metadata['simulated_inbound'] ?? false) === true,
                'funding_context_bound' => false];

            return $this->envelope($type, $id, 'Transaction #'.$id.' · '.$transaction->type->value, $evidence);
        }

        if ($type === 'budget_snapshot') {
            $snapshot = BudgetSnapshot::query()->where('organization_id', $institutionId)->findOrFail($id);
            Gate::forUser($actor)->authorize('view', $snapshot);
            if (! $snapshot->hasValidSnapshot()) {
                throw ValidationException::withMessages(['source' => 'Planning evidence is damaged. Capture a new reviewed context.']);
            }
            if ($snapshot->currency !== 'USDC') {
                throw ValidationException::withMessages(['currency' => 'Allocation evaluation requires USDC planning evidence; currencies are never converted implicitly.']);
            }

            return $this->envelope($type, $id, 'Budget snapshot #'.$id.' · '.$snapshot->snapshot['department']['name'],
                ['snapshot_digest' => $snapshot->snapshot_digest, 'currency' => $snapshot->currency,
                    'realized_receipts_minor_units' => $snapshot->snapshot['amounts']['realized_receipts'],
                    'opening_funds_minor_units' => $snapshot->snapshot['amounts']['opening_funds'],
                    'restricted_minor_units' => $snapshot->snapshot['amounts']['restricted_cash'],
                    'protected_reserve_minor_units' => $snapshot->snapshot['amounts']['protected_reserve'],
                    'captured_cash_headroom_minor_units' => $snapshot->headroom()['cash_minor_units'], 'funding_context_bound' => true]);
        }

        throw ValidationException::withMessages(['source_type' => 'Choose a collection, transaction or budget snapshot.']);
    }

    /** @param array<string, mixed> $data */
    public function request(User $actor, array $data): InstitutionPolicyEvaluation
    {
        Gate::forUser($actor)->authorize('create', InstitutionPolicyEvaluation::class);
        if (! $this->queueReady()) {
            throw ValidationException::withMessages(['queue' => 'A database or Redis queue with retry_after greater than 180 seconds is required.']);
        }

        $evaluation = DB::transaction(function () use ($actor, $data): InstitutionPolicyEvaluation {
            $institution = $this->institutions->require();
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $existing = InstitutionPolicyEvaluation::query()->where('request_key', $data['request_key'])->first();
            if ($existing !== null) {
                Gate::forUser($actor)->authorize('view', $existing);
                if ($existing->requested_by !== $actor->id || $existing->source_type !== $data['source_type']
                    || $existing->source_id !== (int) $data['source_id'] || $existing->source_digest !== $data['source_digest']
                    || $existing->publication_digest !== $data['expected_publication_digest'] || $existing->reason !== trim($data['reason'])) {
                    throw ValidationException::withMessages(['request_key' => 'Request identity already belongs to different evidence or requester.']);
                }

                return $existing;
            }
            $publication = $this->policies->active();
            if (! $publication instanceof InstitutionPolicyPublication || $publication->publication_digest !== $data['expected_publication_digest']) {
                throw ValidationException::withMessages(['policy' => 'Published policy changed or is not active. Refresh before re-evaluating.']);
            }
            $input = $this->source($actor, $data['source_type'], (int) $data['source_id']);
            if (! hash_equals($input['source_digest'], $data['source_digest'])) {
                throw ValidationException::withMessages(['source' => 'Source evidence changed. Refresh and inspect it before re-evaluating.']);
            }
            $running = InstitutionPolicyEvaluation::query()->where('organization_id', $institution->id)
                ->where('source_type', $data['source_type'])->where('source_id', $data['source_id'])->whereIn('state', ['queued', 'running'])->exists();
            if ($running) {
                throw ValidationException::withMessages(['source' => 'This source already has queued or running work. Inspect its progress before starting another run.']);
            }
            $evaluation = InstitutionPolicyEvaluation::query()->create([
                'organization_id' => $institution->id, 'institution_policy_version_id' => $publication->institution_policy_version_id,
                'requested_by' => $actor->id, 'request_key' => $data['request_key'], 'source_type' => $data['source_type'],
                'source_id' => (int) $data['source_id'], 'source_digest' => $input['source_digest'], 'policy_digest' => $publication->policy_digest,
                'publication_digest' => $publication->publication_digest, 'reason' => trim($data['reason']),
                'input' => $input, 'input_digest' => PaymentIntent::digest($input),
                'timeline' => [['stage' => 'queued', 'message' => 'Fresh advisory run queued. Waiting for a worker; nothing is approved or paid.',
                    'at' => now()->utc()->toIso8601String()]],
            ]);
            activity('finance')->causedBy($actor)->performedOn($evaluation)->event('institution_policy_evaluation_requested')
                ->withProperties(['source_type' => $evaluation->source_type, 'source_id' => $evaluation->source_id, 'can_execute' => false])
                ->log('Fresh policy evaluation requested; financial evidence unchanged');

            return $evaluation;
        }, 3);

        if ($evaluation->wasRecentlyCreated) {
            try {
                Bus::dispatch(new EvaluateInstitutionPolicy($evaluation->id));
            } catch (Throwable $exception) {
                report($exception);
                $evaluation->checkpoint('needs_review', 'Queue unavailable. Request a fresh run after service is restored.',
                    ['state' => 'needs_review', 'completed_at' => now(), 'last_error' => 'Queue unavailable. No evaluation or financial action occurred.']);
            }
        }

        return $evaluation->refresh();
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function envelope(string $type, int $id, string $label, array $evidence): array
    {
        return ['source_type' => $type, 'source_id' => $id, 'label' => $label, 'evidence' => $evidence,
            'source_digest' => PaymentIntent::digest(['source_type' => $type, 'source_id' => $id, 'evidence' => $evidence])];
    }
}
