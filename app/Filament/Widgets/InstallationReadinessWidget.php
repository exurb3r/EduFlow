<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Clusters\Settings\Pages\ApplicationDetailsSettingsPage;
use App\Filament\Clusters\Settings\Pages\ApplicationFeaturesSettingsPage;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use App\Models\Organization;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use App\Settings\AiSettings;
use App\Settings\ApplicationFeaturesSettings;
use App\Settings\InstallationSettings;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Whether this installation is ready to be handed to a school.
 *
 * The admin panel governs the installation, not the money: one institution
 * per self-hosted instance, conservative onboarding defaults, and a rail
 * that is honestly labelled. Defaults are deliberately narrow — manual
 * release, zero autonomous allowance, no enabled live rail, AI off, public
 * registration off — so a school must consciously widen any of them.
 */
class InstallationReadinessWidget extends BaseWidget
{
    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = 3;

    #[\Override]
    public function getHeading(): ?string
    {
        return 'Installation readiness';
    }

    #[\Override]
    public function getDescription(): ?string
    {
        return 'One institution per installation. This is not multi-tenancy: two schools run two instances, and shared provider credentials or wallet sessions are never acceptable. Financial execution lives in the finance panel.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [Stat::make('Installation', 'Not configured')
                ->description('Run php artisan eduflow:install. A missing or ambiguous institution fails closed rather than defaulting to the first row.')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger')];
        }

        $installed = $this->installedAt();
        $organizationCount = Organization::query()->count();

        $identityStat = Stat::make('Institution', $institution->name)
            ->description($installed === null
                ? sprintf('%s · %s · %s', $institution->currency, app()->getLocale(), config('app.timezone'))
                : sprintf('Initialised %s · %s · %s', $installed, $institution->currency, config('app.timezone')))
            ->descriptionIcon('heroicon-o-academic-cap')
            ->color('primary')
            ->chart([3, 5, 4, 7, 6, 8, 9])
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(ApplicationDetailsSettingsPage::getUrl());

        $singleStat = Stat::make('Institution boundary', $organizationCount === 1 ? 'Single institution' : $organizationCount.' organizations')
            ->description($organizationCount === 1
                ? 'Exactly one institution is permitted on this profile. A second is blocked; raw database writes are outside that guard.'
                : 'Ambiguous institution context. Dashboards, the wallet doctor and settlement refuse to guess.')
            ->descriptionIcon('heroicon-o-building-library')
            ->color($organizationCount === 1 ? 'success' : 'danger')
            ->chart($organizationCount === 1 ? [1, 1, 1, 1, 1, 1, 1] : [3, 1, 4, 2, 5, 2, 4])
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(ApplicationDetailsSettingsPage::getUrl());

        $features = $this->features();
        $openGates = array_keys(array_filter([
            'Registration' => $features instanceof ApplicationFeaturesSettings && $features->registration_enabled,
            'Impersonation' => $features instanceof ApplicationFeaturesSettings && $features->user_impersonation_enabled,
        ]));

        $onboardingStat = Stat::make('School onboarding', $openGates === [] ? 'Locked down' : 'Open: '.implode(', ', $openGates))
            ->description('Conservative defaults are intentional. Registration and impersonation should stay off until the school chooses otherwise.')
            ->descriptionIcon('heroicon-o-lock-closed')
            ->color($openGates === [] ? 'success' : 'warning')
            ->chart($openGates === [] ? [1, 1, 1, 1, 1, 1] : [2, 4, 3, 5, 4, 6])
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(ApplicationFeaturesSettingsPage::getUrl());

        $ai = $this->ai();

        $aiStat = Stat::make('Advisory AI', $ai instanceof AiSettings && $ai->advisory_enabled ? 'Enabled' : 'Off by default')
            ->description(sprintf(
                '%s · disclosure %s · settlement proposals %s',
                AiProvider::query()->where('is_active', true)->count().' active provider(s)',
                $ai instanceof AiSettings && $ai->disclosure_accepted ? 'accepted' : 'not accepted',
                $ai instanceof AiSettings && $ai->allow_settlement_proposals ? 'ALLOWED' : 'not allowed',
            ))
            ->descriptionIcon('heroicon-o-sparkles')
            ->color($ai instanceof AiSettings && $ai->advisory_enabled ? 'info' : 'gray')
            ->chart([2, 3, 5, 4, 6, 8, 7])
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(AiProviderResource::getUrl('index'));

        $status = app(LeptonTreasuryService::class)->status($institution->primaryWallet());
        $wallet = $institution->primaryWallet();

        $bound = $wallet !== null
            && $status['treasury_address'] !== null
            && strcasecmp($status['treasury_address'], $wallet->address) === 0;

        $railStat = Stat::make('Settlement rail', $status['driver'].' · '.strtoupper((string) $status['chain']))
            ->description($status['is_fake']
                ? 'Fake driver: no wallet, no chain, no real funds. Safe for demonstration, never for finance.'
                : ($bound ? 'Treasury bound to the configured agent wallet' : 'Treasury address does not match the configured agent wallet'))
            ->descriptionIcon('heroicon-o-circle-stack')
            ->chart($status['is_fake'] ? [1, 2, 1, 2, 1, 2] : [4, 7, 6, 9, 8, 12])
            ->color($status['is_fake'] ? 'warning' : ($bound ? 'success' : 'danger'));

        $runtimeStat = Stat::make('Background runtime', config('eduflow.background_finance.enabled') ? 'Enabled' : 'Disabled')
            ->description('Background planning is a supervised service. Nothing here submits, authorizes or settles a payment.')
            ->descriptionIcon('heroicon-o-cog-6-tooth')
            ->color(config('eduflow.background_finance.enabled') ? 'info' : 'gray')
            ->chart(config('eduflow.background_finance.enabled') ? [2, 4, 5, 7, 6, 8, 9] : [0, 0, 0, 0, 0])
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(FinanceDashboard::getUrl(panel: 'finance'));

        return [$identityStat, $singleStat, $onboardingStat, $railStat, $aiStat, $runtimeStat];
    }

    private function installedAt(): ?string
    {
        try {
            $settings = app(InstallationSettings::class);

            return $settings->initialized_at !== null
                ? Carbon::parse($settings->initialized_at)->toDateString()
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function features(): ?ApplicationFeaturesSettings
    {
        try {
            return app(ApplicationFeaturesSettings::class);
        } catch (Throwable) {
            return null;
        }
    }

    private function ai(): ?AiSettings
    {
        try {
            return app(AiSettings::class);
        } catch (Throwable) {
            return null;
        }
    }
}
