<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

/**
 * Reads the Arc chain through the Lepton gateways so the dashboard reflects
 * real settlement state rather than the local ledger alone.
 */
class LeptonNetworkWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    /**
     * A plain integer applies at every breakpoint, which rendered four tiles
     * side by side on a 390px phone at roughly 85px each. Breaking down to a
     * single column keeps the chain figures readable on a small screen.
     *
     * @var array<string, int>
     */
    protected int|array|null $columns = ['@lg' => 4, '@md' => 2, 'default' => 1];

    /**
     * Chain reconciliation is a widget-level concern, not a per-stat one:
     * Filament stats have no action slot.
     */
    protected function getHeaderActions(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [];
        }

        $wallet = $institution->primaryWallet();
        $status = app(LeptonTreasuryService::class)->status($wallet);

        $needsAdoption = $wallet !== null
            && $status['treasury_address'] !== null
            && strcasecmp($status['treasury_address'], $wallet->address) !== 0;

        return [
            Action::make('openExplorer')
                ->label('ArcScan')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => $status['address_url'] !== null)
                ->url(fn (): ?string => $status['address_url'])
                ->openUrlInNewTab(),

            Action::make('adoptWallet')
                ->label('Use agent wallet')
                ->icon('heroicon-m-link')
                ->color('primary')
                ->visible(fn (): bool => $needsAdoption)
                ->requiresConfirmation()
                ->modalHeading('Point the treasury at your Lepton agent wallet?')
                ->modalDescription('Updates the organization wallet address to the configured LEPTON_TREASURY_ADDRESS. The ledger balance is untouched until you sync from the chain.')
                ->action(function () use ($wallet): void {
                    if (! $wallet) {
                        return;
                    }

                    try {
                        $changed = app(LeptonTreasuryService::class)->adoptConfiguredWallet($wallet);

                        Notification::make()
                            ->title($changed ? 'Treasury wallet updated' : 'Already using that wallet')
                            ->body($changed ? 'Payments now originate from '.$wallet->fresh()->address : null)
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()->title('Could not update treasury')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('syncBalance')
                ->label('Sync from chain')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Overwrite the ledger with the on-chain balance?')
                ->modalDescription('Reads the live '.strtoupper($status['chain']).' balance through Lepton and writes it to the OpenSinop ledger. Use this when the two have diverged.')
                ->action(function () use ($wallet): void {
                    if (! $wallet) {
                        Notification::make()->title('No treasury wallet found.')->danger()->send();

                        return;
                    }

                    try {
                        $live = app(LeptonTreasuryService::class)->syncBalance($wallet);

                        if ($live === null) {
                            Notification::make()
                                ->title('Sync failed')
                                ->body('Could not read a live balance. Confirm the Lepton CLI is authenticated for '.strtoupper((string) config('lepton.arc.chain', 'ARC-TESTNET')).'.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Treasury synced')
                            ->body('Ledger balance set to '.number_format($live, 2).' USDC from the live chain.')
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()->title('Sync failed')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    #[\Override]
    protected function getStats(): array
    {
        $org = app(InstallationInstitution::class)->current();

        if ($org === null) {
            return [Stat::make('Institution', 'Not configured')
                ->description('Configure EDUFLOW_INSTITUTION_ID for a single institution')
                ->color('warning')];
        }

        $wallet = $org->primaryWallet();
        $status = app(LeptonTreasuryService::class)->status($wallet);

        $chain = strtoupper($status['chain']);
        $isMainnet = $status['chain'] === 'ARC';

        $networkStat = Stat::make('Arc Settlement Network', $chain)
            ->description($status['live_available']
                ? 'Block '.number_format($status['block'] ?? 0).' · id '.$status['chain_id']
                : 'Live chain reads unavailable')
            ->color($isMainnet ? 'warning' : 'success');

        if ($status['address_url']) {
            $networkStat->extraAttributes([
                'class' => 'cursor-pointer hover:shadow-sm transition-shadow',
                'x-data' => '{}',
                'x-on:click' => 'window.open('.json_encode($status['address_url']).', \'_blank\')',
            ]);
        }

        $balanceStat = Stat::make('On-Chain Treasury', $status['onchain_balance'] !== null
            ? number_format($status['onchain_balance'], 2).' USDC'
            : 'Unavailable')
            ->description($status['onchain_balance'] !== null
                ? 'Live via RPC · ledger '.number_format((float) $status['ledger_balance'], 2).' USDC'
                : 'Chain read failed')
            ->color($status['live_available'] ? 'success' : 'gray');

        if ($status['in_sync'] !== null) {
            $balanceStat->color($status['in_sync'] ? 'success' : 'warning')
                ->description($status['in_sync'] ? 'Ledger matches chain' : 'Ledger drift '.$status['drift'].' USDC');
        }

        // The value already truncates the address; repeating the full 42-char
        // token as the description pushed it out of the tile and across the
        // neighbouring card. The description now states what the address is,
        // and the full value stays reachable on hover.
        $treasuryStat = Stat::make('Agent Wallet', $this->shortAddress($status['treasury_address']))
            ->description($status['treasury_address'] ? 'Treasury destination on '.$chain : 'Set LEPTON_TREASURY_ADDRESS')
            ->color($status['treasury_address'] ? 'gray' : 'danger');

        if ($status['treasury_address']) {
            $treasuryStat->extraAttributes(['title' => $status['treasury_address']]);
        }

        $driverStat = Stat::make('Lepton Driver', $status['driver'])
            ->description($status['is_fake']
                ? 'Fake driver: no real USDC moves'
                : 'Circle CLI + arc-canteen')
            ->color($status['is_fake'] ? 'warning' : 'gray');

        return [$networkStat, $balanceStat, $treasuryStat, $driverStat];
    }

    private function shortAddress(?string $address): string
    {
        if ($address === null || $address === '') {
            return 'Not configured';
        }

        return strlen($address) > 14
            ? substr($address, 0, 6).'…'.substr($address, -4)
            : $address;
    }
}
