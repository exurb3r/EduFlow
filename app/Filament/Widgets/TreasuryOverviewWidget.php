<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Collections;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\PaymentReviews;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\FinanceWorkflowRun;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\Transaction;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How far the institution has actually progressed, in records rather than money.
 *
 * The legacy version summed float invoice amounts and called it "scheduled
 * obligations", then reported AI autonomy counts as if a payment lane were
 * running. Neither survives contact with the pilot contract: allocation is
 * not cash, and nothing here has settled anything. Progress is therefore
 * counted as evidence recorded and decisions resolved.
 */
class TreasuryOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /**
     * Five stats, so a five-across desktop row: three columns would leave a
     * ragged 3 + 2 with a hole in the last row, which reads as a layout bug.
     *
     * @var array<string, int>
     */
    /**
     * Five across from `xl` up, where a tile is wide enough for these short
     * figures. Below that the band steps down rather than squeezing five
     * columns into a tablet.
     *
     * @var array<string, int>
     */
    protected int|array|null $columns = ['@xl' => 5, '@lg' => 3, '@md' => 2, 'default' => 1];

    #[\Override]
    public function getHeading(): ?string
    {
        return 'Money posture';
    }

    #[\Override]
    public function getDescription(): ?string
    {
        return 'Progress measured in records, not balances. Exact amounts live in the finance panel.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [Stat::make('Money posture', 'Not configured')
                ->description('Configure EDUFLOW_INSTITUTION_ID for a single institution')
                ->color('warning')];
        }

        $institutionId = $institution->id;
        $wallet = $institution->primaryWallet();
        $status = app(LeptonTreasuryService::class)->status($wallet);

        $ledgerStat = Stat::make(
            'Ledger balance',
            $wallet === null ? 'No treasury' : number_format((float) $wallet->balance, 2).' USDC',
        )
            ->description('Local ledger only — not on-chain funds, and it never settles a payable.')
            ->color($status['in_sync'] === false ? 'warning' : 'gray')
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(TransactionResource::getUrl('index'));

        $collections = CollectionBatch::query()->where('organization_id', $institutionId)->count();
        $reviewed = CollectionBatch::query()->where('organization_id', $institutionId)
            ->whereHas('reviews', fn ($query) => $query->where('decision', 'approve_receipts'))
            ->count();
        $snapshots = BudgetSnapshot::query()->where('organization_id', $institutionId)->count();

        $evidenceStat = Stat::make('Exact evidence records', (string) ($collections + $snapshots))
            ->description(sprintf(
                '%d collection batches (%d reviewed) · %d budget snapshots',
                $collections,
                $reviewed,
                $snapshots,
            ))
            ->color('gray')
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(Collections::getUrl(panel: 'finance'));

        PaymentIntent::query()->where('organization_id', $institutionId)->count();
        PaymentReservation::query()->where('organization_id', $institutionId)->count();
        $authorizations = PaymentAuthorization::query()->where('organization_id', $institutionId)
            ->get(['decision'])
            ->countBy(fn (PaymentAuthorization $authorization): string => $authorization->decision);

        $decisionStat = Stat::make(
            'Resolved payment decisions',
            (string) $authorizations->sum(),
        )
            ->description(sprintf(
                '%d approved · %d rejected · %d held — authority, not settlement',
                $authorizations->get('approve_payment', 0),
                $authorizations->get('reject_payment', 0),
                $authorizations->get('hold_payment', 0),
            ))
            ->color('gray')
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(PaymentReviews::getUrl(panel: 'finance'));

        $states = FinanceWorkflowRun::query()
            ->where('organization_id', $institutionId)
            ->get(['state'])
            ->countBy(fn (FinanceWorkflowRun $run): string => $run->state);

        $waiting = ($states->get('waiting_for_review', 0) + $states->get('blocked', 0) + $states->get('failed', 0));

        $supervisionStat = Stat::make('Supervision backlog', (string) $waiting)
            ->description(sprintf(
                '%d waiting or blocked · %d completed',
                $waiting,
                $states->get('completed', 0),
            ))
            ->color($waiting > 0 ? 'warning' : 'success')
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(FinanceDashboard::getUrl(panel: 'finance'));

        $pending = Transaction::query()
            ->where('organization_id', $institutionId)->where('status', 'pending')->count();

        $settlementStat = Stat::make('Settlement status', 'Not executed')
            ->description(sprintf(
                '%d pending · a stored hash is a claim, not proof of payment',
                $pending,
            ))
            ->color('gray')
            ->extraAttributes(['class' => 'cursor-pointer hover:shadow-sm transition-shadow'])
            ->url(TransactionResource::getUrl('index'));

        return [$ledgerStat, $evidenceStat, $decisionStat, $supervisionStat, $settlementStat];
    }
}
