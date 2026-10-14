<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StudentWalletFundingStatus;
use App\Models\Student;
use App\Models\StudentWallet;
use App\Models\StudentWalletFunding;
use App\Notifications\FundsReceivedNotification;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Yukazakiri\Lepton\Support\LeptonCliException;

/**
 * Mints and funds a student's own wallet through the Circle CLI.
 *
 * Two constraints shape every method here:
 *
 * 1. Circle caps agent wallets at five per blockchain and offers no delete, so
 *    a generation is a permanent spend of a slot. The cap is re-read from the
 *    CLI immediately before creating, and creating past it throws rather than
 *    silently minting a wallet nobody can track.
 * 2. The faucet only funds wallets the CLI owns, so the address has to be one
 *    the CLI reports back. It is read from the wallet list by diffing before
 *    and after — never synthesised from the student record, because a
 *    fabricated destination is either rejected or, worse, accepted and
 *    irretrievable.
 */
class StudentWalletGenerationService
{
    private const DEFAULT_DRIP_BASE_UNITS = 20_000000;

    public function __construct(
        private readonly CircleAgentWalletCli $cli,
        private readonly StudentWalletService $wallets,
    ) {}

    public function chain(): string
    {
        return (string) config('lepton.arc.chain', 'ARC-TESTNET');
    }

    public function remainingSlots(): int
    {
        return $this->cli->remainingAgentWalletSlots($this->chain());
    }

    /**
     * Mint an agent wallet and point the student's payout address at it.
     *
     * @throws RuntimeException when no slot is left, the wallet already exists,
     *                          or the CLI does not report a new address.
     */
    public function generate(Student $student): StudentWallet
    {
        if ($student->wallet()->exists()) {
            throw new RuntimeException('This student already has a generated wallet. Generating another would spend one of the five Circle agent-wallet slots.');
        }

        $chain = $this->chain();

        $before = $this->cli->agentWalletAddresses($chain);

        if (count($before) >= CircleAgentWalletCli::AGENT_WALLET_LIMIT) {
            throw new RuntimeException(sprintf(
                'Circle allows %d agent wallets per blockchain and %d already exist on %s. Free a slot before generating another.',
                CircleAgentWalletCli::AGENT_WALLET_LIMIT,
                count($before),
                $chain,
            ));
        }

        try {
            $this->cli->createAgentWallet($this->idempotencyKeyFor($student));
        } catch (LeptonCliException $e) {
            throw new RuntimeException($this->readableCliFailure($e, 'generate a wallet'), $e->getCode(), previous: $e);
        }

        $after = $this->cli->agentWalletAddresses($chain);
        $created = array_values(array_diff($after, $before));

        if ($created === []) {
            throw new RuntimeException('The Circle CLI reported success but no new wallet address on '.$chain.'. Nothing was recorded — verify with `circle wallet list --type agent` before retrying.');
        }

        $address = $this->assertUsableAddress($created[0]);

        return DB::transaction(function () use ($student, $address, $chain): StudentWallet {
            $wallet = StudentWallet::create([
                'student_id' => $student->getKey(),
                'address' => $address,
                'provider' => 'circle',
                'network' => 'arc',
                'chain' => $chain,
                'status' => 'active',
                'generated_at' => now(),
            ]);

            // The single writer, so the payout address and the cached balance
            // stay consistent with the wallet just minted.
            $this->wallets->setAddress($student, $address);

            return $wallet;
        });
    }

    /**
     * Drip the faucet into a student's generated wallet.
     *
     * @throws RuntimeException when the student has no generated wallet, or the
     *                          wallet is not one the CLI can fund.
     */
    public function fund(Student $student): StudentWalletFunding
    {
        $wallet = $student->wallet;

        if (! $wallet instanceof StudentWallet) {
            throw new RuntimeException('No generated wallet on file. Generate one for this student first.');
        }

        // The faucet only funds wallets the CLI owns, so the address it is asked
        // to fund has to be the one it minted, not a later manual edit.
        $payoutAddress = $this->wallets->addressFor($student);

        if ($payoutAddress === null || strcasecmp($payoutAddress, $wallet->address) !== 0) {
            throw new RuntimeException('The payout address on file is not the generated wallet, so the faucet would not accept it. Restore the generated address before funding.');
        }

        $chain = $wallet->chain;

        try {
            $response = $this->cli->fundWallet($wallet->address, $chain);
        } catch (LeptonCliException $e) {
            return $this->record($wallet, $chain, StudentWalletFundingStatus::FAILED, null, $this->readableCliFailure($e, 'fund the wallet'));
        }

        $txHash = is_array($response) ? ($response['data']['txHash'] ?? $response['data']['transactionHash'] ?? null) : null;
        $amount = $this->dripAmount($response);

        $funding = $this->record(
            $wallet,
            $chain,
            StudentWalletFundingStatus::FUNDED,
            is_string($txHash) ? $txHash : null,
            null,
            $amount,
        );

        $amountUsdc = number_format($amount / 1_000_000, 2);
        $student->user->notify(new FundsReceivedNotification(
            amount: $amountUsdc,
            description: "Top-up of {$amountUsdc} USDC from Arc testnet faucet.",
            txHash: is_string($txHash) ? $txHash : null,
            source: 'faucet',
        ));

        return $funding;
    }

    /**
     * The faucet mints a fixed amount and reports it inconsistently, so the
     * known drip is used unless the CLI states a different one.
     *
     * @param  array<string, mixed>|string  $response
     */
    private function dripAmount(array|string $response): int
    {
        if (! is_array($response)) {
            return self::DEFAULT_DRIP_BASE_UNITS;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;

        foreach (['amount', 'amountBaseUnits', 'amount_base_units', 'minted'] as $key) {
            $value = $data[$key] ?? null;

            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return self::DEFAULT_DRIP_BASE_UNITS;
    }

    private function record(
        StudentWallet $wallet,
        string $chain,
        StudentWalletFundingStatus $status,
        ?string $txHash,
        ?string $detail,
        ?int $amountBaseUnits = null,
    ): StudentWalletFunding {
        return StudentWalletFunding::create([
            'student_wallet_id' => $wallet->getKey(),
            'chain' => $chain,
            'amount_base_units' => $amountBaseUnits ?? self::DEFAULT_DRIP_BASE_UNITS,
            'status' => $status,
            'tx_hash' => $txHash,
            'detail' => $detail,
            'funded_at' => now(),
        ]);
    }

    /**
     * Circle's API accepts only a UUID as an idempotency key.
     *
     * It is a v5 UUID derived from the student rather than a random one, so a
     * retry after a failure or a timeout asks Circle about the same attempt
     * instead of minting a second wallet from an already-spent slot. A plain
     * string is rejected with a 400 "API parameter invalid" that names nothing.
     */
    private function idempotencyKeyFor(Student $student): string
    {
        $hash = hash('sha1', 'student-wallet:'.$student->getKey());

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex(hexdec($hash[16]) & 0x3 | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    /**
     * A destination must be a well-formed Arc address, or nothing is recorded.
     */
    private function assertUsableAddress(string $address): string
    {
        if (preg_match(Student::PAYOUT_ADDRESS_PATTERN, $address) !== 1) {
            throw new RuntimeException('The Circle CLI returned an address that is not a valid Arc destination: '.$address.'. Nothing was recorded.');
        }

        return $address;
    }

    private function readableCliFailure(LeptonCliException $e, string $action): string
    {
        $stderr = trim($e->stderr);

        return match (true) {
            str_contains($stderr, 'Not logged in'), str_contains($stderr, 'session expired') => 'The Circle CLI is not logged in for this chain. Run `php artisan lepton:login`, then try again.',
            str_contains($stderr, 'rate limit'), str_contains($stderr, '429') => 'The Arc faucet rate-limits after a few drips. Wait before topping this wallet up again.',
            str_contains($stderr, 'must be one of your wallets') => 'Circle will only fund wallets it owns. This address is not one of them.',
            $stderr !== '' => "Could not {$action}: {$stderr}",
            default => "Could not {$action}.",
        };
    }
}
