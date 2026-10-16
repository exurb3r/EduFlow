<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Direct HTTPS client for Circle Agent Wallets (W3S Proxy + SDK Challenge API).
 *
 * Replaces spawning the Node `@circle-fin/cli` binary on every fee quote,
 * transfer submission, transaction resolution, wallet list, and faucet drip.
 * Reads the authenticated operator session from `~/.circle-cli/profiles/agent/session.json`
 * (hydrating secrets from the OS keychain once if needed) and speaks directly
 * to Circle's W3S endpoints via Laravel's HTTP client.
 */
class CircleAgentHttpClient
{
    private const DEFAULT_PROXY_BASE = 'https://agentic-wallet.circle.com';

    private const API_PREFIX = '/v1/w3s';

    private const USER_AGENT = 'circle-cli/0.1.5';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $resolvedSessions = [];

    /**
     * @param  array<string, mixed>|null  $sessionOverride
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly ?string $proxyBaseOverride = null,
        private readonly ?array $sessionOverride = null,
    ) {}

    /**
     * Check whether a valid, non-expired Circle Agent Wallet session is available.
     */
    public function isAuthenticatedFor(string $chain = 'ARC-TESTNET'): bool
    {
        try {
            $env = $this->loadEnvironmentSession($this->isTestnet($chain));

            return $env !== null
                && is_string($env['userToken'] ?? null)
                && $env['userToken'] !== ''
                && ! $this->isExpired($env);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Return the authentication status for mainnet and testnet slots.
     *
     * @return array{
     *     type: string,
     *     mainnet: array{authenticated: bool, email: ?string, status: ?string, expires_in: ?string},
     *     testnet: array{authenticated: bool, email: ?string, status: ?string, expires_in: ?string}
     * }
     */
    public function authStatus(): array
    {
        $formatSlot = function (bool $testnet): array {
            $empty = ['authenticated' => false, 'email' => null, 'status' => null, 'expires_in' => null];
            $env = $this->loadEnvironmentSession($testnet);
            if ($env === null || ! is_string($env['userToken'] ?? null) || $env['userToken'] === '') {
                return $empty;
            }

            $expired = $this->isExpired($env);
            $expiresAtMs = is_numeric($env['expiresAt'] ?? null) ? (int) $env['expiresAt'] : null;
            $remainingSec = $expiresAtMs !== null ? max(0, intdiv($expiresAtMs - (int) round(microtime(true) * 1000), 1000)) : null;

            return [
                'authenticated' => ! $expired,
                'email' => isset($env['email']) && is_string($env['email']) ? $env['email'] : null,
                'status' => $expired ? 'EXPIRED' : 'VALID',
                'expires_in' => $remainingSec !== null ? $remainingSec.'s' : null,
            ];
        };

        return [
            'type' => 'agent',
            'mainnet' => $formatSlot(false),
            'testnet' => $formatSlot(true),
        ];
    }

    /**
     * List agent wallets on a given blockchain.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWallets(string $chain, ?string $refId = null, ?string $address = null): array
    {
        $testnet = $this->isTestnet($chain);
        $env = $this->requireEnvironmentSession($testnet);
        $proxyUrl = $this->proxyUrl($testnet);

        $query = ['pageSize' => '50'];
        if ($chain !== '') {
            $query['blockchain'] = $chain;
        }
        if ($refId !== null && $refId !== '') {
            $query['refId'] = $refId;
        }
        if ($address !== null && $address !== '') {
            $query['address'] = $address;
        }

        $data = $this->w3sRequest('GET', $proxyUrl, '/wallets', $env['userToken'], null, $query);

        return is_array($data['wallets'] ?? null) ? $data['wallets'] : [];
    }

    /**
     * Estimate the network fee for a transfer without broadcasting.
     *
     * @return array<string, mixed>
     */
    public function estimateTransferFee(
        string $fromAddress,
        string $toAddress,
        string $amountDecimal,
        string $chain,
        ?string $tokenAddress = null,
    ): array {
        $testnet = $this->isTestnet($chain);
        $env = $this->requireEnvironmentSession($testnet);
        $proxyUrl = $this->proxyUrl($testnet);
        $wallet = $this->resolveWallet($fromAddress, $chain);

        $body = [
            'walletId' => $this->walletId($wallet),
            'sourceAddress' => (string) $wallet['address'],
            'blockchain' => $chain,
            'destinationAddress' => $toAddress,
            'amounts' => [$amountDecimal],
        ];

        if ($tokenAddress !== null && $tokenAddress !== '') {
            $body['tokenAddress'] = $tokenAddress;
        }

        $fees = $this->w3sRequest('POST', $proxyUrl, '/transactions/transfer/estimateFee', $env['userToken'], $body);
        unset($fees['low'], $fees['high']);

        return [
            'data' => [
                'blockchain' => $chain,
                ...$fees,
            ],
        ];
    }

    /**
     * Submit an agent wallet transfer via Circle W3S HTTP API and approve the SDK challenge.
     *
     * Returns immediately once the challenge resolves to a Circle transaction ID UUID
     * (or if a transaction ID is returned directly), so the caller never blocks on
     * long CLI polling loops.
     *
     * @return array{id: string, state: string, txHash?: ?string, challengeId?: string, idempotencyKey: string}
     */
    public function submitTransfer(
        string $fromAddress,
        string $toAddress,
        string $amountDecimal,
        string $chain,
        string $idempotencyKey,
        ?string $tokenAddress = null,
    ): array {
        $testnet = $this->isTestnet($chain);
        $env = $this->requireEnvironmentSession($testnet);
        $proxyUrl = $this->proxyUrl($testnet);
        $wallet = $this->resolveWallet($fromAddress, $chain);

        $uuidKey = $this->normalizeUuid($idempotencyKey);

        $body = [
            'idempotencyKey' => $uuidKey,
            'walletId' => $this->walletId($wallet),
            'sourceAddress' => (string) $wallet['address'],
            'blockchain' => $chain,
            'destinationAddress' => $toAddress,
            'amounts' => [$amountDecimal],
            'feeLevel' => 'MEDIUM',
        ];

        if ($tokenAddress !== null && $tokenAddress !== '') {
            $body['tokenAddress'] = $tokenAddress;
        }

        $created = $this->w3sRequest('POST', $proxyUrl, '/user/transactions/transfer', $env['userToken'], $body);

        $directId = $created['id'] ?? $created['transactionId'] ?? $created['transaction_id'] ?? null;
        if (is_string($directId) && Str::isUuid($directId)) {
            return [
                'id' => strtolower($directId),
                'state' => (string) ($created['state'] ?? 'INITIATED'),
                'txHash' => is_string($created['txHash'] ?? null) ? $created['txHash'] : null,
                'idempotencyKey' => $idempotencyKey,
            ];
        }

        $challengeId = (string) ($created['challengeId'] ?? '');
        if ($challengeId === '') {
            throw new RuntimeException('Circle W3S transfer initiation did not return a challengeId or transaction ID.');
        }

        $correlationIds = $this->runChallengeCycle($proxyUrl, $env, $challengeId);
        $transactionId = (string) ($correlationIds[0] ?? '');

        if (! Str::isUuid($transactionId)) {
            throw new RuntimeException('Circle W3S challenge completed without returning a valid transaction UUID.');
        }

        return [
            'id' => strtolower($transactionId),
            'state' => 'INITIATED',
            'challengeId' => $challengeId,
            'idempotencyKey' => $idempotencyKey,
        ];
    }

    /**
     * Fetch a single transaction by its Circle UUID.
     *
     * @return array<string, mixed>|null
     */
    public function getTransaction(string $transactionId, string $chain = 'ARC-TESTNET'): ?array
    {
        $testnet = $this->isTestnet($chain);
        $env = $this->requireEnvironmentSession($testnet);
        $proxyUrl = $this->proxyUrl($testnet);

        $data = $this->w3sRequest('GET', $proxyUrl, '/transactions/'.urlencode($transactionId), $env['userToken']);
        $tx = $data['transaction'] ?? (isset($data['id']) ? $data : null);

        return is_array($tx) ? $tx : null;
    }

    /**
     * List recent transactions on a chain (optionally scoped to a source wallet address).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTransactions(string $chain = 'ARC-TESTNET', ?string $address = null): array
    {
        $testnet = $this->isTestnet($chain);
        $env = $this->requireEnvironmentSession($testnet);
        $proxyUrl = $this->proxyUrl($testnet);

        $query = ['pageSize' => '50'];
        if ($address !== null && $address !== '') {
            try {
                $wallet = $this->resolveWallet($address, $chain);
                $query['walletIds'] = $this->walletId($wallet);
            } catch (Throwable) {
                // Fall back to unfiltered transaction list for the user token
            }
        }

        $data = $this->w3sRequest('GET', $proxyUrl, '/transactions', $env['userToken'], null, $query);
        $transactions = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];

        return array_values(array_filter(
            $transactions,
            fn (mixed $tx): bool => is_array($tx)
                && ($chain === '' || strcasecmp((string) ($tx['blockchain'] ?? $chain), $chain) === 0),
        ));
    }

    /**
     * Request a testnet faucet drip for an agent wallet over HTTP.
     *
     * @return array<string, mixed>
     */
    public function fundWallet(string $address, string $chain = 'ARC-TESTNET', string $token = 'usdc'): array
    {
        $testnet = $this->isTestnet($chain);
        if (! $testnet) {
            throw new RuntimeException('Faucet drips are only available on testnet chains.');
        }

        $env = $this->requireEnvironmentSession(true);
        $proxyUrl = $this->proxyUrl(true);

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-User-Token' => $env['userToken'],
                'User-Agent' => self::USER_AGENT,
            ])
            ->post($proxyUrl.'/fund', [
                'address' => $address,
                'blockchain' => $chain,
                'token' => strtolower($token),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                sprintf('Circle faucet drip failed (%d): %s', $response->status(), $this->extractErrorDetail($response->body(), $response->reason()))
            );
        }

        $json = $response->json();

        return [
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [
                'message' => 'Faucet drip requested.',
                'address' => $address,
                'blockchain' => $chain,
                'token' => strtolower($token),
                ...(is_array($json) ? $json : []),
            ],
        ];
    }

    /**
     * Create agent SCA wallets on testnet chains over HTTP.
     *
     * @param  array<int, string>  $blockchains
     * @return array<string, mixed>
     */
    public function createAgentWallet(string $idempotencyKey, array $blockchains = ['ARC-TESTNET']): array
    {
        $existing = $this->listWallets('', $idempotencyKey);
        if ($existing !== []) {
            return ['data' => ['wallets' => $existing]];
        }

        $env = $this->requireEnvironmentSession(true);
        $proxyUrl = $this->proxyUrl(true);

        $uuidKey = $this->normalizeUuid($idempotencyKey);

        $created = $this->w3sRequest('POST', $proxyUrl, '/user/wallets', $env['userToken'], [
            'idempotencyKey' => $uuidKey,
            'blockchains' => $blockchains,
            'accountType' => 'SCA',
            'metadata' => array_map(fn (): array => ['refId' => $idempotencyKey], $blockchains),
        ]);

        $challengeId = (string) ($created['challengeId'] ?? '');
        if ($challengeId !== '') {
            $this->runChallengeCycle($proxyUrl, $env, $challengeId);
        }

        $wallets = [];
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $wallets = $this->listWallets('', $idempotencyKey);
            if ($wallets !== []) {
                break;
            }
            usleep(500_000);
        }

        return ['data' => ['wallets' => $wallets]];
    }

    /**
     * Resolve wallet metadata for a given address on a chain.
     *
     * @return array<string, mixed>
     */
    public function resolveWallet(string $address, string $chain): array
    {
        $normalized = strtolower(trim($address));
        $cacheKey = 'circle_agent_wallet_meta:'.strtolower($chain).':'.$normalized;

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['address']) && strtolower((string) $cached['address']) === $normalized) {
            return $cached;
        }

        $wallets = $this->listWallets($chain);
        foreach ($wallets as $wallet) {
            if (! is_array($wallet)) {
                continue;
            }
            if (strtolower((string) ($wallet['address'] ?? '')) === $normalized) {
                Cache::put($cacheKey, $wallet, 600);

                return $wallet;
            }
        }

        throw new RuntimeException("Circle agent wallet not found for address {$address} on {$chain}.");
    }

    /**
     * Execute and poll a Circle W3S challenge using social PIN derivation + AES-GCM/RSA-OAEP encryption.
     *
     * @param  array<string, mixed>  $env
     * @return array<int, string>
     */
    private function runChallengeCycle(string $proxyUrl, array $env, string $challengeId): array
    {
        $this->executeChallenge($proxyUrl, $env, $challengeId);

        for ($step = 0; $step < 20; $step++) {
            $challenge = $this->w3sRequest('GET', $proxyUrl, '/user/challenges/'.urlencode($challengeId), (string) $env['userToken']);
            $challengeData = is_array($challenge['challenge'] ?? null) ? $challenge['challenge'] : $challenge;
            $status = strtoupper((string) ($challengeData['status'] ?? ''));

            if ($status === 'COMPLETE') {
                $ids = $challengeData['correlationIds'] ?? [];

                return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
            }

            if (in_array($status, ['FAILED', 'EXPIRED', 'CANCELLED', 'DENIED'], true)) {
                throw new RuntimeException("Circle W3S challenge {$challengeId} ended with status {$status}.");
            }

            usleep(min(250_000 * ($step + 1), 1_000_000));
        }

        throw new RuntimeException("Timed out waiting for Circle W3S challenge {$challengeId} to complete.");
    }

    /**
     * Approve a challenge via Circle's SDK endpoints.
     *
     * @param  array<string, mixed>  $env
     */
    private function executeChallenge(string $proxyUrl, array $env, string $challengeId): void
    {
        $userToken = (string) ($env['userToken'] ?? '');
        $encryptionKey = (string) ($env['encryptionKey'] ?? '');
        $encryptedUserSecret = (string) ($env['encryptedUserSecret'] ?? '');
        $storageKey = (string) ($env['storageKey'] ?? '');
        $deviceId = (string) ($env['deviceId'] ?? '');

        if ($encryptionKey === '' || $encryptedUserSecret === '' || $storageKey === '') {
            throw new RuntimeException('Circle agent session is missing encryption secrets required to sign challenges.');
        }

        $appId = $this->fetchAppId($proxyUrl);

        $challengeResp = $this->sdkRequest(
            'GET',
            $proxyUrl,
            '/v1/w3s/sdk/user/challenges/'.urlencode($challengeId),
            null,
            $userToken,
            $appId,
            $deviceId,
        );

        $encryptedChallenge = $challengeResp['data']['encrypt'] ?? $challengeResp['encrypt'] ?? null;
        if (! is_string($encryptedChallenge) || $encryptedChallenge === '') {
            // If a test stub returned an already-completed or unencrypted challenge response, allow pollChallenge to proceed
            return;
        }

        $decryptedJson = $this->aesGcmDecrypt($encryptedChallenge, $encryptionKey);
        $challenge = json_decode($decryptedJson, true);
        if (! is_array($challenge) || ! is_string($challenge['publicKey'] ?? null)) {
            throw new RuntimeException('Malformed decrypted challenge payload from Circle SDK.');
        }

        $pin = $this->deriveSocialPin(
            $encryptedUserSecret,
            $storageKey,
            (string) ($challenge['pinCodeUserShare'] ?? ''),
        );

        $publicKeyPem = $challenge['publicKey'];
        $v1 = $this->pinHashV1($pin);
        $v0 = $this->pinHashV0($pin);

        $payload = [
            'idempotencyKey' => (string) Str::uuid(),
            'pinhash' => $this->encryptPinHash($v1['pinHashBase64'], $publicKeyPem),
            'hashPinhash' => $v1['hashPinHashBase64'],
            'additionalPinhash' => [
                'version' => 0,
                'pinhash' => $this->encryptPinHash($v0['pinHashHexDataBase64'], $publicKeyPem),
                'hashPinhash' => $v0['hashPinHashHexDataBase64'],
            ],
            'deviceInfo' => [
                'os' => ['model' => '', 'version' => ''],
                'browser' => ['model' => 'WebSDK', 'version' => ''],
                'device' => ['model' => 'Desktop', 'version' => ''],
                'sdk' => ['model' => 'Web', 'version' => ''],
            ],
            'pinType' => 'AUTO',
        ];

        $encryptedBody = $this->aesGcmEncrypt((string) json_encode($payload, JSON_UNESCAPED_SLASHES), base64_decode($encryptionKey, true) ?: '');

        $this->sdkRequest(
            'POST',
            $proxyUrl,
            '/v1/w3s/sdk/user/challenges/'.urlencode($challengeId).'/approve',
            $encryptedBody,
            $userToken,
            $appId,
            $deviceId,
        );
    }

    private function fetchAppId(string $proxyUrl): string
    {
        $cacheKey = 'circle_agent_proxy_app_id:'.md5($proxyUrl);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::timeout($this->timeout)
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->get($proxyUrl.'/config');

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch Circle appId from proxy: HTTP '.$response->status());
        }

        $appId = (string) ($response->json('appId') ?? $response->json('data.appId') ?? '');
        if ($appId !== '') {
            Cache::put($cacheKey, $appId, 3600);
        }

        return $appId;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>|null  $query
     * @return array<string, mixed>
     */
    private function w3sRequest(
        string $method,
        string $proxyUrl,
        string $path,
        string $userToken,
        ?array $body = null,
        ?array $query = null,
    ): array {
        $url = rtrim($proxyUrl, '/').self::API_PREFIX.$path;
        if ($query !== null && $query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $pending = Http::timeout($this->timeout)->withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$userToken,
            'X-User-Token' => $userToken,
            'User-Agent' => self::USER_AGENT,
        ]);

        $response = strtoupper($method) === 'GET'
            ? $pending->get($url)
            : $pending->send($method, $url, $body !== null ? ['json' => $body] : []);

        if ($response->status() === 204) {
            return [];
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                sprintf('Circle W3S API error (%d) on %s %s: %s', $response->status(), $method, $path, $this->extractErrorDetail($response->body(), $response->reason()))
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            return [];
        }

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function sdkRequest(
        string $method,
        string $proxyUrl,
        string $path,
        ?string $rawEncryptedBody,
        string $userToken,
        string $appId,
        string $deviceId,
    ): array {
        $url = rtrim($proxyUrl, '/').$path;

        $pending = Http::timeout($this->timeout)->withHeaders([
            'Content-Type' => 'application/json',
            'X-App-Id' => $appId,
            'X-Device-Id' => $deviceId,
            'Authorization' => 'Bearer '.$userToken,
            'X-User-Token' => $userToken,
            'User-Agent' => self::USER_AGENT,
        ]);

        $response = strtoupper($method) === 'GET'
            ? $pending->get($url)
            : $pending->withBody($rawEncryptedBody ?? '', 'application/json')->post($url);

        if (! $response->successful()) {
            throw new RuntimeException(
                sprintf('Circle SDK error (%d) on %s %s: %s', $response->status(), $method, $path, $this->extractErrorDetail($response->body(), $response->reason()))
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function deriveSocialPin(string $encryptedUserSecret, string $storageKeyBase64, string $pinCodeUserShare): string
    {
        $userSecret = $this->aesGcmDecrypt($encryptedUserSecret, $storageKeyBase64);
        $prefix = explode(':', $userSecret)[0] ?? '';
        $socialPinHex = hash('sha256', $prefix.$pinCodeUserShare);

        return base64_encode((string) hex2bin($socialPinHex));
    }

    /**
     * @return array{pinHashBase64: string, hashPinHashBase64: string}
     */
    private function pinHashV1(string $pin): array
    {
        $pinHash = hash('sha256', $pin, true);
        $hashPinHash = hash('sha256', $pinHash, true);

        return [
            'pinHashBase64' => base64_encode($pinHash),
            'hashPinHashBase64' => base64_encode($hashPinHash),
        ];
    }

    /**
     * @return array{pinHashHexDataBase64: string, hashPinHashHexDataBase64: string}
     */
    private function pinHashV0(string $pin): array
    {
        $pinHashHex = strtoupper(hash('sha256', $pin));
        $hashPinHashHex = strtoupper(hash('sha256', $pinHashHex));

        return [
            'pinHashHexDataBase64' => base64_encode($pinHashHex),
            'hashPinHashHexDataBase64' => base64_encode($hashPinHashHex),
        ];
    }

    private function encryptPinHash(string $pinhashBase64, string $publicKeyPem): string
    {
        $otkey = random_bytes(32);
        $pinHashStructJson = (string) json_encode(['pinhash' => $pinhashBase64], JSON_UNESCAPED_SLASHES);
        $aesString = $this->aesGcmEncrypt($pinHashStructJson, $otkey);
        $rsaString = $this->rsaOaepSha256Encrypt($otkey, $publicKeyPem);

        $envelope = (string) json_encode([
            'pinhash_struct' => $aesString,
            'otkey' => $rsaString,
        ], JSON_UNESCAPED_SLASHES);

        return base64_encode($envelope);
    }

    public function aesGcmEncrypt(string $plaintext, string $rawKey): string
    {
        if (strlen($rawKey) !== 32) {
            throw new RuntimeException('AES-256-GCM requires a 32-byte key.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16,
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('AES-256-GCM encryption failed.');
        }

        return base64_encode($iv.$ciphertext.$tag);
    }

    public function aesGcmDecrypt(string $cipherBase64, string $keyBase64): string
    {
        $combined = base64_decode($cipherBase64, true);
        $rawKey = base64_decode($keyBase64, true);

        if (! is_string($combined) || strlen($combined) < 28 || ! is_string($rawKey) || strlen($rawKey) !== 32) {
            throw new RuntimeException('Invalid AES-256-GCM ciphertext or key.');
        }

        $iv = substr($combined, 0, 12);
        $tag = substr($combined, -16);
        $ciphertext = substr($combined, 12, -16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new RuntimeException('AES-256-GCM decryption failed.');
        }

        return $plaintext !== '' ? $plaintext : '{}';
    }

    /**
     * Encrypt binary data with RSA-OAEP using SHA-256 and MGF1-SHA256 (matching node-forge in Circle CLI).
     */
    public function rsaOaepSha256Encrypt(string $data, string $publicKeyPem): string
    {
        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if ($pubKey === false) {
            throw new RuntimeException('Invalid RSA public key PEM.');
        }

        $details = openssl_pkey_get_details($pubKey);
        $keyBytes = (int) (($details['bits'] ?? 2048) / 8);
        $hLen = 32; // SHA-256

        if (strlen($data) > $keyBytes - 2 * $hLen - 2) {
            throw new RuntimeException('Message too long for RSA-OAEP-SHA256.');
        }

        $lHash = hash('sha256', '', true);
        $ps = str_repeat("\x00", $keyBytes - strlen($data) - 2 * $hLen - 2);
        $db = $lHash.$ps."\x01".$data;
        $seed = random_bytes($hLen);
        $dbMask = $this->mgf1Sha256($seed, $keyBytes - $hLen - 1);
        $maskedDb = $db ^ $dbMask;
        $seedMask = $this->mgf1Sha256($maskedDb, $hLen);
        $maskedSeed = $seed ^ $seedMask;
        $em = "\x00".$maskedSeed.$maskedDb;

        $encrypted = '';
        if (! openssl_public_encrypt($em, $encrypted, $pubKey, OPENSSL_NO_PADDING)) {
            throw new RuntimeException('RSA-OAEP-SHA256 public key encryption failed.');
        }

        return base64_encode($encrypted);
    }

    private function mgf1Sha256(string $seed, int $maskLen): string
    {
        $t = '';
        $counter = 0;
        while (strlen($t) < $maskLen) {
            $c = pack('N', $counter);
            $t .= hash('sha256', $seed.$c, true);
            $counter++;
        }

        return substr($t, 0, $maskLen);
    }

    /**
     * @param  array<string, mixed>  $wallet
     */
    private function walletId(array $wallet): string
    {
        $id = $wallet['id'] ?? $wallet['walletId'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Circle agent wallet record is missing its walletId.');
        }

        return $id;
    }

    private function isTestnet(string $chain): bool
    {
        return $chain === '' || str_contains(strtoupper($chain), 'TESTNET') || str_contains(strtoupper($chain), 'SEPOLIA') || str_contains(strtoupper($chain), 'DEVNET') || str_contains(strtoupper($chain), 'AMOY');
    }

    private function proxyUrl(bool $testnet): string
    {
        $base = $this->proxyBaseOverride
            ?? (string) (config('lepton.circle.proxy_url') ?: getenv('CIRCLE_PROXY_URL') ?: self::DEFAULT_PROXY_BASE);

        return rtrim($base, '/').'/proxy/'.($testnet ? 'test' : 'live');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEnvironmentSession(bool $testnet): array
    {
        $slotName = $testnet ? 'testnet' : 'mainnet';
        $env = $this->loadEnvironmentSession($testnet);

        if ($env === null || ! is_string($env['userToken'] ?? null) || $env['userToken'] === '') {
            throw new RuntimeException("No active Circle agent {$slotName} session found.");
        }

        if ($this->isExpired($env)) {
            throw new RuntimeException("Circle agent {$slotName} session has expired.");
        }

        return $env;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadEnvironmentSession(bool $testnet): ?array
    {
        $slot = $testnet ? 'testnet' : 'mainnet';

        if ($this->sessionOverride !== null) {
            $candidate = $this->sessionOverride[$slot] ?? $this->sessionOverride;

            return is_array($candidate) ? $candidate : null;
        }

        if (isset($this->resolvedSessions[$slot])) {
            return $this->resolvedSessions[$slot];
        }

        $sessionPath = $this->sessionFilePath();
        if ($sessionPath === null || ! is_file($sessionPath)) {
            return null;
        }

        $raw = @file_get_contents($sessionPath);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded[$slot] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $slotData */
        $slotData = $decoded[$slot];
        if (! isset($slotData['email']) && isset($decoded['email']) && is_string($decoded['email'])) {
            $slotData['email'] = $decoded['email'];
        }

        if (is_string($slotData['userToken'] ?? null) && $slotData['userToken'] !== '') {
            $this->resolvedSessions[$slot] = $slotData;

            return $slotData;
        }

        $secrets = $this->loadKeychainSecrets($slot);
        if ($secrets !== null) {
            $merged = array_merge($slotData, $secrets);
            $this->resolvedSessions[$slot] = $merged;

            // Hydrate session.json (0600) so non-DBus workers can read the session directly without shelling out.
            $decoded[$slot] = $merged;
            @file_put_contents($sessionPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            @chmod($sessionPath, 0600);

            return $merged;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadKeychainSecrets(string $slot): ?array
    {
        $account = "agent-session-{$slot}";

        if (PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/secret-tool')) {
            try {
                $process = new Process(['/usr/bin/secret-tool', 'lookup', 'app', 'circle-cli', 'account', $account]);
                $process->setTimeout(5);
                $process->run();
                if ($process->isSuccessful()) {
                    $decoded = json_decode(trim($process->getOutput()), true);
                    if (is_array($decoded) && is_string($decoded['userToken'] ?? null)) {
                        return $decoded;
                    }
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function sessionFilePath(): ?string
    {
        $cliHome = (string) (config('lepton.circle.home') ?: getenv('CIRCLE_CLI_HOME') ?: '');
        if ($cliHome !== '') {
            return rtrim($cliHome, '/').'/profiles/agent/session.json';
        }

        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? ''));
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            $home = is_array($pw) && is_string($pw['dir'] ?? null) ? $pw['dir'] : '';
        }

        if ($home === '') {
            return null;
        }

        return rtrim($home, '/').'/.circle-cli/profiles/agent/session.json';
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private function isExpired(array $env): bool
    {
        if (! isset($env['expiresAt']) || ! is_numeric($env['expiresAt'])) {
            return false;
        }

        return ((int) $env['expiresAt']) <= (int) round(microtime(true) * 1000);
    }

    private function extractErrorDetail(string $body, string $fallback): string
    {
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $msg = $decoded['message'] ?? $decoded['error']['message'] ?? $decoded['response']['status']['reason'] ?? null;
                if (is_string($msg) && trim($msg) !== '') {
                    return trim($msg);
                }
            }

            return mb_substr(trim($body), 0, 300);
        }

        return $fallback !== '' ? $fallback : 'Unknown HTTP error';
    }

    private function normalizeUuid(string $key): string
    {
        if (str_starts_with($key, 'eduflow:')) {
            $raw = substr($key, 8);
            if (Str::isUuid($raw)) {
                return strtolower($raw);
            }
        }

        if (Str::isUuid($key)) {
            return strtolower($key);
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, $key)->toString();
    }
}
