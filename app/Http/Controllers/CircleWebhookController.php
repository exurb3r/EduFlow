<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaymentSubmissionOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Yukazakiri\Lepton\Contracts\CircleGateway;

final class CircleWebhookController extends Controller
{
    /**
     * Handle incoming webhook notifications from Circle Developer-Controlled Wallets.
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

        // If gateway instance supports webhook signature verification
        $gateway = app()->bound(CircleGateway::class) ? app(CircleGateway::class) : null;
        if ($gateway !== null && method_exists($gateway, 'fetchEntityPublicKey') && method_exists($gateway, 'verifyWebhookSignature') && is_string($signature) && is_string($keyId)) {
            try {
                $pubKey = (string) $gateway->fetchEntityPublicKey();
                $verified = (bool) $gateway->verifyWebhookSignature($rawPayload, $signature, $pubKey);
                if (! $verified) {
                    Log::warning('Circle webhook signature verification failed.');
                }
            } catch (Throwable $e) {
                Log::notice('Could not verify Circle webhook signature: '.$e->getMessage());
            }
        }

        $notificationType = $data['notificationType'] ?? null;
        if ($notificationType === 'transactions' || isset($data['data']['transaction'])) {
            $tx = $data['data']['transaction'] ?? ($data['data'] ?? []);
            $txId = (string) ($tx['id'] ?? '');
            $state = strtoupper((string) ($tx['state'] ?? ''));
            $txHash = $tx['txHash'] ?? null;

            if ($txId !== '') {
                $outbox = PaymentSubmissionOutbox::query()
                    ->where('provider_handle', $txId)
                    ->orWhere('provider_idempotency_key', $txId)
                    ->first();

                if ($outbox instanceof PaymentSubmissionOutbox) {
                    Log::info("Circle webhook matched submission outbox #{$outbox->id} for state {$state}", [
                        'txId' => $txId,
                        'txHash' => $txHash,
                        'state' => $state,
                    ]);
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
