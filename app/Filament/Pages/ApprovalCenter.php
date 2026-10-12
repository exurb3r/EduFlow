<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Agents\EduFlowAgent;
use App\Models\Approval;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ApprovalCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Finance records';

    protected static ?string $navigationLabel = 'Legacy Approval Center';

    protected static ?string $title = 'Legacy Approval Center';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.approval-center';

    public static function getNavigationBadge(): ?string
    {
        $count = Approval::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Approval::query()->where('status', 'pending')->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Escalated At')
                    ->dateTime('M d, H:i')
                    ->sortable(),
                TextColumn::make('agentDecision.action_type')
                    ->label('Action')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('agentDecision.requested_amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                    ->weight('bold'),
                TextColumn::make('agentDecision.policy_checked')
                    ->label('Policy Trigger')
                    ->badge()
                    ->color('warning'),
                TextColumn::make('agentDecision.reasoning_summary')
                    ->label('AI Escalation Rationale')
                    ->limit(60)
                    ->tooltip(fn (Approval $record) => $record->agentDecision?->reasoning_summary),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve & Pay USDC')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Authorize USDC Payment on Arc')
                    ->modalDescription('This will execute the transaction from the organization\'s Circle Wallet on the Arc network.')
                    ->schema([
                        Textarea::make('comment')
                            ->label('Authorization Note')
                            ->placeholder('e.g., Verified with Department Dean; within quarterly cap.')
                            ->rows(2),
                    ])
                    ->action(function (Approval $record, array $data, EduFlowAgent $agent): void {
                        $user = Auth::user();
                        $success = $agent->approveEscalation($record, $user, $data['comment'] ?? null);

                        if ($success) {
                            Notification::make()
                                ->title('Payment Authorized & Executed')
                                ->body('USDC transfer confirmed on Arc network.')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Approval Execution Failed')
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')
                            ->label('Rejection Reason')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (Approval $record, array $data, EduFlowAgent $agent): void {
                        $user = Auth::user();
                        $agent->rejectEscalation($record, $user, $data['reason']);

                        Notification::make()
                            ->title('Payment Rejected')
                            ->body('Transaction marked rejected and logged in audit trail.')
                            ->warning()
                            ->send();
                    }),
            ]);
    }
}
