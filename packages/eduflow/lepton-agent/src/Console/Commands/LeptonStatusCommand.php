<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Console\Commands;

use Eduflow\Lepton\LeptonManager;
use Illuminate\Console\Command;

final class LeptonStatusCommand extends Command
{
    protected $signature = 'lepton:status {--address= : Wallet to inspect (defaults to LEPTON_TREASURY_ADDRESS)} {--chain= : Override chain code}';

    protected $description = 'Show Arc block, wallet balance, and spending limits via Lepton gateways.';

    public function handle(LeptonManager $lepton): int
    {
        $address = (string) ($this->option('address') ?: config('lepton.arc.treasury', ''));

        if ($address === '') {
            $this->error('No address given. Pass --address=0x... or set LEPTON_TREASURY_ADDRESS.');

            return self::FAILURE;
        }

        $status = $lepton->status($address);

        $this->table(['Key', 'Value'], collect($status)->map(fn ($v, $k): array => [$k, is_array($v) ? json_encode($v) : (string) $v])->values()->all());

        return self::SUCCESS;
    }
}
