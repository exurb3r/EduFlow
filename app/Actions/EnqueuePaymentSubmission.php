<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentSubmissionOutbox;
use App\Services\InstallationInstitution;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Record that an authorized payment is durably owed to a rail.
 *
 * Runs inside the authorization's own transaction, so an approved payment can
 * never exist without a durable obligation to submit it. That closes the
 * commit-to-broker crash gap by construction: there is no window in which the
 * approval is durable and the work is not.
 *
 * This records intent only. No gateway is contacted, no balance moves, and
 * `can_execute` stays false — the entry says what is owed, not that it may be
 * sent.
 */
final readonly class EnqueuePaymentSubmission
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(PaymentAuthorization $authorization, string $requestKey): PaymentSubmissionOutbox
    {
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'A stable UUID submission identity is required.']);
        }

        $requestKey = strtolower($requestKey);
        $institution = $this->institutions->require();

        /** @var PaymentIntent $intent */
        $intent = PaymentIntent::query()->where('organization_id', $institution->id)->findOrFail($authorization->payment_intent_id);
        /** @var PaymentReservation $reservation */
        $reservation = PaymentReservation::query()->where('organization_id', $institution->id)->findOrFail($authorization->payment_reservation_id);

        $evidence = $authorization->evidence();

        // Only a payment that is approved *right now* becomes owed work. A
        // rejection, a hold, a fake-driver simulation or an expired approval
        // has nothing to submit.
        if ($authorization->decision !== 'approve_payment' || ($evidence['payment_approved'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'payment' => 'Only a current, independently authorized, non-simulated payment may be queued for submission.',
            ]);
        }

        /** @var PaymentSubmissionOutbox|null $existing */
        $existing = PaymentSubmissionOutbox::query()->where('payment_authorization_id', $authorization->id)->first();

        if ($existing !== null) {
            if ($existing->request_key !== $requestKey || $existing->organization_id !== $institution->id
                || ! $existing->hasValidSnapshot() || ! $existing->bindsCurrentAuthorization()) {
                throw ValidationException::withMessages(['request_key' => 'Submission identity already binds different evidence.']);
            }

            return $existing;
        }

        if (PaymentSubmissionOutbox::query()->where('request_key', $requestKey)->exists()) {
            throw ValidationException::withMessages(['request_key' => 'Submission identity belongs to another payment.']);
        }

        $snapshot = $this->snapshot($institution->id, $requestKey, $authorization, $intent, $reservation);

        try {
            /** @var PaymentSubmissionOutbox $entry */
            $entry = PaymentSubmissionOutbox::query()->create([
                'request_key' => $requestKey,
                'organization_id' => $institution->id,
                'payment_authorization_id' => $authorization->id,
                'payment_reservation_id' => $reservation->id,
                'payment_intent_id' => $intent->id,
                'invoice_id' => $intent->invoice_id,
                'provider_idempotency_key' => 'eduflow:'.$requestKey,
                'state' => 'queued',
                'attempts' => 0,
                'max_attempts' => 3,
                'snapshot' => $snapshot,
                'snapshot_digest' => PaymentIntent::digest($snapshot),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['payment' => 'Submission could not be recorded durably; the payment remains authorized and unsent.']);
        }

        activity('finance')->performedOn($entry)->event('payment_submission_queued')
            ->withProperties([
                'payment_intent_id' => $intent->id,
                'payment_authorization_id' => $authorization->id,
                'provider_idempotency_key' => $entry->provider_idempotency_key,
                'can_execute' => false,
                'payments_submitted' => 0,
            ])
            ->log('Authorized payment durably owed to a rail; nothing submitted');

        return $entry;
    }

    /** @return array<string, mixed> */
    private function snapshot(int $institutionId, string $requestKey, PaymentAuthorization $authorization,
        PaymentIntent $intent, PaymentReservation $reservation): array
    {
        return [
            'schema_version' => 1,
            'purpose' => 'authorized_vendor_payment_submission',
            'institution_id' => $institutionId,
            'request_key' => $requestKey,
            'provider_idempotency_key' => 'eduflow:'.$requestKey,
            'payment_authorization_id' => $authorization->id,
            'payment_reservation_id' => $reservation->id,
            'payment_intent_id' => $intent->id,
            'invoice_id' => $intent->invoice_id,
            'authorization_digest' => $authorization->snapshot_digest,
            'reservation_digest' => $reservation->snapshot_digest,
            'intent_digest' => $intent->snapshot_digest,
            'amount_base_units' => (string) $intent->amount_base_units,
            'max_fee_base_units' => (string) $intent->max_fee_base_units,
            'currency' => $intent->currency,
            'chain' => $intent->chain,
            'chain_id' => $intent->chain_id,
            'source_address' => $intent->source_address,
            'recipient_address' => $intent->recipient_address,
            'authorized_by' => $authorization->reviewed_by,
            'authorized_until' => $authorization->snapshot['valid_until'] ?? null,
            'is_fake' => $authorization->snapshot['is_fake'] ?? null,
        ];
    }
}
