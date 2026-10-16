<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionOutbox;
use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Services\PaymentSettlementReconciler;
use App\Services\StudentBillPaymentProcessor;
use App\Services\StudentTuitionPaymentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Yukazakiri\Lepton\Contracts\CircleGateway;

final class CircleWebhookController extends Controller
{
    /**
     * Handle incoming webhook notifications from Circle Wallets (Developer-Controlled or Agent Wallets).
     *
     * A webhook never settles a payment on its own word: it records a candidate
     * `txHash` (if well-formed) on the matching in-flight record and immediately
     * wakes the corresponding processor/reconciler so `ArcSettlementVerifier`
     * verifies the transaction receipt on Arc before any ledger is credited.
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = (string) $request->getContent();
        $signature = $request->header('X-Circle-Signature');
        $keyId = $request->header('X-Circle-Key-Id');

        $data = json_decode($rawPayload, true);
        if (! is_array($data)) {
            return response()->json(['error' => 'Malformed JSON payload.'], 400);
        }

        Log::info('Circle webhook received', [
            'type' => $data['notificationType'] ?? 'unknown',
            'keyId' => $keyId,
        ]);

        // Verify signature when the active CircleGateway supports it and headers are present
        $gateway = app()->bound(CircleGateway::class) ? app(CircleGateway::class) : null;
        if ($gateway !== null && method_exists($gateway, 'fetchEntityPublicKey') && method_exists($gateway, 'verifyWebhookSignature') && is_string($signature) && is_string($keyId)) {
            try {
                $pubKey = (string) $gateway->fetchEntityPublicKey();
                $verified = (bool) $gateway->verifyWebhookSignature($rawPayload, $signature, $pubKey);
                if (! $verified) {
                    Log::warning('Circle webhook signature verification failed.');

                    return response()->json(['error' => 'Invalid webhook signature.'], 401);
                }
            } catch (Throwable $e) {
                Log::notice('Could not verify Circle webhook signature: '.$e->getMessage());
            }
        }

        $notificationType = $data['notificationType'] ?? null;
        $reconciled = [
            'outbox' => null,
            'tuition_attempt' => null,
            'bill_payment' => null,
        ];

        if (is_string($notificationType) && str_starts_with($notificationType, 'transactions') || isset($data['data']['transaction']) || isset($data['notification']['id'])) {
            $tx = $data['data']['transaction'] ?? ($data['notification'] ?? ($data['data'] ?? []));
            $txId = strtolower(trim((string) ($tx['id'] ?? '')));
            $idempotencyKey = trim((string) ($tx['idempotencyKey'] ?? ''));
            $state = strtoupper((string) ($tx['state'] ?? ''));
            $rawHash = is_string($tx['txHash'] ?? null) ? trim($tx['txHash']) : (is_string($tx['transactionHash'] ?? null) ? trim($tx['transactionHash']) : null);
            $validHash = is_string($rawHash) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $rawHash) === 1
                ? strtolower($rawHash)
                : null;

            if ($txId !== '' || $idempotencyKey !== '') {
                $reconciled['outbox'] = $this->reconcileOutbox($txId, $idempotencyKey, $state, $validHash);
                $reconciled['tuition_attempt'] = $this->reconcileTuitionAttempt($txId, $idempotencyKey, $validHash);
                $reconciled['bill_payment'] = $this->reconcileBillPayment($txId, $idempotencyKey, $validHash);
            }
        }

        return response()->json([
            'received' => true,
            'reconciled' => $reconciled,
        ]);
    }

    private function reconcileOutbox(string $txId, string $idempotencyKey, string $state, ?string $validHash): ?int
    {
        $outbox = PaymentSubmissionOutbox::query()
            ->where(function ($query) use ($txId, $idempotencyKey): void {
                if ($txId !== '') {
                    $query->where('provider_handle', $txId)
                        ->orWhere('provider_idempotency_key', $txId);
                }
                if ($idempotencyKey !== '') {
                    $query->orWhere('provider_idempotency_key', $idempotencyKey);
                }
            })
            ->first();

        if (! $outbox instanceof PaymentSubmissionOutbox) {
            return null;
        }

        Log::info("Circle webhook matched submission outbox #{$outbox->id} for state {$state}", [
            'txId' => $txId,
            'txHash' => $validHash,
            'state' => $state,
        ]);

        try {
            if ($validHash !== null && in_array($outbox->state, ['accepted', 'unknown'], true) && ($outbox->result['provider_reference'] ?? null) === null) {
                $handle = $outbox->provider_handle ?? ($txId !== '' ? $txId : null);
                $outbox->state = 'submitted';
                $outbox->stage = 'hash_resolved';
                $outbox->result = array_filter([
                    'provider_handle' => $handle,
                    'provider_reference' => $validHash,
                    'verdict' => 'submitted',
                    'settled' => false,
                    'payments_submitted' => 1,
                    'can_execute' => false,
                ], static fn (mixed $v): bool => $v !== null);
                $outbox->result_digest = PaymentIntent::digest($outbox->result);
                $outbox->save();
            }

            if (in_array($outbox->state, ['accepted', 'submitted', 'unknown'], true)) {
                app(PaymentSettlementReconciler::class)->reconcile($outbox->fresh());
            }
        } catch (Throwable $e) {
            report($e);
        }

        return (int) $outbox->id;
    }

    private function reconcileTuitionAttempt(string $txId, string $idempotencyKey, ?string $validHash): ?int
    {
        $attempt = StudentTuitionPaymentAttempt::query()
            ->where(function ($query) use ($txId, $idempotencyKey): void {
                if ($txId !== '') {
                    $query->where('provider_handle', $txId);
                }
                if ($idempotencyKey !== '') {
                    $query->orWhere('request_key', $idempotencyKey);
                }
            })
            ->first();

        if (! $attempt instanceof StudentTuitionPaymentAttempt) {
            return null;
        }

        try {
            if ($validHash !== null && $attempt->provider_reference === null && in_array($attempt->state, ['submitting', 'accepted', 'awaiting_verification', 'unknown'], true)) {
                $attempt->update([
                    'provider_handle' => $attempt->provider_handle ?? ($txId !== '' ? $txId : null),
                    'provider_reference' => $validHash,
                    'state' => 'awaiting_verification',
                    'leased_until' => null,
                    'next_attempt_at' => now(),
                ]);
            }

            if (in_array($attempt->fresh()->state, ['submitting', 'accepted', 'awaiting_verification', 'unknown', 'verified'], true)) {
                app(StudentTuitionPaymentProcessor::class)->process((int) $attempt->id);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return (int) $attempt->id;
    }

    private function reconcileBillPayment(string $txId, string $idempotencyKey, ?string $validHash): ?int
    {
        $billPayment = StudentBillPayment::query()
            ->where(function ($query) use ($txId, $idempotencyKey): void {
                if ($txId !== '') {
                    $query->where('provider_handle', $txId);
                }
                if ($idempotencyKey !== '') {
                    $query->orWhere('request_key', $idempotencyKey);
                }
            })
            ->first();

        if (! $billPayment instanceof StudentBillPayment) {
            return null;
        }

        try {
            if ($validHash !== null && $billPayment->provider_reference === null && in_array($billPayment->submission_state, [
                StudentBillPayment::SUBMISSION_SUBMITTING,
                StudentBillPayment::SUBMISSION_ACCEPTED,
                StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION,
            ], true)) {
                $billPayment->update([
                    'provider_handle' => $billPayment->provider_handle ?? ($txId !== '' ? $txId : null),
                    'provider_reference' => $validHash,
                    'submission_state' => StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION,
                    'leased_until' => null,
                    'next_attempt_at' => now(),
                ]);
            }

            if (in_array($billPayment->fresh()->submission_state, [
                StudentBillPayment::SUBMISSION_SUBMITTING,
                StudentBillPayment::SUBMISSION_ACCEPTED,
                StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION,
                StudentBillPayment::SUBMISSION_VERIFIED,
            ], true)) {
                app(StudentBillPaymentProcessor::class)->process((int) $billPayment->id);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return (int) $billPayment->id;
    }
}
