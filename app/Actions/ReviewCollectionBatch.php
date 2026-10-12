<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\FinanceWorkflowDispatcher;
use App\Services\FinanceWorkflows;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ReviewCollectionBatch
{
    public function __construct(
        private InstallationInstitution $institutions,
        private FinanceWorkflows $workflows,
        private FinanceWorkflowDispatcher $dispatcher,
    ) {}

    public function handle(User $reviewer, CollectionBatch $batch, string $expectedDigest, string $decision, string $reference, string $reason): CollectionBatchReview
    {
        Gate::forUser($reviewer)->authorize('review', $batch);
        if (! in_array($decision, ['approve_receipts', 'reject', 'hold'], true) || trim($reference) === '' || mb_strlen($reference) > 255
            || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['decision' => 'Explicit collection decision, independent verification reference and reason required.']);
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $batch, $expectedDigest, $decision, $reference, $reason, $institution): CollectionBatchReview {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var CollectionBatch $stored */
            $stored = CollectionBatch::query()->where('organization_id', $institution->id)->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($reviewer)->authorize('review', $stored);
            if (! $stored->hasValidSnapshot() || ! hash_equals($stored->snapshot_digest, $expectedDigest) || $stored->collected_until->isFuture()) {
                throw ValidationException::withMessages(['expected_digest' => 'Intact realized collection evidence and exact expected digest required.']);
            }
            /** @var CollectionBatchReview|null $existing */
            $existing = CollectionBatchReview::query()->where('collection_batch_id', $stored->id)->first();
            if ($existing !== null) {
                if (! $existing->hasValidEvidence($stored) || $existing->reviewed_by !== $reviewer->id || $existing->decision !== $decision
                    || $existing->verification_reference !== $reference || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['decision' => 'Collection review already recorded with different evidence or feedback.']);
                }

                $this->workflows->collectionReviewed($existing);
                $this->dispatcher->wakeAfterCommit('collection:'.$stored->id);

                return $existing;
            }
            $review = new CollectionBatchReview(['organization_id' => $institution->id, 'collection_batch_id' => $stored->id,
                'reviewed_by' => $reviewer->id, 'decision' => $decision, 'verification_reference' => $reference, 'reason' => $reason, 'batch_digest' => $stored->snapshot_digest]);
            $review->review_digest = PaymentIntent::digest($review->content());
            $review->save();

            $this->workflows->collectionReviewed($review);
            $this->dispatcher->wakeAfterCommit('collection:'.$stored->id);

            activity('finance')->causedBy($reviewer)->performedOn($review)->event('collection_batch_reviewed')
                ->withProperties(['batch_id' => $stored->id, 'decision' => $decision, 'review_digest' => $review->review_digest, 'can_execute' => false])
                ->log('Collection source and restrictions reviewed; no posting, conversion or payment authority');

            return $review;
        }, 3);
    }
}
