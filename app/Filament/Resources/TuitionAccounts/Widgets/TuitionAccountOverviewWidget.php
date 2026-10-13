<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\Widgets;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\TuitionAccount;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class TuitionAccountOverviewWidget extends Widget
{
    protected string $view = 'filament.resources.tuition-accounts.widgets.tuition-account-overview-widget';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public function mount(?Model $record = null): void
    {
        $this->record = $record;
    }

    public function getTuitionAccount(): ?TuitionAccount
    {
        if ($this->record instanceof TuitionAccount) {
            return $this->record;
        }

        return null;
    }

    public function getExplorerUrl(): ?string
    {
        $account = $this->getTuitionAccount();

        if (! $account) {
            return null;
        }

        $address = $account->student->wallet->address ?? $account->student->payout_address;

        if (! $address) {
            return null;
        }

        try {
            return app(ArcNetworkGateway::class)->addressExplorerUrl($address);
        } catch (\Throwable) {
            return null;
        }
    }

    #[\Override]
    protected function getViewData(): array
    {
        $account = $this->getTuitionAccount();

        if (! $account) {
            return [];
        }

        $account->loadMissing(['student.user', 'student.wallet', 'academicTerm']);

        $totalMoney = new Money($account->total_amount, CurrencyCode::USDC);
        $paidMoney = new Money($account->paid_amount, CurrencyCode::USDC);
        $remainingMoney = new Money($account->remainingAmount(), CurrencyCode::USDC);

        return [
            'account' => $account,
            'totalFormatted' => $totalMoney->format(2).' USDC',
            'paidFormatted' => $paidMoney->format(2).' USDC',
            'remainingFormatted' => $remainingMoney->format(2).' USDC',
            'percentage' => $account->paymentProgressPercentage(),
            'statusLabel' => $account->statusLabel(),
            'statusColor' => $account->statusColor(),
            'isPaidInFull' => $account->isPaidInFull(),
            'isPartiallyPaid' => $account->isPartiallyPaid(),
            'isUnpaid' => $account->isUnpaid(),
            'studentName' => $account->student->user->name,
            'studentNumber' => $account->student->student_number,
            'termName' => $account->academicTerm->name,
            'explorerUrl' => $this->getExplorerUrl(),
        ];
    }
}
