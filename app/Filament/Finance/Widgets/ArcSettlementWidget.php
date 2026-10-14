<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\PaymentReviews;
use App\Models\FundingWindow;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Observed Arc evidence, kept visually and numerically separate from local cash.
 *
 * Everything here is a read of the rail, not a spendable balance: a funding
 * window holds block-bound capacity for at most fifteen minutes and every
 * record carries whether it came from the fake driver. A stale window shows
 * as stale rather than being quietly refreshed.
 */
class ArcSettlementWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = 4;

    /** Minutes after which a funding observation stops being usable evidence. */
    private const int STALE_AFTER_MINUTES = 15;

    #[\Override]
    public function getHeading(): ?string
    {
        return 'Observed Arc settlement rail';
    }

    #[\Override]
    public function getDescription(): ?string
    {
        return 'Block-bound observations read through Lepton. These are chain facts about the institution wallet, not local-currency receipts and not an approved payment balance. Native Arc USDC is an 18-decimal gas quantity; ERC-20 and native interfaces expose the same balance.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [Stat::make('Arc settlement rail', 'Not configured')
                ->description('Configure EDUFLOW_INSTITUTION_ID for a single institution')
                ->color('warning')];
        }

        $window = FundingWindow::query()
            ->where('organization_id', $institution->id)
            ->orderByDesc('id')
            ->first();

        $balance = is_array($window?->snapshot['balance'] ?? null) ? $window->snapshot['balance'] : [];
        $isFake = $balance['is_fake'] ?? null;
        $observedAt = $balance['observed_at'] ?? null;
        $validUntil = $window?->snapshot['valid_until'] ?? null;
        $age = $this->ageInSeconds($observedAt);

        $usdcStat = Stat::make(
            'Observed USDC balance',
            isset($balance['usdc_base_units']) ? Money::formatExact($balance['usdc_base_units'], 'USDC') : 'Not observed',
        )
            ->description($observedAt !== null
                ? sprintf('Block %s · observed %s UTC', (string) ($balance['block_number'] ?? '—'), $observedAt)
                : 'No reviewed funding window has observed this wallet yet')
            ->descriptionIcon(Heroicon::Banknotes)
            ->color($isFake === true ? 'warning' : ($balance !== [] ? 'success' : 'gray'));

        if ($isFake === true) {
            $usdcStat->description('FAKE DRIVER — simulation only, no real USDC exists here · observed '.$observedAt);
        }

        $nativeStat = Stat::make(
            'Native Arc balance (gas)',
            isset($balance['native_units']) ? $this->formatNative($balance['native_units']) : 'Not observed',
        )
            ->description('On Arc, USDC pays the fee — an empty wallet cannot send anything, including a zero-value transfer')
            ->descriptionIcon(Heroicon::Bolt)
            ->color($balance !== [] ? 'info' : 'gray');

        $status = app(LeptonTreasuryService::class)->status($institution->primaryWallet());
        $chain = strtoupper($status['chain'] === '' ? 'ARC-TESTNET' : $status['chain']);

        $chainStat = Stat::make('Chain', $chain)
            ->description($status['live_available']
                ? sprintf('Chain id %s · block %s · %s', (string) $status['chain_id'], number_format((float) ($status['block'] ?? 0)), (string) ($status['rpc_host'] ?? 'rpc'))
                : 'Live chain reads unavailable')
            ->descriptionIcon(Heroicon::GlobeAlt)
            ->color($status['live_available'] ? ($chain === 'ARC' ? 'danger' : 'success') : 'gray');

        if ($status['address_url'] !== null) {
            $chainStat->url($status['address_url'], shouldOpenInNewTab: true)
                ->description('Opens the block explorer for the institution wallet');
        }

        if ($chain === 'ARC') {
            $chainStat->description('MAINNET — the pilot executes on ARC-TESTNET only; no implicit fallback exists');
        }

        $driverStat = Stat::make('Execution driver', $status['driver'] === '' ? 'unknown' : $status['driver'])
            ->description($status['is_fake']
                ? 'Fake gateway: no wallet, no chain, no real funds'
                : 'Circle Agent Wallet through Lepton')
            ->descriptionIcon(Heroicon::CommandLine)
            ->color($status['is_fake'] ? 'warning' : 'primary');

        // Colour follows the same four states as the label, decided once here so
        // the wording and the colour can never disagree.
        $evidenceState = match (true) {
            $window === null => 'no evidence',
            $age === null => 'unreadable',
            $age > self::STALE_AFTER_MINUTES * 60 => 'stale',
            default => 'current',
        };

        $evidenceColour = match ($evidenceState) {
            'current' => 'success',
            'stale' => 'warning',
            default => 'gray',
        };

        $evidenceStat = Stat::make('Funding evidence', $window === null ? 'None captured' : ucfirst($evidenceState))
            ->description($window === null
                ? 'Capture and independently review a budget snapshot to open a funding window'
                : sprintf(
                    'Window #%d · valid until %s UTC · capacity %s budget / %s cash',
                    $window->id,
                    is_string($validUntil) ? $validUntil : '—',
                    Money::formatExact(data_get($window->snapshot, 'capacity.budget_base_units'), 'USDC'),
                    Money::formatExact(data_get($window->snapshot, 'capacity.cash_base_units'), 'USDC'),
                ))
            ->descriptionIcon(Heroicon::Clock)
            ->color($evidenceColour)
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(PaymentReviews::getUrl(panel: 'finance'));

        return [$usdcStat, $nativeStat, $chainStat, $evidenceStat, $driverStat];
    }

    /**
     * Arc native USDC is an 18-decimal integer quantity that exceeds
     * PHP_INT_MAX at ordinary balances, so it is rendered from the exact
     * string with arbitrary precision rather than cast to a float.
     */
    private function formatNative(mixed $units): string
    {
        if (! is_string($units) || preg_match('/^\d+$/D', $units) !== 1) {
            return 'Exact amount unavailable';
        }

        return (string) BigDecimal::ofUnscaledValue(BigInteger::of($units), 18);
    }

    private function ageInSeconds(mixed $timestamp): ?int
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return (int) Carbon::parse($timestamp)->diffInSeconds(now()->utc(), absolute: true);
        } catch (Throwable) {
            return null;
        }
    }
}
