<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * A payment rail that settles asynchronously.
 *
 * The synchronous `WalletGateway::transfer()` contract assumes the provider
 * hands back an on-chain reference. That is true of a local key and false of a
 * Circle agent wallet, where acceptance and inclusion are separate events hours
 * or blocks apart. Splitting the rail behind this interface is what lets the
 * state machine say "accepted" without pretending the money has moved.
 */
interface AsyncPaymentTransport
{
    /**
     * Submit once and return the rail's own handle.
     *
     * @param  array{from:string, to:string, amount_base_units:int, chain:string, idempotency_key:string, max_fee_base_units?:int}  $request
     *
     * @throws \RuntimeException when the rail refuses or cannot be reached. A
     *                           refusal is a fact; an unreachable rail is not,
     *                           and the caller must not read one as the other.
     */
    public function submit(array $request): TransportAcceptance;

    /**
     * Ask the rail what the transfer would cost, without broadcasting.
     *
     * Part of the interface because the fee question belongs to the rail. If
     * preflight went through a different contract than submission, the two
     * could disagree about which network the money would move on, and the
     * ceiling would be checked against the wrong chain.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null Raw rail response, or null if it will not say.
     */
    public function estimate(array $request): ?array;

    /** Observe a previously accepted transfer. Never re-sends. */
    public function resolve(string $handle, array $request): TransportResolution;
}
