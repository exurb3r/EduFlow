<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Gateways;

use Eduflow\Lepton\Contracts\ArcNetworkGateway;
use Eduflow\Lepton\Support\CliRunner;

final class ArcCanteenGateway implements ArcNetworkGateway
{
    public function __construct(
        private readonly CliRunner $arc,
        private readonly string $chainCode = 'ARC-TESTNET',
        private readonly int $chainId = 5042002,
        private readonly ?string $treasuryAddress = null,
        private readonly ?string $configuredRpcUrl = null,
        private readonly string $testnetExplorer = 'https://testnet.arcscan.app/tx/',
        private readonly string $mainnetExplorer = 'https://arcscan.app/tx/',
    ) {}

    public function rpcUrl(): string
    {
        if (is_string($this->configuredRpcUrl) && $this->configuredRpcUrl !== '') {
            return $this->configuredRpcUrl;
        }

        $out = $this->arc->run(['rpc-url'], false);

        return is_string($out) ? trim($out) : '';
    }

    public function chainCode(): string
    {
        return $this->chainCode;
    }

    public function chainId(): int
    {
        return $this->chainId;
    }

    public function blockNumber(): string
    {
        $out = $this->arc->run(['rpc', 'eth_blockNumber'], false);

        return is_string($out) ? trim($out, "\" \n") : '';
    }

    public function treasuryAddress(): ?string
    {
        return $this->treasuryAddress;
    }

    public function explorerUrl(string $txHash): string
    {
        $base = $this->chainCode === 'ARC' ? $this->mainnetExplorer : $this->testnetExplorer;

        return rtrim($base, '/').'/'.$txHash;
    }
}
