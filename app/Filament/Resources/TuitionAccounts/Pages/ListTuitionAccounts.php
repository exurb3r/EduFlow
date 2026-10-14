<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\Pages;

use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Filament\Resources\TuitionAccounts\Widgets\TuitionAccountsStatsWidget;
use App\Models\AcademicTerm;
use App\Models\TuitionAccount;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListTuitionAccounts extends ListRecords
{
    protected static string $resource = TuitionAccountResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Open tuition account')
                ->icon(Heroicon::PlusCircle),
        ];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            TuitionAccountsStatsWidget::class,
        ];
    }

    /**
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        $activeTerm = AcademicTerm::query()
            ->where('starts_on', '<=', today())
            ->where('ends_on', '>=', today())
            ->first();

        $tabs = [
            'all' => Tab::make('All Accounts')
                ->icon(Heroicon::Banknotes)
                ->badge(fn (): int => TuitionAccount::query()->count()),

            'outstanding' => Tab::make('Outstanding Balance')
                ->icon(Heroicon::ExclamationTriangle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereColumn('total_amount', '>', 'paid_amount'))
                ->badge(fn (): int => TuitionAccount::query()->whereColumn('total_amount', '>', 'paid_amount')->count())
                ->badgeColor('danger'),

            'settled' => Tab::make('Fully Settled')
                ->icon(Heroicon::CheckBadge)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereColumn('total_amount', '<=', 'paid_amount'))
                ->badge(fn (): int => TuitionAccount::query()->whereColumn('total_amount', '<=', 'paid_amount')->count())
                ->badgeColor('success'),
        ];

        if ($activeTerm) {
            $tabs['active_term'] = Tab::make("Current: {$activeTerm->name}")
                ->icon(Heroicon::CalendarDays)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('academic_term_id', $activeTerm->id))
                ->badge(fn (): int => TuitionAccount::query()->where('academic_term_id', $activeTerm->id)->count())
                ->badgeColor('info');
        }

        return $tabs;
    }
}
