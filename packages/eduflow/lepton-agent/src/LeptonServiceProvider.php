<?php

declare(strict_types=1);

namespace Eduflow\Lepton;

use Eduflow\Lepton\Contracts\ArcNetworkGateway;
use Eduflow\Lepton\Contracts\WalletGateway;
use Eduflow\Lepton\Contracts\X402Gateway;
use Eduflow\Lepton\Gateways\ArcCanteenGateway;
use Eduflow\Lepton\Gateways\CircleCliGateway;
use Eduflow\Lepton\Gateways\FakeLeptonGateway;
use Eduflow\Lepton\Support\CliRunner;
use Illuminate\Support\ServiceProvider;

final class LeptonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lepton.php', 'lepton');

        // Octane-safe: scoped() avoids leaking Process state across requests.
        $this->app->scoped(CliRunner::class.'circle', fn (): CliRunner => new CliRunner(
            (string) config('lepton.circle.bin', 'circle'),
            (int) config('lepton.arc.timeout', 60),
        ));

        $this->app->scoped(CliRunner::class.'arc', fn (): CliRunner => new CliRunner(
            (string) config('lepton.arc.bin', 'arc-canteen'),
            (int) config('lepton.arc.timeout', 60),
        ));

        $this->app->scoped(FakeLeptonGateway::class, fn (): FakeLeptonGateway => new FakeLeptonGateway);

        $this->app->scoped(ArcNetworkGateway::class, function (): ArcNetworkGateway {
            if ($this->driver() === 'fake') {
                return $this->app->make(FakeLeptonGateway::class);
            }

            return new ArcCanteenGateway(
                arc: $this->app->make(CliRunner::class.'arc'),
                chainCode: (string) config('lepton.arc.chain', 'ARC-TESTNET'),
                chainId: (int) config('lepton.arc.chain_id', 5042002),
                treasuryAddress: config('lepton.arc.treasury'),
                configuredRpcUrl: config('lepton.arc.rpc_url'),
                testnetExplorer: (string) config('lepton.explorer.testnet'),
                mainnetExplorer: (string) config('lepton.explorer.mainnet'),
            );
        });

        $this->app->scoped(WalletGateway::class, function (): WalletGateway {
            if ($this->driver() === 'fake') {
                return $this->app->make(FakeLeptonGateway::class);
            }

            return new CircleCliGateway(
                circle: $this->app->make(CliRunner::class.'circle'),
                usdcDecimals: (int) config('lepton.usdc_decimals', 6),
                dryRun: (bool) config('lepton.dry_run', false),
                defaultRpcUrl: config('lepton.arc.rpc_url'),
            );
        });

        $this->app->scoped(X402Gateway::class, function (): X402Gateway {
            if ($this->driver() === 'fake') {
                return $this->app->make(FakeLeptonGateway::class);
            }

            // Circle CLI handles both wallet + x402, reuse same runner.
            return $this->app->make(WalletGateway::class) instanceof X402Gateway
                ? $this->app->make(WalletGateway::class)
                : new CircleCliGateway($this->app->make(CliRunner::class.'circle'));
        });

        $this->app->scoped(LeptonManager::class, fn (): LeptonManager => new LeptonManager(
            $this->app->make(WalletGateway::class),
            $this->app->make(ArcNetworkGateway::class),
            $this->app->make(X402Gateway::class),
        ));

        $this->app->alias(LeptonManager::class, 'lepton');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/lepton.php' => config_path('lepton.php')], 'lepton-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\LeptonStatusCommand::class,
                Console\Commands\LeptonTransferCommand::class,
            ]);
        }
    }

    private function driver(): string
    {
        $driver = (string) config('lepton.default', 'circle');

        return in_array($driver, ['circle', 'fake'], true) ? $driver : 'circle';
    }
}
