<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\PaymentSubmissionOutbox;
use App\Services\Payments\AsyncPaymentTransport;
use Illuminate\Validation\ValidationException;
use Throwable;
use Yukazakiri\Lepton\Contracts\WalletGateway;

/**
 * The only component permitted to ask a rail to move money.
 *
 * It exists as a separate service so that everything above it — the planner,
 * the reservations, the authorizations, the outbox — can be written and
 * reviewed without any of them holding a path to signing. It receives an
 * already-authorized durable entry and does exactly four things, in order:
 * re-check authority, preflight the fee, submit once with a stable provider
 * identity, and report the outcome honestly.
 *
 * What it deliberately does not do: decide which bill to pay, choose an
 * amount, resolve a destination, retry an uncertain submission, or mark
 * anything settled. Settlement is the verifier's verdict, never this class's
 * opinion.
 */
final readonly class IsolatedPaymentExecutor
{
    public function __construct(private WalletGateway $wallets, private InstallationInstitution $institutions) {}

    /**
     * Preflight without broadcasting.
     *
     * Estimates the fee so an unaffordable or oversized transfer is refused
     * before anything is sent, and never reaches the rail as a write.
     *
     * @return array<string, mixed>
     */
    public function estimate(PaymentSubmissionOutbox $entry): array
    {
        $this->assertRail($entry);

        $request = [
            'from' => $entry->snapshot['source_address'],
            'to' => $entry->snapshot['recipient_address'],
            'amount_base_units' => (int) $entry->snapshot['amount_base_units'],
            'chain' => $entry->snapshot['chain'],
            'idempotency_key' => $entry->provider_idempotency_key,
        ];

        // Preflight must go to the same rail as the submission, or the ceiling
        // would be checked against a network the money does not move on.
        $transport = $this->transportFor();

        if ($transport instanceof AsyncPaymentTransport) {
            return $transport->estimate($request) ?? [];
        }

        return $this->wallets->transfer(
            $entry->snapshot['source_address'],
            $entry->snapshot['recipient_address'],
            (int) $entry->snapshot['amount_base_units'],
            [
                'chain' => $entry->snapshot['chain'],
                'idempotencyKey' => $entry->provider_idempotency_key,
                'estimate' => true,
            ],
        )->raw;
    }

    /**
     * Submit exactly once, under an identity the provider will recognise on a
     * retry.
     *
     * A timeout or malformed response is reported as unknown, never as a
     * failure: the payment may still have been accepted, and the honest
     * response is to reconcile the existing attempt rather than send again.
     *
     * @return array{outcome:string, provider_reference:?string, provider_handle:?string, detail:array<string, mixed>}
     */
    public function submit(PaymentSubmissionOutbox $entry): array
    {
        $this->assertRail($entry);

        if (config('eduflow.submission.stop_switch', false) === true) {
            throw ValidationException::withMessages(['payment' => 'Submission stop switch is engaged; no transfer was attempted.']);
        }

        $request = [
            'from' => $entry->snapshot['source_address'],
            'to' => $entry->snapshot['recipient_address'],
            'amount_base_units' => (int) $entry->snapshot['amount_base_units'],
            'chain' => $entry->snapshot['chain'],
            'idempotency_key' => $entry->provider_idempotency_key,
        ];

        // An asynchronous rail is not a slower synchronous rail. Circle agent
        // wallets never hand back a hash from `transfer()` — they hand back a
        // transaction id — so submitting through the synchronous contract would
        // guarantee a thrown "no tx hash" and an `unknown` entry on every
        // single payment. Such rails are driven through their own transport,
        // which submits exactly once and reports *acceptance*, which is a fact
        // the system can hold honestly.
        $transport = $this->transportFor();

        if ($transport instanceof AsyncPaymentTransport) {
            try {
                $acceptance = $transport->submit($request);
            } catch (Throwable $exception) {
                report($exception);

                // The rail refused or could not be reached. Neither is proof
                // that no transfer happened, so this stays unresolved.
                return ['outcome' => 'unknown', 'provider_reference' => null, 'provider_handle' => null,
                    'detail' => ['error' => $exception->getMessage(), 'provider_exception' => true]];
            }

            return ['outcome' => 'accepted', 'provider_reference' => null,
                'provider_handle' => $acceptance->handle,
                'detail' => [
                    'rail' => 'circle_agent_wallet',
                    // Recorded on the append-only attempt as well as the entry,
                    // so the handle survives even if the entry is later
                    // rewritten by reconciliation.
                    'provider_handle' => $acceptance->handle,
                    // No hash exists yet. Saying so here keeps the distinction
                    // between "the rail took it" and "money moved" impossible
                    // to lose downstream.
                    'provider_reference' => null,
                    'settlement_proven' => false,
                ]];
        }

        try {
            $result = $this->wallets->transfer(
                $entry->snapshot['source_address'],
                $entry->snapshot['recipient_address'],
                (int) $entry->snapshot['amount_base_units'],
                [
                    'chain' => $entry->snapshot['chain'],
                    'idempotencyKey' => $entry->provider_idempotency_key,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            // Uncertain, not failed. Capacity stays held until reconciled.
            return ['outcome' => 'unknown', 'provider_reference' => null, 'provider_handle' => null,
                'detail' => ['error' => $exception->getMessage(), 'provider_exception' => true]];
        }

        if ($result->isFake || config('lepton.default') === 'fake') {
            return ['outcome' => 'blocked', 'provider_reference' => null, 'provider_handle' => null,
                'detail' => ['reason' => 'Fake driver. Simulation is never submitted as a network transfer.', 'is_fake' => true]];
        }

        return [
            'outcome' => 'submitted',
            'provider_reference' => $result->txHash,
            'provider_handle' => null,
            'detail' => [
                'from' => $result->fromAddress,
                'to' => $result->toAddress,
                'amount_base_units' => (string) $result->amountBaseUnits,
                'chain' => $result->chain,
                'explorer_url' => $result->explorerUrl,
            ],
        ];
    }

    /**
     * The asynchronous transport for this rail, or null for a synchronous one.
     *
     * `auto` follows the configured driver, because a Circle agent wallet is
     * asynchronous in fact and not merely by convention. It can be forced either
     * way, and the transport itself is resolved from the container so a test can
     * supply its own rather than reaching for a real binary.
     */
    private function transportFor(): ?AsyncPaymentTransport
    {
        $mode = (string) config('eduflow.submission.transport', 'auto');
        $wantsAsync = match ($mode) {
            'circle_agent_wallet' => true,
            'synchronous' => false,
            default => config('lepton.default') === 'circle',
        };
        if (! $wantsAsync) {
            return null;
        }

        return app(AsyncPaymentTransport::class);
    }

    /**
     * The rail must be the one this installation was explicitly configured
     * for. There is no fallback, and a mismatch is refused rather than
     * resolved in favour of whatever happens to be reachable.
     */
    private function assertRail(PaymentSubmissionOutbox $entry): void
    {
        if (! config('eduflow.submission.enabled', false)) {
            throw ValidationException::withMessages(['payment' => 'Payment submission runtime is disabled.']);
        }

        $institution = $this->institutions->current();

        if (! $institution instanceof Organization) {
            throw ValidationException::withMessages(['payment' => 'Institution context unavailable; no transfer attempted.']);
        }

        $chain = $entry->snapshot['chain'] ?? null;
        $chainId = $entry->snapshot['chain_id'] ?? null;

        if ($chain !== config('lepton.arc.chain') || $chainId !== config('lepton.arc.chain_id')
            || $chain !== 'ARC-TESTNET' || $chainId !== 5042002) {
            throw ValidationException::withMessages(['payment' => 'Only explicitly configured Arc testnet execution is permitted; mainnet remains blocked.']);
        }

        foreach (['source_address', 'recipient_address', 'amount_base_units'] as $required) {
            if (($entry->snapshot[$required] ?? null) === null || $entry->snapshot[$required] === '') {
                throw ValidationException::withMessages(['payment' => 'Submission lacks bound treasury, recipient or amount evidence.']);
            }
        }

        if (! is_string($entry->snapshot['recipient_address'])
            || preg_match('/^0x[0-9a-f]{40}$/D', strtolower($entry->snapshot['recipient_address'])) !== 1) {
            throw ValidationException::withMessages(['payment' => 'Bound recipient is not a usable address; a rejected address is recoverable, one the recipient does not control is not.']);
        }
    }
}
