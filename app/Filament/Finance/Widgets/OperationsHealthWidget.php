<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Filament\Pages\PaymentReviews;
use App\Models\FinanceWorkflowRun;
use App\Models\FundingWindow;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the machinery behind this panel is actually running.
 *
 * Operational health is reported from recorded state — queue depth,
 * heartbeats, expiry, unread notifications, provider reachability — so a
 * supervisor can tell "nothing needs doing" apart from "nothing is running".
 * A stopped worker and an idle one look identical on every other panel.
 */
class OperationsHealthWidget extends BaseWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    /**
     * Eight stats, which divide evenly at four and two, so every tier is a
     * complete row. Container queries, not viewport breakpoints — see
     * ArcSettlementWidget. Eight across would mean 148px tiles, so the widest
     * tier is four.
     *
     * @var array<string, int>
     */
    protected int|array|null $columns = ['@5xl' => 4, '@2xl' => 2, 'default' => 1];

    #[\Override]
    public function getHeading(): ?string
    {
        return 'Operations health';
    }

    #[\Override]
    public function getDescription(): ?string
    {
        return 'Polling improves visibility only. An unread notification never approves, submits or releases a hold.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [Stat::make('Operations health', 'Not configured')
                ->description('Configure EDUFLOW_INSTITUTION_ID for a single institution')
                ->color('warning')];
        }

        $enabled = (bool) config('eduflow.background_finance.enabled');
        $connection = (string) config('queue.default', 'database');
        $queue = (string) config('queue.connections.'.$connection.'.queue', 'default');

        $runtimeStat = Stat::make('Background runtime', $enabled ? 'Enabled' : 'Disabled')
            ->description(sprintf('Planning queue "%s" on %s', $queue, $connection))
            ->color($enabled ? 'success' : 'warning');

        [$queueDepth, $queueOldest] = $this->queueDepth($queue, $connection);

        $queueStat = Stat::make('Queue depth', (string) $queueDepth)
            ->description($connection === 'database'
                ? ($queueOldest === null ? 'No queued work waiting' : 'Oldest waiting '.$queueOldest)
                : 'Depth is not observable on the '.$connection.' connection')
            // Depth is a number, not a state. It was `info`, so a busy queue read as
            // an alert beside the warning and danger tiles in the same band.
            ->color('gray');

        $heartbeat = FinanceWorkflowRun::query()
            ->where('organization_id', $institution->id)
            ->orderByDesc('heartbeat_at')
            ->first();

        $heartbeatStat = Stat::make('Worker heartbeat', $heartbeat?->heartbeat_at === null ? 'Never' : $heartbeat->heartbeat_at->diffForHumans())
            ->description($heartbeat?->heartbeat_at === null
                ? 'No worker has claimed a run on this installation'
                : sprintf('Run #%d last checked in %s UTC · %s', $heartbeat->id, $heartbeat->heartbeat_at->toDateTimeString(), $heartbeat->state))
            ->color($this->heartbeatColour($heartbeat));

        $staleWindows = FundingWindow::query()
            ->where('organization_id', $institution->id)
            ->get()
            ->filter(fn (FundingWindow $window): bool => $this->isExpired($window->snapshot['valid_until'] ?? null))
            ->count();

        $fundingStat = Stat::make('Stale funding evidence', (string) $staleWindows)
            ->description('Capacity expires with its block and never rolls over')
            ->color($staleWindows > 0 ? 'warning' : 'success');

        $user = Auth::user();
        $unread = $user instanceof User ? $user->unreadNotifications()->count() : 0;

        $notificationStat = Stat::make('Notifications awaiting you', (string) $unread)
            ->description('Persisted before the alert; reading one approves nothing')
            ->color('gray');

        $status = app(LeptonTreasuryService::class)->status($institution->primaryWallet());

        // The full driver error is a setup instruction — "run circle wallet login
        // <email> --testnet --init" — and ran to nine lines in a stat
        // caption. The caption says what is wrong; the exact command stays
        // reachable on hover rather than being truncated away.
        //
        // It also deliberately does not restate the driver and chain: the
        // ArcSettlement band owns both, and repeating them here had the two
        // bands saying the same thing twice on one screen. This tile answers
        // one question only — is the session usable.
        $providerStat = Stat::make('Provider session', $status['live_available'] ? 'Reachable' : 'Unavailable')
            ->description($status['live_available']
                ? 'Session established; signed chain reads are succeeding'
                : 'Not authenticated for this chain')
            ->color(match (true) {
                $status['is_fake'] => 'warning',
                $status['live_available'] => 'success',
                default => 'danger',
            });

        // $status['error'] is declared string|null by the gateway contract, so the
        // null-safe read was redundant; the '' check below is the one that
        // matters, because an empty string is not a useful tooltip.
        if (! $status['live_available'] && is_string($status['error']) && $status['error'] !== '') {
            $providerStat->extraAttributes(['title' => $status['error']]);
        }

        $unresolved = Transaction::query()
            ->where('organization_id', $institution->id)
            ->where('status', 'pending')
            ->count();

        $failed = Transaction::query()
            ->where('organization_id', $institution->id)
            ->where('metadata->reconciliation', 'failed')
            ->count();

        $outcomeStat = Stat::make('Unresolved payment outcomes', (string) ($unresolved + $failed))
            ->description(sprintf(
                '%d pending, %d failed. A null or timed-out lookup opens a case, never a blind re-send',
                $unresolved,
                $failed,
            ))
            ->color($unresolved + $failed > 0 ? 'warning' : 'success')
            ->url(PaymentReviews::getUrl(panel: 'finance'));

        return [$runtimeStat, $queueStat, $heartbeatStat, $fundingStat, $notificationStat, $providerStat, $outcomeStat];
    }

    /** @return array{int, ?string} */
    private function queueDepth(string $queue, string $connection): array
    {
        if ($connection !== 'database') {
            return [0, null];
        }

        try {
            $depth = DB::table('jobs')->where('queue', $queue)->count();

            if ($depth === 0) {
                return [0, null];
            }

            $oldest = DB::table('jobs')->where('queue', $queue)->min('created_at');

            return [$depth, $oldest === null ? null : Carbon::createFromTimestamp($oldest)->diffForHumans()];
        } catch (Throwable) {
            return [0, null];
        }
    }

    private function heartbeatColour(?FinanceWorkflowRun $heartbeat): string
    {
        if ($heartbeat?->heartbeat_at === null) {
            return 'gray';
        }

        $active = in_array($heartbeat->state, ['queued', 'running', 'waiting_for_review'], true);

        return $active && $heartbeat->heartbeat_at->diffInMinutes(now()->utc(), absolute: true) > 5 ? 'warning' : 'success';
    }

    private function isExpired(mixed $validUntil): bool
    {
        if (! is_string($validUntil) || $validUntil === '') {
            return false;
        }

        try {
            return Carbon::parse($validUntil)->isPast();
        } catch (Throwable) {
            return false;
        }
    }
}
