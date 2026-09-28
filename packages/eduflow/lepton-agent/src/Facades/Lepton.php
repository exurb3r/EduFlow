<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Facades;

use Eduflow\Lepton\DTOs\BalanceResult;
use Eduflow\Lepton\DTOs\TransferResult;
use Eduflow\Lepton\LeptonManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static TransferResult transfer(string $from, string $to, int $amountBaseUnits, array $options = [])
 * @method static TransferResult transferDecimal(string $from, string $to, string $decimalAmount, array $options = [])
 * @method static BalanceResult balance(string $address, array $options = [])
 * @method static array status(string $address)
 *
 * @see LeptonManager
 */
final class Lepton extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LeptonManager::class;
    }
}
