<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Agents\EduFlowAgent;
use App\Services\CircleWalletService;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Throwable;

class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::Home;

    public function getTitle(): string
    {
        return 'EduFlow AI — Autonomous Financial Operator';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receiveRevenue')
                ->label('Record Tuition Revenue (+10,000 USDC)')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Record incoming tuition revenue')
                ->modalDescription('Credits the EduFlow ledger for an incoming tuition batch. This is a ledger entry only: no on-chain transfer is made, so the transaction is stored without a tx hash.')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount (USDC)')
                        ->numeric()
                        ->default(10000)
                        ->required(),
                ])
                ->action(function (CircleWalletService $walletService, array $data): void {
                    $wallet = app(InstallationInstitution::class)->current()?->primaryWallet();

                    if (! $wallet) {
                        Notification::make()->title('Organization wallet not found')->danger()->send();

                        return;
                    }

                    $amount = (float) $data['amount'];
                    $reference = 'inbound-'.Str::uuid();

                    $tx = $walletService->receiveRevenue($wallet, $amount, $reference, 'Tuition Revenue Deposit');

                    Notification::make()
                        ->title('Revenue recorded in the EduFlow ledger')
                        ->body('+'.number_format($amount, 2).' USDC credited locally. Ledger treasury: '.number_format($wallet->fresh()->balance, 2).' USDC. Reference: '.$reference.'. No on-chain transfer was made; sync from Arc to reconcile.')
                        ->success()
                        ->persistent()
                        ->send();

                    $this->reportChainDrift();
                }),

            Action::make('runAutonomousCycle')
                ->label('Run Autonomous Agent Cycle')
                ->icon('heroicon-m-bolt')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Execute Autonomous Financial Cycle')
                ->modalDescription('EduFlow AI will observe pending invoices, forecast 30-day liquidity, evaluate deterministic policies, disburse approved USDC through the Lepton agent wallet on Arc, and escalate high-value payments to the Approval Center.')
                ->action(function (EduFlowAgent $agent): void {
                    $org = app(InstallationInstitution::class)->current();

                    if (! $org) {
                        Notification::make()->title('Institution context unavailable. Check installation configuration.')->danger()->send();

                        return;
                    }

                    try {
                        $result = $agent->runAutonomousCycle($org);
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title('Cycle failed before completing')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $stats = $result['stats'];

                    Notification::make()
                        ->title('EduFlow AI — Cycle Completed')
                        ->body("Auto-Paid: {$stats['auto_paid']} | Escalated: {$stats['escalated']} | Held: {$stats['held']} | Rejected: {$stats['rejected']}. Disbursed: {$stats['total_disbursed_usdc']} USDC on Arc.")
                        ->success()
                        ->persistent()
                        ->send();

                    $this->reportChainDrift();
                }),
        ];
    }

    /**
     * Surface ledger-vs-chain divergence after any operation, so operations
     * staff can never mistake a local credit for settled funds.
     */
    private function reportChainDrift(): void
    {
        $wallet = app(InstallationInstitution::class)->current()?->primaryWallet();

        if (! $wallet) {
            return;
        }

        $chain = app(LeptonTreasuryService::class)->status($wallet);

        if (! $chain['live_available']) {
            return;
        }

        if ($chain['in_sync'] === false) {
            Notification::make()
                ->title('Ledger and chain differ')
                ->body(sprintf(
                    'EduFlow ledger %s USDC vs live Arc %s USDC (drift %s USDC). Use "Sync from chain" on the Arc Settlement Network panel to reconcile.',
                    number_format($chain['ledger_balance'], 2),
                    number_format($chain['onchain_balance'], 2),
                    number_format($chain['drift'], 2),
                ))
                ->warning()
                ->persistent()
                ->send();
        }
    }
}
