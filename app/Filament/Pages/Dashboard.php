<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\AdoptionActivityChart;
use App\Filament\Widgets\AgentActivityFeedWidget;
use App\Filament\Widgets\InstallationReadinessWidget;
use App\Filament\Widgets\LeptonNetworkWidget;
use App\Filament\Widgets\TreasuryOverviewWidget;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\Widget;

/**
 * Installation and operations console for the super administrator.
 *
 * This panel governs the instance — identity, rail binding, staff, providers,
 * settings — and deliberately does not move money. The cashier, accounting
 * and supervisor workflow lives in the finance panel, so the two never blur
 * into one surface that looks equally safe to click from.
 */
class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Installation & operations';

    #[\Override]
    public function getSubheading(): ?string
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return 'Institution context unavailable. Run php artisan eduflow:install; data is never selected by row order.';
        }

        $status = app(LeptonTreasuryService::class)->status($institution->primaryWallet());

        return sprintf(
            '%s · %s driver on %s %s%s · finance supervision: %s',
            $institution->name,
            $status['driver'],
            strtoupper((string) $status['chain']),
            (string) $status['chain_id'],
            $status['is_fake'] ? ' · FAKE DRIVER' : '',
            Filament::getPanel('finance')->getUrl(),
        );
    }

    /**
     * Explicit rather than inherited: without this the panel renders every
     * registered widget, so an unrelated plugin widget can appear beside
     * settlement figures that operators will read as authoritative.
     *
     * @return array<class-string<Widget>>
     */
    #[\Override]
    public function getWidgets(): array
    {
        return [
            InstallationReadinessWidget::class,
            AdoptionActivityChart::class,
            LeptonNetworkWidget::class,
            TreasuryOverviewWidget::class,
            AgentActivityFeedWidget::class,
            AccountWidget::class,
        ];
    }

    /** @return int|array<string, int|null> */
    #[\Override]
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2, 'xl' => 3];
    }

    /**
     * The legacy autonomous cycle writes a ledger row without touching the
     * chain, which is the fabricated-receipt pattern the pilot contract
     * forbids. Recorded receipts belong in cashier collection batches, which
     * require independent review before they can fund anything.
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
