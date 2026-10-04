<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests\Actions;

use App\Actions\ApproveEscalatedRequest;
use App\Enums\AssistanceStatus;
use App\Enums\CurrencyCode;
use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistanceRequest;
use App\Services\CurrencyConverter;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Dispatches the escalated remainder of a partially approved assistance request.
 *
 * The 100 USDC autonomous portion was already transferred by EduFlowAgent; this
 * action only releases the portion a human has authority over.
 */
class ApproveEscalatedAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->name('approve_escalated')
            ->label('Approve remainder')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (AssistanceRequest $record): bool => self::isVisibleFor($record))
            ->requiresConfirmation()
            ->modalHeading('Approve escalated assistance remainder')
            ->modalDescription('This executes a second USDC transfer on Arc for the portion held for human review, then resolves the request.')
            ->schema(fn (AssistanceRequest $record): array => self::schemaFor($record))
            ->action(function (AssistanceRequest $record, array $data): void {
                $this->process($record, $data);
            });
    }

    /**
     * @return array<int, Component>
     */
    public static function schemaFor(AssistanceRequest $record): array
    {
        $pending = $record->pendingReviewBaseUnits();
        $displayCurrency = self::displayCurrency();

        return [
            Placeholder::make('split_summary')
                ->label('Split decision')
                ->content(self::splitSummary($record, $pending, $displayCurrency)),
            Textarea::make('comment')
                ->label('Approval note')
                ->placeholder('Optional note stored on the approval record.')
                ->rows(2)
                ->maxLength(1000),
        ];
    }

    public static function displayCurrency(): CurrencyCode
    {
        $code = strtoupper((string) config('eduflow.display_currency', 'PHP'));

        return CurrencyCode::tryFrom($code) ?? CurrencyCode::PHP;
    }

    public static function splitSummary(AssistanceRequest $record, int $pending, CurrencyCode $currency): string
    {
        $converter = app(CurrencyConverter::class);
        $requested = (int) ($record->requested_amount ?? 0);
        $autoApproved = $requested - $pending;

        $lines = [
            'Requested: '.$converter->formatDual($requested, $currency),
        ];

        if ($autoApproved > 0) {
            $lines[] = 'Auto-approved already disbursed: '.$converter->formatDual($autoApproved, $currency);
        }

        if ($pending > 0) {
            $lines[] = 'Pending human approval: '.$converter->formatDual($pending, $currency);
        }

        $decision = $record->latestAgentDecision();
        if ($decision?->input_snapshot['locked_quote'] ?? null) {
            $quote = $decision->input_snapshot['locked_quote'];
            $lines[] = 'Locked rate: 1 USDC = '.$quote['units_per_usdc'].' minor '.$quote['quote']
                .' ('.$quote['provider'].', quoted '.$quote['quoted_at'].')';
        }

        if ($decision?->policy_checked) {
            $lines[] = 'Policy: '.$decision->policy_checked;
        }

        return implode(PHP_EOL, $lines);
    }

    public static function isVisibleUsing(): Closure
    {
        return self::isVisibleFor(...);
    }

    public static function isVisibleFor(AssistanceRequest $record): bool
    {
        if ($record->pendingReviewBaseUnits() <= 0) {
            return false;
        }

        return $record->agentDecisions()
            ->where('requires_approval', true)
            ->where('status', 'escalated')
            ->exists();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function process(AssistanceRequest $record, array $data): void
    {
        $decision = $record->latestAgentDecision();

        if (! $decision) {
            Notification::make()->title('No agent decision recorded for this request.')->danger()->send();

            return;
        }

        $fund = AssistanceFund::where('organization_id', $decision->organization_id)->first();

        if (! $fund) {
            Notification::make()->title('Assistance fund not configured.')->danger()->send();

            return;
        }

        try {
            app(ApproveEscalatedRequest::class)->handle(
                request: $record,
                decision: $decision,
                approver: Auth::user(),
                fund: $fund,
                comment: ($data['comment'] ?? null) ?: null,
            );
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('Escalated transfer failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Remainder approved')
            ->body(AssistanceRequestResource::formatUsdc($record->fresh()->requested_amount).' USDC fully disbursed. Request resolved.')
            ->success()
            ->send();
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject_escalated')
            ->label('Reject remainder')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Reject escalated assistance remainder')
            ->modalDescription('No further funds move. The request is closed and the auto-approved portion stands.')
            ->visible(fn (AssistanceRequest $record): bool => self::isVisibleFor($record))
            ->action(function (AssistanceRequest $record): void {
                $decision = $record->latestAgentDecision();

                Approval::where('agent_decision_id', $decision?->id)
                    ->where('status', 'pending')
                    ->update([
                        'approver_id' => Auth::id(),
                        'status' => 'rejected',
                        'comment' => 'Escalated remainder rejected by reviewer.',
                        'approved_at' => now(),
                    ]);

                $decision?->update(['status' => 'rejected']);

                $record->update([
                    'status' => AssistanceStatus::CLOSED,
                    'admin_notes' => trim(($record->admin_notes ?? '')."\nEscalated remainder rejected. The auto-approved portion remains disbursed."),
                    'resolved_at' => now(),
                ]);

                Notification::make()->title('Remainder rejected')->body('Request closed. No further funds moved.')->success()->send();
            });
    }

    public static function latestPendingApproval(AssistanceRequest $record): ?AgentDecision
    {
        return $record->agentDecisions()
            ->where('requires_approval', true)
            ->latest('id')
            ->first();
    }
}
