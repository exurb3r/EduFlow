<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Agents\EduFlowAgent;
use App\Models\Organization;
use App\Services\CircleWalletService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

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
                ->label('Receive Tuition Revenue (+10,000 USDC)')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Deposit Tuition Revenue on Arc')
                ->modalDescription('Simulates an incoming student tuition batch of 10,000 USDC into Northstar Learning Center\'s Circle Wallet.')
                ->action(function (CircleWalletService $circleService): void {
                    $org = Organization::first();
                    $wallet = $org?->primaryWallet();

                    if (! $wallet) {
                        Notification::make()->title('Organization wallet not found')->danger()->send();

                        return;
                    }

                    $tx = $circleService->receiveRevenue($wallet, 10000.00, '0xstudent_tuition_batch', 'Fall Semester Tuition Revenue Deposit');

                    Notification::make()
                        ->title('Revenue Received')
                        ->body('+10,000.00 USDC confirmed on Arc. New Treasury: '.number_format($wallet->fresh()->balance, 2)." USDC. Tx: {$tx->provider_tx_hash}")
                        ->success()
                        ->send();
                }),

            Action::make('runAutonomousCycle')
                ->label('Run Autonomous Agent Cycle')
                ->icon('heroicon-m-bolt')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Execute Autonomous Financial Cycle')
                ->modalDescription('EduFlow AI will observe pending invoices, forecast 30-day liquidity, evaluate deterministic policies, auto-disburse approved USDC on Arc, and escalate high-value payments to the Approval Center.')
                ->action(function (EduFlowAgent $agent): void {
                    $org = Organization::first();

                    if (! $org) {
                        Notification::make()->title('No organization found. Please run seeder.')->danger()->send();

                        return;
                    }

                    $result = $agent->runAutonomousCycle($org);

                    Notification::make()
                        ->title('EduFlow AI — Cycle Completed')
                        ->body("Auto-Paid: {$result['stats']['auto_paid']} | Escalated: {$result['stats']['escalated']} | Held: {$result['stats']['held']}. Disbursed: {$result['stats']['total_disbursed_usdc']} USDC on Arc.")
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
