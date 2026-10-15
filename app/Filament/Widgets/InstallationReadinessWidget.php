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

    /**
     * Three across, not six.
     *
     * Six left roughly 180px of content per tile, which is narrower than the
     * word "Impersonation" — so values broke mid-word, and every tile in the
     * row stretched to the tallest while the rest sat on dead space. Three
     * gives each tile enough room for the longest value to wrap at a space,
     * and divides six stats into two even rows instead of a ragged remainder.
     *
     * @var array<string, int>
     */
    protected int|array|null $columns = ['@lg' => 3, '@md' => 2, 'default' => 1];

    #[\Override]
    public function getHeading(): ?string
    {
        return 'Installation readiness';
    }

    #[\Override]
    public function getDescription(): ?string
    {
        // NB: the separator here was a U+FFFD replacement character, not a
        // dash — a corrupted byte had been written to disk and rendered as
        // a diamond on the page. Kept as a real em dash.
        return 'One institution per installation — this is not multi-tenancy. Money moves in the finance panel, never here.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [Stat::make('Installation', 'Not configured')
->description('Run php artisan eduflow:install. Context fails closed.')
                ->color('danger')];
        }

        $installed = $this->installedAt();
        $organizationCount = Organization::query()->count();

        $identityStat = Stat::make('Institution', $institution->name)
            ->description($installed === null
                ? sprintf('%s · %s · %s', $institution->currency, app()->getLocale(), config('app.timezone'))
                : sprintf('Initialised %s · %s', $installed, $institution->currency))
            ->color('gray')
            ->extraAttributes(['class' => 'cursor-pointer'])
            ->url(ApplicationDetailsSettingsPage::getUrl());

        $singleStat = Stat::make('Institution boundary', $organizationCount === 1 ? 'Single institution' : $organizationCount.' organizations')
            ->description($organizationCount === 1
                ? 'Exactly one institution is permitted on this profile.'
                : 'Ambiguous context. Dashboards and settlement refuse to guess.')
            ->color($organizationCount === 1 ? 'success' : 'danger')
            ->extraAttributes(['class' => 'cursor-pointer'])
            ->url(ApplicationDetailsSettingsPage::getUrl());

        $features = $this->features();
        $openGates = array_keys(array_filter([
            'Registration' => $features instanceof ApplicationFeaturesSettings && $features->registration_enabled,
            'Impersonation' => $features instanceof ApplicationFeaturesSettings && $features->user_impersonation_enabled,
        ]));

        // The value already names whichever gates are open, so the caption
        // states the policy instead of listing them a second time.
        $onboardingStat = Stat::make('School onboarding', $openGates === [] ? 'Locked down' : 'Open: '.implode(', ', $openGates))
            ->description('Off unless a school widens them deliberately.')
            ->color($openGates === [] ? 'success' : 'warning')
            ->extraAttributes(['class' => 'cursor-pointer'])
            ->url(ApplicationFeaturesSettingsPage::getUrl());

        $ai = $this->ai();

        $aiStat = Stat::make('Advisory AI', $ai instanceof AiSettings && $ai->advisory_enabled ? 'Enabled' : 'Off by default')
            ->description(sprintf(
                '%d active provider(s) · disclosure %s',
                AiProvider::query()->where('is_active', true)->count(),
                $ai instanceof AiSettings && $ai->disclosure_accepted ? 'accepted' : 'not accepted',
            ))
            ->color($ai instanceof AiSettings && $ai->advisory_enabled ? 'success' : 'gray')
            ->extraAttributes(['class' => 'cursor-pointer'])
            ->url(AiProviderResource::getUrl('index'));

        $status = app(LeptonTreasuryService::class)->status($institution->primaryWallet());
        $wallet = $institution->primaryWallet();

        $bound = $wallet !== null
            && $status['treasury_address'] !== null
            && strcasecmp($status['treasury_address'], $wallet->address) === 0;

        $railStat = Stat::make('Settlement rail', $status['driver'].' · '.strtoupper((string) $status['chain']))
            ->description($status['is_fake']
                ? 'Fake driver — no chain, no real funds.'
                : ($bound ? 'Treasury bound to the agent wallet' : 'Treasury address does not match the agent wallet'))
            ->color($status['is_fake'] ? 'warning' : ($bound ? 'success' : 'danger'));

        $runtimeStat = Stat::make('Background runtime', config('eduflow.background_finance.enabled') ? 'Enabled' : 'Disabled')
            ->description('Supervised service. Nothing here settles a payment.')
            ->color(config('eduflow.background_finance.enabled') ? 'success' : 'gray')
            ->extraAttributes(['class' => 'cursor-pointer'])
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
