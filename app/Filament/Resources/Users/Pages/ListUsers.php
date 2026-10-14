<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Widgets\UsersStatsWidget;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New user account')
                ->icon(Heroicon::UserPlus),
        ];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            UsersStatsWidget::class,
        ];
    }

    /**
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Users')
                ->icon(Heroicon::Users)
                ->badge(fn (): int => User::query()->count()),

            'students' => Tab::make('Students')
                ->icon(Heroicon::AcademicCap)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('student'))
                ->badge(fn (): int => User::query()->whereHas('student')->count())
                ->badgeColor('primary'),

            'staff' => Tab::make('Staff & Admins')
                ->icon(Heroicon::ShieldCheck)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('roles', fn (Builder $q) => $q->whereIn('name', ['admin', 'super_admin', 'finance_officer'])))
                ->badge(fn (): int => User::query()->whereHas('roles', fn (Builder $q) => $q->whereIn('name', ['admin', 'super_admin', 'finance_officer']))->count())
                ->badgeColor('warning'),

            'unverified' => Tab::make('Unverified')
                ->icon(Heroicon::ExclamationTriangle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('email_verified_at'))
                ->badge(fn (): int => User::query()->whereNull('email_verified_at')->count())
                ->badgeColor('danger'),

            'mfa_missing' => Tab::make('Missing 2FA')
                ->icon(Heroicon::ShieldExclamation)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('app_authentication_secret')->where('has_email_authentication', false)->whereDoesntHave('passkeys'))
                ->badge(fn (): int => User::query()->whereNull('app_authentication_secret')->where('has_email_authentication', false)->whereDoesntHave('passkeys')->count())
                ->badgeColor('gray'),
        ];
    }
}
