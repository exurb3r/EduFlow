<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\Collections;
use App\Filament\Support\LinkedHeading;
use App\Models\CollectionBatch;
use App\Services\InstallationInstitution;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Cashier intake position, grouped by currency and declared source stream.
 *
 * Deliberately reports what evidence exists rather than a spendable balance.
 * An unreviewed batch is recorded money, not cash: the planner refuses to
 * treat it as available, and this panel must not imply otherwise by
 * subtracting everything into one reassuring number.
 */
class CollectionsOverviewWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    private const string HEADING = 'Collections by source and currency';

    private const string DESCRIPTION = 'Reported amounts are staff-recorded aggregates. Only independently reviewed batches can fund a plan, so there is deliberately no available-balance total here. Suspense and reversal tracking are not yet implemented, so unmatched or reversed money is reported inside "Reported" rather than as a separate bucket.';

    #[\Override]
    protected function getTableQuery(): Builder
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return CollectionBatch::query()->whereRaw('1 = 0');
        }

        return CollectionBatch::query()
            ->where('collection_batches.organization_id', $institution->id)
            ->leftJoin('collection_batch_reviews', 'collection_batch_reviews.collection_batch_id', '=', 'collection_batches.id')
            ->select([
                'collection_batches.currency',
                'collection_batches.source_stream',
            ])
            ->selectRaw('COUNT(*) AS batches_count')
            ->selectRaw('COALESCE(SUM(collection_batches.received_minor_units), 0) AS reported_minor_units')
            ->selectRaw('COALESCE(SUM(collection_batches.restricted_minor_units), 0) AS restricted_minor_units')
            ->selectRaw('COALESCE(SUM(CASE WHEN collection_batch_reviews.decision = \'approve_receipts\' THEN collection_batches.received_minor_units ELSE 0 END), 0) AS approved_minor_units')
            ->selectRaw('COALESCE(SUM(CASE WHEN collection_batch_reviews.decision = \'hold\' THEN collection_batches.received_minor_units ELSE 0 END), 0) AS held_minor_units')
            ->selectRaw('COALESCE(SUM(CASE WHEN collection_batch_reviews.decision = \'reject\' THEN collection_batches.received_minor_units ELSE 0 END), 0) AS rejected_minor_units')
            ->selectRaw('COALESCE(SUM(CASE WHEN collection_batch_reviews.id IS NULL THEN collection_batches.received_minor_units ELSE 0 END), 0) AS unreviewed_minor_units')
            ->groupBy('collection_batches.currency', 'collection_batches.source_stream')
            ->orderBy('collection_batches.currency')
            ->orderBy('collection_batches.source_stream');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading(LinkedHeading::make(self::HEADING, Collections::getUrl(panel: 'finance')))
            ->description(self::DESCRIPTION)
            ->striped()
            ->columns([
                TextColumn::make('currency')->label('Currency')->badge()->sortable(),
                TextColumn::make('source_stream')->label('Source stream')->wrap()->sortable(),
                TextColumn::make('batches_count')->label('Batches')->alignEnd()->sortable(),
                $this->unitsColumn('reported_minor_units', 'Reported'),
                $this->unitsColumn('restricted_minor_units', 'Restricted'),
                TextColumn::make('unrestricted_minor_units')->label('Unrestricted')->alignEnd()
                    ->state(fn (object $record): string => Money::formatExact(
                        max(0, (int) $record->reported_minor_units - (int) $record->restricted_minor_units),
                        $record->currency,
                    )),
                $this->unitsColumn('approved_minor_units', 'Reviewed'),
                $this->unitsColumn('unreviewed_minor_units', 'Awaiting review')
                    ->color(fn (object $record): string => (int) $record->unreviewed_minor_units > 0 ? 'warning' : 'gray'),
                $this->unitsColumn('held_minor_units', 'Held')
                    ->color(fn (object $record): string => (int) $record->held_minor_units > 0 ? 'warning' : 'gray'),
                $this->unitsColumn('rejected_minor_units', 'Rejected')
                    ->color(fn (object $record): string => (int) $record->rejected_minor_units > 0 ? 'danger' : 'gray'),
            ])
            ->defaultSort('currency')
            ->paginated(false);
    }

    /**
     * Rows here are grouped aggregates, so there is no primary key to fall
     * back on. The currency and declared stream together identify the row,
     * and that pair is exactly what the grouping is keyed on.
     *
     * @param  Model|array<string, mixed>  $record
     */
    #[\Override]
    public function getTableRecordKey(Model|array $record): string
    {
        if ($record instanceof Model) {
            return $record->getAttribute('currency').'|'.$record->getAttribute('source_stream');
        }

        return $record['currency'].'|'.$record['source_stream'];
    }

    private function unitsColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->alignEnd()
            ->state(fn (object $record): string => Money::formatExact($record->{$name}, $record->currency))
            ->sortable();
    }
}
