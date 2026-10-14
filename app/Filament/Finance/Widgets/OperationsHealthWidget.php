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
use Filament\Support\Icons\Heroicon;
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

    protected int|array|null $columns = 4;

    #[\Override]
    public function getHeading(): ?string
    {
        return 'Operations health';
    }

    #[\Override]
    public function getDescription(): ?string
    {
        return 'Background work must survive a closed browser. Polling this page improves visibility only; it never drives execution, and an unread notification never approves, submits, refreshes funding or releases a hold.';
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
        $queue = (string) config('eduflow.background_finance.queue');
        $connection = (string) config('eduflow.background_finance.queue_connection');

        $runtimeStat = Stat::make('Background runtime', $enabled ? 'Enabled' : 'Disabled')
            ->description(sprintf('%s planning queue "%s" on the %s connection. Payment submission uses a separate runtime and queue.', $enabled ? 'Durable' : 'Off —', $queue, $connection))
            ->descriptionIcon(Heroicon::Cog6Tooth)
            ->color($enabled ? 'success' : 'warning');

        [$queueDepth, $queueOldest] = $this->queueDepth($queue, $connection);

        $queueStat = Stat::make('Queue depth', (string) $queueDepth)
            ->description($connection === 'database'
                ? ($queueOldest === null ? 'No queued work waiting' : 'Oldest waiting '.$queueOldest)
                : 'Depth is not observable on the '.$connection.' connection')
            ->descriptionIcon(Heroicon::QueueList)
            ->color($queueDepth > 0 ? 'info' : 'gray');

        $heartbeat = FinanceWorkflowRun::query()
            ->where('organization_id', $institution->id)
            ->orderByDesc('heartbeat_at')
            ->first();

        $heartbeatStat = Stat::make('Worker heartbeat', $heartbeat?->heartbeat_at === null ? 'Never' : $heartbeat->heartbeat_at->diffForHumans())
            ->description($heartbeat?->heartbeat_at === null
                ? 'No worker has claimed a run on this installation'
                : sprintf('Run #%d last checked in %s UTC · %s', $heartbeat->id, $heartbeat->heartbeat_at->toDateTimeString(), $heartbeat->state))
            ->descriptionIcon(Heroicon::OutlinedHeart)
            ->color($this->heartbeatColour($heartbeat));

        $staleWindows = FundingWindow::query()
            ->where('organization_id', $institution->id)
            ->get()
            ->filter(fn (FundingWindow $window): bool => $this->isExpired($window->snapshot['valid_until'] ?? null))
            ->count();

        $fundingStat = Stat::make('Stale funding evidence', (string) $staleWindows)
            ->description('Windows hold block-bound capacity for at most fifteen minutes and do not roll over unattended. Expiry is never silently refreshed.')
            ->descriptionIcon(Heroicon::ExclamationTriangle)
            ->color($staleWindows > 0 ? 'warning' : 'success');

        $user = Auth::user();
        $unread = $user instanceof User ? $user->unreadNotifications()->count() : 0;

        $notificationStat = Stat::make('Notifications awaiting you', (string) $unread)
            ->description('Work items are persisted before an alert is sent, so a failed delivery never loses the task. Reading or dismissing one approves nothing.')
            ->descriptionIcon(Heroicon::BellAlert)
            ->color($unread > 0 ? 'info' : 'gray');

        $status = app(LeptonTreasuryService::class)->status($institution->primaryWallet());

        $providerStat = Stat::make('Provider session', $status['live_available'] ? 'Reachable' : 'Unavailable')
            ->description($status['live_available']
                ? sprintf('%s driver · %s chain %s · block %s', $status['driver'], strtoupper((string) $status['chain']), (string) $status['chain_id'], number_format((float) ($status['block'] ?? 0)))
                : ($status['error'] ?? 'Circle session is not established for this chain'))
            ->descriptionIcon(Heroicon::Signal)
            ->color(match (true) {
                $status['is_fake'] => 'warning',
                $status['live_available'] => 'success',
                default => 'danger',
            });

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
                '%d pending · %d failed reconciliation. Null or timed-out lookups stay unresolved and open an investigation case; they are never re-submitted blindly or declared fabricated.',
                $unresolved,
                $failed,
            ))
            ->descriptionIcon(Heroicon::MagnifyingGlass)
            ->color($unresolved + $failed > 0 ? 'warning' : 'success')
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
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
