<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Console\Commands;

use Eduflow\Lepton\LeptonManager;
use Illuminate\Console\Command;

final class LeptonTransferCommand extends Command
{
    protected $signature = 'lepton:transfer {to : Destination 0x address} {--amount= : Decimal USDC like 450.00} {--from= : Source wallet (defaults to LEPTON_TREASURY_ADDRESS)} {--chain= : Override chain} {--estimate : Fee estimate only}';

    protected $description = 'Transfer USDC via Circle CLI gateway using integer base units internally.';

    public function handle(LeptonManager $lepton): int
    {
        $to = (string) $this->argument('to');
        $amount = (string) ($this->option('amount') ?? '');
        $from = (string) ($this->option('from') ?: config('lepton.arc.treasury', ''));

        if ($from === '' || $to === '' || $amount === '') {
            $this->error('Usage: php artisan lepton:transfer 0xDEST --amount=1.00 --from=0xSRC');

            return self::FAILURE;
        }

        $result = $lepton->transferDecimal($from, $to, $amount, [
            'chain' => $this->option('chain') ?: config('lepton.arc.chain', 'ARC-TESTNET'),
            'estimate' => (bool) $this->option('estimate'),
        ]);

        $this->info("Tx: {$result->txHash}");
        $this->line("Explorer: {$result->explorerUrl}");

        return self::SUCCESS;
    }
}
