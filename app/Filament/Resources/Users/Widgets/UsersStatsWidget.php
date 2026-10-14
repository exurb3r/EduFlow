<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Widgets;

use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UsersStatsWidget extends BaseWidget
{
    #[\Override]
    protected function getStats(): array
    {
        $totalUsers = User::query()->count();
        $studentsCount = User::query()->whereHas('student')->count();
        $staffCount = User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super_admin', 'finance_officer']))->count();
        $unverifiedCount = User::query()->whereNull('email_verified_at')->count();

        $mfaCount = User::query()->where(function ($q): void {
            $q->whereNotNull('app_authentication_secret')
                ->orWhere('has_email_authentication', true)
                ->orWhereHas('passkeys');
        })->count();

        $studentPct = $totalUsers > 0 ? round(($studentsCount / $totalUsers) * 100, 1) : 0;
        $mfaPct = $totalUsers > 0 ? round(($mfaCount / $totalUsers) * 100, 1) : 0;

        return [
            Stat::make('Total Users', number_format($totalUsers))
                ->description('All registered portal accounts')
                ->descriptionIcon(Heroicon::Users)
                ->color('primary'),

            Stat::make('Enrolled Students', number_format($studentsCount))
                ->description("{$studentPct}% with student profiles")
                ->descriptionIcon(Heroicon::AcademicCap)
                ->color('success'),

            Stat::make('MFA Secured', number_format($mfaCount))
                ->description("{$mfaPct}% protected with 2FA")
                ->descriptionIcon(Heroicon::ShieldCheck)
                ->color($mfaPct >= 50 ? 'success' : 'warning'),

            Stat::make('Staff & Officers', number_format($staffCount))
                ->description('Admin and finance supervisors')
                ->descriptionIcon(Heroicon::Key)
                ->color('info'),

            Stat::make('Unverified Accounts', number_format($unverifiedCount))
                ->description('Pending email verification')
                ->descriptionIcon(Heroicon::ExclamationCircle)
                ->color($unverifiedCount > 0 ? 'warning' : 'gray'),
        ];
    }
}
