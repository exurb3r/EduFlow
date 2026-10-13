<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Widgets;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Student;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class StudentProfileSummaryWidget extends Widget
{
    protected string $view = 'filament.resources.students.widgets.student-profile-summary-widget';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public function mount(?Model $record = null): void
    {
        $this->record = $record;
    }

    public function getStudent(): ?Student
    {
        if ($this->record instanceof Student) {
            return $this->record;
        }

        return null;
    }

    #[\Override]
    protected function getViewData(): array
    {
        $student = $this->getStudent();

        if (! $student) {
            return [];
        }

        $student->loadMissing(['user', 'wallet', 'tuitionAccounts.academicTerm', 'bills']);

        $name = $student->user->name;
        $words = explode(' ', trim($name));
        $initials = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $initials .= Str::upper(substr($w, 0, 1));
        }

        $walletAddress = $student->wallet->address ?? $student->payout_address;
        $explorerUrl = null;
        if ($walletAddress) {
            try {
                $explorerUrl = app(ArcNetworkGateway::class)->addressExplorerUrl($walletAddress);
            } catch (\Throwable) {
                $explorerUrl = null;
            }
        }

        $tuitionBalanceUnits = $student->totalTuitionBalance();
        $tuitionPaidUnits = $student->totalTuitionPaid();
        $tuitionOwedUnits = $student->totalTuitionOwed();

        $openBills = $student->bills->whereIn('status', ['open', 'partially_paid']);
        $unpaidBillsUnits = (int) $openBills->sum(fn ($b): int => (int) ($b->amount_base_units - $b->paid_base_units));

        return [
            'student' => $student,
            'name' => $name,
            'initials' => $initials ?: 'ST',
            'email' => $student->user->email,
            'studentNumber' => $student->student_number,
            'program' => $student->program,
            'yearLevel' => $student->year_level,
            'enrollmentStatus' => $student->enrollment_status,
            'enrollmentColor' => $student->enrollmentStatusColor(),
            'academicStatus' => $student->academic_status,
            'academicColor' => $student->academicStatusColor(),
            'attendanceRate' => $student->attendance_rate,
            'walletAddress' => $walletAddress,
            'explorerUrl' => $explorerUrl,
            'tuitionAccountsCount' => $student->tuitionAccounts->count(),
            'tuitionBalanceFormatted' => new Money($tuitionBalanceUnits, CurrencyCode::USDC)->format(2).' USDC',
            'tuitionPaidFormatted' => new Money($tuitionPaidUnits, CurrencyCode::USDC)->format(2).' USDC',
            'tuitionOwedFormatted' => new Money($tuitionOwedUnits, CurrencyCode::USDC)->format(2).' USDC',
            'hasTuitionDebt' => $tuitionBalanceUnits > 0,
            'openBillsCount' => $openBills->count(),
            'unpaidBillsFormatted' => new Money($unpaidBillsUnits, CurrencyCode::USDC)->format(2).' USDC',
        ];
    }
}
