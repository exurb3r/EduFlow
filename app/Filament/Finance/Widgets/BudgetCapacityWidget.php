<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Support\LinkedHeading;
use App\Models\BudgetSnapshot;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Services\InstallationInstitution;
use Brick\Math\BigInteger;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Approved allocation against realized local cash, kept as two separate
 * figures, plus the capacity currently held by reservations.
 *
 * Allocation is not cash and a referral rate is not a balance, so the three
 * numbers never collapse into one "available" total. Held capacity is
 * application-side bookkeeping only: nothing external is locked.
 */
class BudgetCapacityWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, string>|null Memoized held base units keyed by budget id. */
    private ?array $heldByBudget = null;

    private const string HEADING = 'Approved allocation, realized cash and held capacity';

    private const string DESCRIPTION = 'Latest captured budget evidence per department plan. Allocation and cash headroom are computed separately from the same exact snapshot. Held capacity counts outstanding application reservations including their fee ceiling; it does not lock external funds and does not mean a payment is approved.';

    #[\Override]
    protected function getTableQuery(): Builder
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return BudgetSnapshot::query()->whereRaw('1 = 0');
        }

        return BudgetSnapshot::query()
            ->where('organization_id', $institution->id)
            ->whereIn('id', BudgetSnapshot::query()
                ->selectRaw('MAX(id)')
                ->where('organization_id', $institution->id)
                ->groupBy('budget_id'))
            ->with('budget');
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading(LinkedHeading::make(self::HEADING, FinanceSupervisor::getUrl(panel: 'finance')))
            ->description(self::DESCRIPTION)
            ->striped()
            ->columns([
                TextColumn::make('id')->label('Snapshot')->prefix('#'),
                TextColumn::make('department')->label('Department')
                    ->state(fn (BudgetSnapshot $record): string => (string) ($record->snapshot['department']['name'] ?? 'Unnamed'))
                    ->description(fn (BudgetSnapshot $record): string => sprintf(
                        '%s → %s',
                        (string) ($record->snapshot['department']['period_start'] ?? '—'),
                        (string) ($record->snapshot['department']['period_end'] ?? '—'),
                    ))
                    ->searchable(),
                TextColumn::make('evidence_mode')->label('Evidence')
                    ->badge()
                    ->state(fn (BudgetSnapshot $record): string => in_array($record->snapshot['schema_version'] ?? null, [2, 3], true)
                        ? 'Reviewed collections'
                        : 'Legacy attestation')
                    ->color(fn (BudgetSnapshot $record): string => in_array($record->snapshot['schema_version'] ?? null, [2, 3], true) ? 'success' : 'warning')
                    ->tooltip(fn (BudgetSnapshot $record): string => in_array($record->snapshot['schema_version'] ?? null, [2, 3], true)
                        ? 'Bound to independently reviewed collection batches.'
                        : 'Schema v1 staff attestation. Readable, but cannot open a funding window.'),
                TextColumn::make('validity')->label('Evidence window')
                    ->state(fn (BudgetSnapshot $record): string => (string) ($record->snapshot['valid_until'] ?? 'Not recorded'))
                    ->description(fn (BudgetSnapshot $record): string => 'As of '.(($record->snapshot['as_of'] ?? '—')))
                    ->color(fn (BudgetSnapshot $record): string => $this->freshness($record) === 'expired' ? 'danger' : 'gray'),
                $this->amountColumn('allocation', 'Approved allocation'),
                $this->amountColumn('already_spent', 'Already spent')
                    ->toggleable(isToggledHiddenByDefault: true),
                $this->amountColumn('other_budget_commitments', 'Other commitments')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('budget_headroom')->label('Budget headroom')->alignEnd()
                    ->state(fn (BudgetSnapshot $record): string => Money::formatExact($this->headroom($record)['budget_minor_units'] ?? null, $record->currency))
                    ->color(fn (BudgetSnapshot $record): string => $this->headroomColour($record, 'budget_minor_units')),
                $this->amountColumn('restricted_cash', 'Restricted cash')
                    ->toggleable(isToggledHiddenByDefault: true),
                $this->amountColumn('protected_reserve', 'Protected reserve')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cash_headroom')->label('Realized cash headroom')->alignEnd()
                    ->state(fn (BudgetSnapshot $record): string => Money::formatExact($this->headroom($record)['cash_minor_units'] ?? null, $record->currency))
                    ->color(fn (BudgetSnapshot $record): string => $this->headroomColour($record, 'cash_minor_units')),
                TextColumn::make('held')->label('Held capacity (USDC)')->alignEnd()
                    ->state(fn (BudgetSnapshot $record): string => Money::formatExact($this->held($record->budget_id), 'USDC'))
                    ->description('Application reservations + fee ceiling; external funds not locked'),
                TextColumn::make('integrity')->label('Snapshot')
                    ->state(fn (BudgetSnapshot $record): string => $record->hasValidSnapshot() ? 'Intact' : 'Digest mismatch')
                    ->badge()
                    ->color(fn (BudgetSnapshot $record): string => $record->hasValidSnapshot() ? 'success' : 'danger'),
            ])
            ->recordActions([
                Action::make('reviewPlan')
                    ->label('Review')
                    ->icon(Heroicon::ArrowRightCircle)
                    ->color('primary')
                    ->url(fn (): string => FinanceSupervisor::getUrl(panel: 'finance')),
            ])
            ->defaultSort('id', 'desc');
    }

    private function amountColumn(string $field, string $label): TextColumn
    {
        return TextColumn::make($field)->label($label)->alignEnd()
            ->state(fn (BudgetSnapshot $record): string => Money::formatExact(data_get($record->snapshot, 'amounts.'.$field), $record->currency))
            ->color('gray');
    }

    /**
     * Invalid evidence must never be rendered as a headroom figure, so the
     * planner's own exception is translated into an absence.
     *
     * @return array<string, string>|array{}
     */
    private function headroom(BudgetSnapshot $record): array
    {
        return $record->hasValidSnapshot() ? $record->headroom() : [];
    }

    private function headroomColour(BudgetSnapshot $record, string $key): string
    {
        $headroom = $this->headroom($record);

        if ($headroom === []) {
            return 'danger';
        }

        return $this->isNegative($headroom[$key]) ? 'danger' : 'success';
    }

    private function freshness(BudgetSnapshot $record): string
    {
        $validUntil = data_get($record->snapshot, 'valid_until');

        if (! is_string($validUntil) || $validUntil === '') {
            return 'unknown';
        }

        try {
            return Carbon::parse($validUntil)->isPast() ? 'expired' : 'current';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function isNegative(string $units): bool
    {
        return BigInteger::of($units)->isNegative();
    }

    /**
     * Cumulative holds are summed outside the Money DTO, so they are kept as
     * exact strings and can outrun a signed integer without a float detour.
     */
    private function held(?int $budgetId): ?string
    {
        if ($budgetId === null) {
            return null;
        }

        if ($this->heldByBudget === null) {
            $this->heldByBudget = $this->resolveHeldByBudget();
        }

        return $this->heldByBudget[$budgetId] ?? '0';
    }

    /** @return array<int, string> */
    private function resolveHeldByBudget(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [];
        }

        $intentBudgets = PaymentIntent::query()
            ->select(['id', 'budget_id'])
            ->where('organization_id', $institution->id)
            ->pluck('budget_id', 'id');

        $holds = PaymentReservation::query()
            ->selectRaw('payment_intent_id, COALESCE(SUM(amount_base_units + max_fee_base_units), 0) AS held_units')
            ->where('organization_id', $institution->id)
            ->whereIn('payment_intent_id', $intentBudgets->keys())
            ->groupBy('payment_intent_id')
            ->pluck('held_units', 'payment_intent_id');

        $held = [];

        foreach ($holds as $intentId => $heldUnits) {
            $budgetId = $intentBudgets->get($intentId);

            if ($budgetId === null) {
                continue;
            }

            $key = (int) $budgetId;
            $held[$key] = (string) BigInteger::of($held[$key] ?? '0')->plus(BigInteger::of((string) $heldUnits));
        }

        return $held;
    }
}
