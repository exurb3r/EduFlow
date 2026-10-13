<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\PaymentIntent;
use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class ReviewedCollections
{
    /** @param list<int> $reviewIds
     * @return list<array{review_id:int, review_digest:string, batch_id:int, batch_digest:string, received_minor_units:string, restricted_minor_units:string, movement_receipt_minor_units:int}>
     */
    public function capture(int $institutionId, string $currency, array $reviewIds, string $asOf, string $expectedReceived, string $restrictedCash): array
    {
        sort($reviewIds, SORT_NUMERIC);
        $entries = [];
        $received = BigInteger::zero();
        $restricted = BigInteger::zero();
        foreach ($reviewIds as $id) {
            /** @var CollectionBatchReview|null $review */
            $review = CollectionBatchReview::query()->where('organization_id', $institutionId)->whereKey($id)->first();
            /** @var CollectionBatch|null $batch */
            $batch = $review === null ? null : CollectionBatch::query()->where('organization_id', $institutionId)->whereKey($review->collection_batch_id)->first();
            if ($review === null || $batch === null || ! $review->hasValidEvidence($batch) || $review->decision !== 'approve_receipts'
                || $batch->currency !== $currency || $batch->collected_until->gt(Carbon::parse($asOf))) {
                throw ValidationException::withMessages(['collection_review_ids' => 'Intact independently approved receipts in the same currency, received by the snapshot time, are required.']);
            }
            $received = $received->plus($batch->received_minor_units);
            $restricted = $restricted->plus($batch->restricted_minor_units);

            $movementMinor = $batch->isMovementSourced() ? $batch->received_minor_units : 0;
            if ($movementMinor !== 0) {
                $this->requireVerifiedMovement($batch);
            }

            $entries[] = ['review_id' => $review->id, 'review_digest' => $review->review_digest, 'batch_id' => $batch->id,
                'batch_digest' => $batch->snapshot_digest, 'received_minor_units' => (string) $batch->received_minor_units,
                'restricted_minor_units' => (string) $batch->restricted_minor_units,
                'movement_receipt_minor_units' => $movementMinor];
        }
        if ((string) $received !== $expectedReceived || $restricted->isGreaterThan($restrictedCash)) {
            throw ValidationException::withMessages(['realized_receipts' => 'Realized receipts must equal reviewed gross collections; restricted cash must include their recorded restrictions.']);
        }

        return $entries;
    }

    /** Historical verification flags alone must not promote digital receipts into planning cash. */
    private function requireVerifiedMovement(CollectionBatch $batch): void
    {
        if ($batch->student_bill_payment_id !== null) {
            $payment = StudentBillPayment::query()->find($batch->student_bill_payment_id);
            if (! $payment instanceof StudentBillPayment || ! $payment->hasValidSettlement() || ! $payment->isAllocated()
                || $payment->organization_id !== $batch->organization_id || $payment->chain !== StudentBillPayment::CHAIN
                || $payment->settlement_verified_at === null || $payment->settlement_verified_at->isFuture()
                || $payment->executed_at === null || $payment->executed_at->isFuture()
                || $payment->applied_base_units < 0 || $payment->overpayment_base_units < 0
                || ! BigInteger::of($payment->applied_base_units)->plus($payment->overpayment_base_units)->isEqualTo($payment->amount_base_units)) {
                throw ValidationException::withMessages(['collection_review_ids' => 'Digital bill receipts require matching immutable consent, verified Arc-testnet proof and exact allocation. A verification flag alone cannot supply planning cash.']);
            }

            return;
        }

        $payment = StudentTuitionPayment::query()->find($batch->student_tuition_payment_id);
        $attempt = $payment instanceof StudentTuitionPayment && is_string($payment->request_key)
            ? StudentTuitionPaymentAttempt::query()->where('request_key', $payment->request_key)->first() : null;

        if (! $payment instanceof StudentTuitionPayment || ! $attempt instanceof StudentTuitionPaymentAttempt
            || ! $payment->isReal() || ! $attempt->isVerified() || $attempt->settlement_verified_at === null
            || $attempt->settlement_verified_at->isFuture() || $payment->executed_at->isFuture()
            || $payment->chain !== 'ARC-TESTNET' || $attempt->chain !== 'ARC-TESTNET' || $attempt->chain_id !== 5042002
            || $payment->tx_hash !== $attempt->provider_reference || preg_match('/^0x[0-9a-fA-F]{64}$/D', $payment->tx_hash) !== 1
            || $payment->amount_base_units !== $attempt->amount_base_units
            || $payment->applied_base_units < 0 || $payment->overpayment_base_units < 0
            || $payment->student_id !== $attempt->student_id || $payment->student_wallet_id !== $attempt->student_wallet_id
            || $payment->tuition_account_id !== $attempt->tuition_account_id
            || strcasecmp($payment->payer_address, $attempt->payer_address) !== 0
            || strcasecmp($payment->recipient_address, $attempt->recipient_address) !== 0
            || ! BigInteger::of($payment->applied_base_units)->plus($payment->overpayment_base_units)->isEqualTo($payment->amount_base_units)) {
            throw ValidationException::withMessages(['collection_review_ids' => 'Digital planning receipts require a matching verified Arc-testnet attempt and exact allocation. A legacy verification flag alone cannot supply planning cash.']);
        }
    }

    public function requireBound(BudgetSnapshot $snapshot): void
    {
        if (! $snapshot->hasValidSnapshot()) {
            throw ValidationException::withMessages(['collections' => 'Planning snapshot failed integrity checks.']);
        }
        if (($snapshot->snapshot['schema_version'] ?? null) === 1) {
            return;
        }
        $entries = $snapshot->snapshot['collections'];
        $ids = array_column($entries, 'review_id');
        /** @var Collection<int, CollectionBatchReview> $linked */
        $linked = $snapshot->collectionReviews()->get();
        if ($linked->count() !== count($ids) || $linked->pluck('id')->sort()->values()->all() !== $ids) {
            throw ValidationException::withMessages(['collections' => 'Stored collection bindings are incomplete; missing evidence cannot become spendable cash.']);
        }
        $current = $this->capture($snapshot->organization_id, $snapshot->currency, $ids, $snapshot->snapshot['as_of'],
            $snapshot->snapshot['amounts']['realized_receipts'], $snapshot->snapshot['amounts']['restricted_cash']);

        if (($snapshot->snapshot['schema_version'] ?? null) === 2) {
            $current = array_map(static function (array $entry): array {
                if ($entry['movement_receipt_minor_units'] !== 0) {
                    throw ValidationException::withMessages(['collections' => 'Movement receipts require a fresh schema v3 plan with opening-funds exclusion. Historical evidence is not rewritten.']);
                }
                unset($entry['movement_receipt_minor_units']);

                return $entry;
            }, $current);
        }

        if (! hash_equals(PaymentIntent::digest(['collections' => $entries]), PaymentIntent::digest(['collections' => $current]))) {
            throw ValidationException::withMessages(['collections' => 'Bound collection or independent review changed; refresh complete planning evidence.']);
        }
    }
}
