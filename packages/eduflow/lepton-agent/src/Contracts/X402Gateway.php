<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Contracts;

interface X402Gateway
{
    /**
     * @return array<int, array<string,mixed>>
     */
    public function searchServices(string $query): array;

    /**
     * @param  array<string,mixed>  $options  Supported: chain, method, data, headers, maxAmount, timeout
     * @return array<string,mixed>
     */
    public function inspectService(string $url, array $options = []): array;

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function payService(string $url, string $fromAddress, array $options = []): array;

    /**
     * @return array<string,mixed>
     */
    public function gatewayBalance(string $address, array $options = []): array;
}
