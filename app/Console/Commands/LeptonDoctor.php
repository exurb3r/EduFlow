<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * One command that answers "is my Lepton agent wallet actually working?".
 *
 * Checks binaries, Circle auth per network, the configured treasury, live
 * chain reads, and finally performs a real (optionally tiny) transfer.
 */
#[Signature('lepton:doctor {--transfer= : Send this decimal amount to --to as a real settlement probe} {--to= : Destination address for the probe transfer} {--skip-transfer : Run read-only checks only} {--adopt-agent-wallet : Point the EduFlow treasury at the Circle agent wallet Circle can sign for}')]
#[Description('Diagnose the Lepton agent wallet: CLIs, auth, treasury, chain reads, and a real settlement probe.')]
class LeptonDoctor extends Command
{
    private int $failures = 0;

    public function handle(LeptonTreasuryService $treasury): int
    {
        if (app(InstallationInstitution::class)->current() === null) {
            $this->error('Institution context unavailable. Check EDUFLOW_INSTITUTION_ID and single-institution setup.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Lepton agent wallet doctor');
        $this->line('  '.str_repeat('─', 60));

        $this->checkBinaries();
        $this->checkCircleAuth();
        $treasuryAddress = $this->checkConfiguredTreasury();
        $this->checkChainReads($treasuryAddress);
        $this->checkLedgerParity();

        if ($treasuryAddress !== null) {
            $this->probeTransfer($treasuryAddress);
        }

        $this->newLine();
        $this->line('  '.str_repeat('─', 60));

        if ($this->failures === 0) {
            $this->line('  <fg=green>All checks passed.</> Real settlement is available.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line(sprintf('  <fg=red>%d check(s) need attention.</>', $this->failures));
        $this->newLine();

        return self::FAILURE;
    }

    private function checkBinaries(): void
    {
        $this->heading('CLI binaries');

        foreach (['circle' => config('lepton.circle.bin', 'circle'), 'arc-canteen' => config('lepton.arc.bin', 'arc-canteen')] as $label => $binary) {
            $found = (new ExecutableFinder)->find($binary) !== null;
            $this->line(sprintf('  %s %-14s %s', $found ? '<fg=green>✓</>' : '<fg=red>✗</>', $label, $binary));

            if (! $found) {
                $this->failures++;
                $this->detail("Install {$label} and ensure it is on PATH.");
            }
        }
    }

    private function checkCircleAuth(): void
    {
        $this->heading('Circle agent session');

        $chain = (string) config('lepton.arc.chain', 'ARC-TESTNET');
        $network = str_contains($chain, 'TESTNET') ? 'testnet' : 'mainnet';

        try {
            $status = app(AuthGateway::class)->authStatus();
        } catch (Throwable $e) {
            $this->line('  <fg=red>✗</> could not read Circle status: '.$e->getMessage());
            $this->detail('Is the Circle CLI installed?  npm install -g @circle-fin/cli');
            $this->failures++;

            return;
        }

        $this->kv('type', (string) $status['type']);

        foreach (['mainnet', 'testnet'] as $name) {
            $section = $status[$name];
            $valid = $section['authenticated'] === true;
            $marker = $valid ? '<fg=green>✓</>' : '<fg=red>✗</>';

            $this->line(sprintf(
                '  %s %-14s %-30s %s',
                $marker,
                $name,
                $section['email'] ?? 'not signed in',
                $valid ? 'expires in '.$section['expires_in'] : ''
            ));
        }

        // Mainnet and testnet authenticate independently, so a valid mainnet
        // session does not authorise transfers on the configured chain.
        if (($status[$network]['authenticated'] ?? false) !== true) {
            $this->line('  <fg=red>✗</> no usable session for '.$chain);
            $this->failures++;
            $this->detail('Run: php artisan lepton:login '.$emailHint().'   (add --testnet for testnet)');

            return;
        }

        $this->line('  <fg=green>✓</> configured chain '.strtoupper($chain).' is authorised');
    }

    private function emailHint(): string
    {
        return '<your-email>';
    }

    private function checkConfiguredTreasury(): ?string
    {
        $this->heading('Treasury wallet');

        $configured = config('lepton.arc.treasury');
        $wallet = app(InstallationInstitution::class)->current()?->primaryWallet();
        $dbAddress = $wallet?->address;

        $chain = (string) config('lepton.arc.chain', 'ARC-TESTNET');
        $network = str_contains($chain, 'TESTNET') ? 'testnet' : 'mainnet';

        $this->kv('env', $configured ?: '<fg=red>not set</>');
        $this->kv('database', $dbAddress ?: '<fg=red>no wallet row</>');

        if (! $configured) {
            $this->failures++;
            $this->detail('Add LEPTON_TREASURY_ADDRESS=0x... to .env');

            return null;
        }

        // Which wallets does Circle actually know about on this network?
        $list = $this->runCli((string) config('lepton.circle.bin', 'circle'), ['wallet', 'list', '--chain', $chain, '--type', 'agent', '--output', 'json']);
        $known = [];

        if ($list['exit'] === 0) {
            $decoded = json_decode($list['output'], true);
            foreach ($decoded['data']['wallets'] ?? [] as $w) {
                $known[strtolower((string) ($w['address'] ?? ''))] = $w['address'] ?? '';
            }
        }

        $matches = isset($known[strtolower($configured)]);

        $this->kv('circle agent wallet', $matches
            ? '<fg=green>'.$configured.'</>'
            : '<fg=yellow>'.$configured.' is not a Circle agent wallet</>');

        if (! $matches) {
            $this->failures++;
            $this->detail('Circle can only sign for its own agent wallets, so transfers would fail.');

            if ($known !== []) {
                $agent = array_values($known)[0];

                if ($this->option('adopt-agent-wallet')) {
                    $this->adopt($configured, $agent);
                } else {
                    $this->detail('Fix with: php artisan lepton:doctor --adopt-agent-wallet');
                }
            }
        }

        if ($dbAddress !== null && strcasecmp($dbAddress, $configured) !== 0) {
            $this->failures++;
            $this->detail('Database treasury differs. Run: php artisan eduflow:demo  or use "Use agent wallet" on the dashboard.');
        }

        // Is it funded? On Arc, USDC is the gas token, so an empty wallet cannot send.
        $balance = $this->liveNativeBalance($configured);

        if ($balance === null) {
            $this->kv('on-chain balance', '<fg=yellow>unreadable</>');
        } else {
            $this->kv('on-chain balance', $balance > 0
                ? sprintf('<fg=green>%s USDC</>', number_format($balance, 4))
                : sprintf('<fg=red>%s USDC — fund it</>', number_format($balance, 4)));

            if ($balance <= 0) {
                $this->failures++;
                $this->detail('Fund it with: circle wallet fund --address '.$configured.' --chain '.$chain);
            }
        }

        return $configured;
    }

    private function checkChainReads(?string $address): void
    {
        $this->heading('Chain reads');

        try {
            $arc = app(ArcNetworkGateway::class);
            $this->kv('chain', $arc->chainCode().' (id '.$arc->chainId().')');

            $block = $arc->blockNumber();
            $this->kv('block', is_string($block) && $block !== ''
                ? number_format((int) Amounts::fromHexQuantity($block, 0))
                : '<fg=red>unavailable</>');

            $rpc = $arc->rpcUrl();
            $this->kv('rpc', parse_url($rpc, PHP_URL_HOST) ?: '<fg=yellow>unavailable</>');

            $url = $address !== null ? $arc->addressExplorerUrl($address) : null;
            $this->kv('explorer', $url ?? '<fg=yellow>unavailable</>');
        } catch (Throwable $e) {
            $this->line('  <fg=red>✗</> chain reads failed: '.$e->getMessage());
            $this->failures++;
        }
    }

    private function checkLedgerParity(): void
    {
        $this->heading('EduFlow ledger parity');

        $status = app(LeptonTreasuryService::class)->status(app(InstallationInstitution::class)->current()?->primaryWallet());

        if (! $status['live_available']) {
            $this->line('  <fg=yellow>?</> live balance unreadable, cannot compare');
            $this->detail($status['error'] ?? '');

            return;
        }

        $this->kv('on-chain', number_format((float) $status['onchain_balance'], 2).' USDC');
        $this->kv('ledger', number_format((float) $status['ledger_balance'], 2).' USDC');

        if ($status['in_sync']) {
            $this->line('  <fg=green>✓</> ledger matches the chain');
        } else {
            $this->line(sprintf('  <fg=yellow>!</> drift of %s USDC', $status['drift']));
            $this->detail('Expected before funding. Use "Sync from chain" on the dashboard once the wallet holds real USDC.');
        }
    }

    private function probeTransfer(string $from): void
    {
        $this->heading('Settlement probe');

        if ($this->option('skip-transfer') || ! $this->option('transfer')) {
            $this->line('  <fg=yellow>skipped</> (re-run with --transfer=0.01 --to=0x... to prove settlement)');

            return;
        }

        $to = (string) $this->option('to');
        $amount = (string) $this->option('transfer');

        if (! str_starts_with($to, '0x')) {
            $this->line('  <fg=red>✗</> --to must be a 0x address');

            return;
        }

        try {
            $result = app(WalletGateway::class)->transfer($from, $to, (int) round((float) $amount * 1000000), [
                'chain' => (string) config('lepton.arc.chain', 'ARC-TESTNET'),
            ]);

            $this->line('  <fg=green>✓</> transfer broadcast');
            $this->kv('hash', $result->txHash);
            $this->kv('explorer', (string) $result->explorerUrl);

            if ($result->isFake) {
                $this->line('  <fg=red>✗</> driver reported this as FAKE — no real USDC moved');
                $this->detail('Set LEPTON_DRIVER=circle in .env');
                $this->failures++;
            }
        } catch (Throwable $e) {
            $this->line('  <fg=red>✗</> transfer failed: '.$e->getMessage());
            $this->failures++;
        }
    }

    /**
     * Rewrite LEPTON_TREASURY_ADDRESS and the database treasury in place.
     */
    private function adopt(string $current, string $agent): void
    {
        $env = base_path('.env');

        if (is_file($env) && is_writable($env)) {
            $contents = (string) file_get_contents($env);
            $line = 'LEPTON_TREASURY_ADDRESS='.$agent;

            $replaced = preg_replace('/^LEPTON_TREASURY_ADDRESS=.*$/m', $line, $contents);

            $updated = is_string($replaced) && $replaced !== $contents
                ? $replaced
                : rtrim($contents)."\n".$line."\n";

            file_put_contents($env, $updated);
            $this->kv('.env updated', '<fg=green>'.$line.'</>');
        } else {
            $this->kv('.env updated', '<fg=red>not writable</>');
        }

        $wallet = app(InstallationInstitution::class)->current()?->primaryWallet();

        if ($wallet !== null) {
            $wallet->update(['address' => $agent]);
            $this->kv('database updated', '<fg=green>'.$agent.'</>');
        }

        $this->newLine();
        $this->line('  <fg=cyan>Run this command again to re-verify.</>');
        $this->line('  <fg=gray>Note: the ledger balance is unchanged; sync it once the wallet holds the demo funds.</>');
    }

    /**
     * @return array{exit:int, output:string}
     */
    private function runCli(string $binary, array $args): array
    {
        try {
            $process = new Process(array_merge([$binary], $args));
            $process->setTimeout(45);
            $process->run();

            return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
        } catch (Throwable) {
            return ['exit' => 1, 'output' => ''];
        }
    }

    private function liveNativeBalance(string $address): ?float
    {
        try {
            $wei = app(ArcNetworkGateway::class)->rpc('eth_getBalance', [$address, 'latest']);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($wei) || ! str_starts_with($wei, '0x')) {
            return null;
        }

        // Native Arc USDC is 18 decimals; a hexdec() cast would overflow above 9.2e18 wei.
        try {
            return (float) Amounts::fromHexQuantity($wei, 18);
        } catch (Throwable) {
            return null;
        }
    }

    private function heading(string $text): void
    {
        $this->newLine();
        $this->line('  <fg=cyan>'.$text.'</>');
    }

    private function kv(string $key, string $value): void
    {
        $this->line(sprintf('    %-22s %s', $key, $value));
    }

    private function detail(string $text): void
    {
        if ($text !== '') {
            $this->line('    <fg=gray>↳ '.$text.'</>');
        }
    }
}
