<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Widgets;

use App\Models\Student;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StudentsStatsWidget extends BaseWidget
{
    #[\Override]
    protected function getStats(): array
    {
        $totalStudents = Student::query()->count();
        $enrolledCount = Student::query()->where('enrollment_status', 'enrolled')->count();
        $qualifiedCount = Student::query()->where('academic_status', 'qualified')->count();
        $walletsCount = Student::query()
            ->where(function ($query): void {
                $query->whereNotNull('payout_address')
                    ->orWhereHas('wallet');
            })
            ->count();

        $enrolledPercentage = $totalStudents > 0
            ? round(($enrolledCount / $totalStudents) * 100, 1)
            : 0;

        return [
            Stat::make('Total Students', number_format($totalStudents))
                ->description('Registered student directory')
                ->descriptionIcon(Heroicon::Users)
                ->color('primary'),

            Stat::make('Active Enrolled', number_format($enrolledCount))
                ->description("{$enrolledPercentage}% actively taking courses")
                ->descriptionIcon(Heroicon::AcademicCap)
                ->color('success'),

            Stat::make('Qualified Standing', number_format($qualifiedCount))
                ->description('Eligible for institutional grants')
                ->descriptionIcon(Heroicon::CheckBadge)
                ->color('success'),

            Stat::make('Arc Wallets', number_format($walletsCount))
                ->description('Configured for on-chain disbursement')
                ->descriptionIcon(Heroicon::Wallet)
                ->color('info'),
        ];
    }
}
