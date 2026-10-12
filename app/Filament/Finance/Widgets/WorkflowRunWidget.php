<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Support\LinkedHeading;
use App\Models\FinanceWorkflowRun;
use App\Services\InstallationInstitution;
use Carbon\CarbonInterface;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Durable background work, shown as run state rather than as activity.
 *
 * A heartbeat proves a worker passed; it does not prove a payment happened,
 * and a queued run is not an executed one. Rows stay visible after
 * completion so a supervisor can see what the scheduler actually did while
 * nobody was watching.
 */
class WorkflowRunWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /** A worker that has not checked in for this long is reported as stale. */
    private const int STALE_HEARTBEAT_MINUTES = 5;

    /** @var array<string, string> Logical worker names for the delivered task kinds. */
    private const array WORKERS = [
        'budget_plan' => 'Budget planner',
        'collection_review' => 'Collections observer',
    ];

    private const string HEADING = 'Background finance work';

    private const string DESCRIPTION = 'Run state is durable and visible whether or not anyone is watching. Seeing a run here is not seeing a payment: no run in this system submits, authorizes or settles a transfer.';

    #[\Override]
    protected function getTableQuery(): Builder
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return FinanceWorkflowRun::query()->whereRaw('1 = 0');
        }

        return FinanceWorkflowRun::query()
            ->where('organization_id', $institution->id)
            ->with(['budgetSnapshot', 'collectionBatch']);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading(LinkedHeading::make(self::HEADING, FinanceSupervisor::getUrl(panel: 'finance')))
            ->description(self::DESCRIPTION)
            ->striped()
            ->poll('15s')
            ->columns([
                TextColumn::make('id')->label('Run')->prefix('#')->sortable(),
                TextColumn::make('kind')->label('Worker')
                    ->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => self::WORKERS[$state] ?? $state)
                    ->description(fn (FinanceWorkflowRun $record): string => 'Purpose: '.($record->result === null ? 'no result yet' : 'planning result recorded')),
                TextColumn::make('state')->label('State')->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'waiting_for_review' => 'Waiting for review',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'running' => 'info',
                        'waiting_for_review', 'blocked', 'paused' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('trigger_key')->label('Triggering event')->limit(34)->wrap()
                    ->tooltip(fn (FinanceWorkflowRun $record): string => $record->trigger_key),
                TextColumn::make('affected_document')->label('Affected document')
                    ->state(fn (FinanceWorkflowRun $record): string => $this->affectedDocument($record))
                    ->badge()->color('gray'),
                TextColumn::make('attempts')->label('Attempts')->alignEnd()->sortable(),
                TextColumn::make('heartbeat_at')->label('Last heartbeat (UTC)')
                    ->dateTime('Y-m-d H:i:s', 'UTC')
                    ->placeholder('Never')
                    ->sortable()
                    ->color(fn (FinanceWorkflowRun $record): string => $this->heartbeatState($record)),
                TextColumn::make('next_attempt_at')->label('Next run (UTC)')
                    ->dateTime('Y-m-d H:i:s', 'UTC')
                    ->placeholder('Not scheduled')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error')->label('Actionable error')->limit(80)->wrap()
                    ->placeholder('None')
                    ->color(fn (FinanceWorkflowRun $record): string => $record->last_error === null ? 'gray' : 'danger')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([5, 10, 25]);
    }

    private function affectedDocument(FinanceWorkflowRun $record): string
    {
        if ($record->budget_snapshot_id !== null) {
            return 'Budget snapshot #'.$record->budget_snapshot_id;
        }

        if ($record->collection_batch_id !== null) {
            return 'Collection batch #'.$record->collection_batch_id;
        }

        return 'Unbound';
    }

    private function heartbeatState(FinanceWorkflowRun $record): string
    {
        if ($record->heartbeat_at === null) {
            return $record->state === 'queued' ? 'gray' : 'warning';
        }

        $active = in_array($record->state, ['queued', 'running', 'waiting_for_review'], true);

        return $active && $this->staleMinutes($record->heartbeat_at) > self::STALE_HEARTBEAT_MINUTES ? 'warning' : 'gray';
    }

    private function staleMinutes(CarbonInterface $heartbeat): float
    {
        try {
            return $heartbeat->diffInMinutes(now()->utc(), absolute: true);
        } catch (Throwable) {
            return 0.0;
        }
    }
}
