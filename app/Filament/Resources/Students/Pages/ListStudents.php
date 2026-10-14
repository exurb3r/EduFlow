<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Students\Widgets\StudentsStatsWidget;
use App\Models\Student;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Register student')
                ->icon(Heroicon::PlusCircle),
        ];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            StudentsStatsWidget::class,
        ];
    }

    /**
     * Directory tabs for quick filtering by academic standing and onboarding stage.
     *
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Students')
                ->icon(Heroicon::Users)
                ->badge(fn (): int => Student::query()->count()),

            'enrolled' => Tab::make('Active Enrolled')
                ->icon(Heroicon::AcademicCap)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('enrollment_status', 'enrolled'))
                ->badge(fn (): int => Student::query()->where('enrollment_status', 'enrolled')->count())
                ->badgeColor('success'),

            'at_risk' => Tab::make('At-Risk & Probation')
                ->icon(Heroicon::ExclamationTriangle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                    $q->whereIn('academic_status', ['probation', 'suspended', 'dismissed'])
                        ->orWhere(fn ($sq) => $sq->whereNotNull('attendance_rate')->where('attendance_rate', '<', 80));
                }))
                ->badge(fn (): int => Student::query()->where(function (Builder $q): void {
                    $q->whereIn('academic_status', ['probation', 'suspended', 'dismissed'])
                        ->orWhere(fn ($sq) => $sq->whereNotNull('attendance_rate')->where('attendance_rate', '<', 80));
                })->count())
                ->badgeColor('warning'),

            'missing_wallet' => Tab::make('Missing Wallet')
                ->icon(Heroicon::Wallet)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                    $q->whereNull('payout_address')->orWhere('payout_address', '');
                }))
                ->badge(fn (): int => Student::query()->where(function (Builder $q): void {
                    $q->whereNull('payout_address')->orWhere('payout_address', '');
                })->count())
                ->badgeColor('danger'),

            'graduated' => Tab::make('Graduated')
                ->icon(Heroicon::CheckBadge)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('enrollment_status', 'graduated'))
                ->badge(fn (): int => Student::query()->where('enrollment_status', 'graduated')->count())
                ->badgeColor('gray'),
        ];
    }
}
