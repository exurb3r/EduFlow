<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests;

use App\Enums\AssistanceStatus;
use App\Enums\CurrencyCode;
use App\Filament\Resources\AssistanceRequests\Actions\ApproveEscalatedAction;
use App\Filament\Resources\AssistanceRequests\Pages\ListAssistanceRequests;
use App\Filament\Resources\AssistanceRequests\Pages\ViewAssistanceRequest;
use App\Models\AgentDecision;
use App\Models\AssistanceRequest;
use App\Services\CurrencyConverter;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AssistanceRequestResource extends Resource
{
    protected static ?string $model = AssistanceRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    protected static string|UnitEnum|null $navigationGroup = 'Student Services';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = AssistanceRequest::whereIn('status', [
            AssistanceStatus::SUBMITTED->value,
            AssistanceStatus::PENDING->value,
        ])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('ticket_number')->label('Ticket #'),
            TextEntry::make('student.student_number')->label('Student number'),
            TextEntry::make('student.user.name')->label('Student'),
            TextEntry::make('academicTerm.name')->label('Academic term'),
            TextEntry::make('type')->badge(),
            TextEntry::make('requested_amount')->label('Requested amount (USDC)')
                ->formatStateUsing(fn (int|string|null $state): string => self::formatUsdc($state)),
            TextEntry::make('status')->badge(),
            TextEntry::make('submitted_at')->dateTime(),
            TextEntry::make('reason')->columnSpanFull(),
            self::agentDecisionSection(),
            RepeatableEntry::make('student.tuitionAccounts')->label('Tuition accounts')
                ->state(fn (AssistanceRequest $record) => $record->student?->tuitionAccounts()->with('academicTerm')->get() ?? collect())
                ->schema([
                    TextEntry::make('academicTerm.name')->label('Academic term'),
                    TextEntry::make('total_amount')->label('Total (USDC)')
                        ->formatStateUsing(fn (int|string|null $state): string => self::formatUsdc($state)),
                    TextEntry::make('paid_amount')->label('Paid (USDC)')
                        ->formatStateUsing(fn (int|string|null $state): string => self::formatUsdc($state)),
                ])->columns(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket #')->searchable()->sortable(),
                TextColumn::make('student.student_number')->label('Student number')->searchable(),
                TextColumn::make('student.user.name')->label('Student')->searchable(),
                TextColumn::make('subject')->label('Subject')->searchable(),
                TextColumn::make('academicTerm.name')->label('Academic term'),
                TextColumn::make('requested_amount')->label('Requested amount (USDC)')
                    ->formatStateUsing(fn (int|string|null $state): string => self::formatUsdc($state))
                    ->sortable(),
                TextColumn::make('auto_approved')->label('Auto-approved')
                    ->state(fn (AssistanceRequest $record): string => self::formatUsdc(
                        (int) ($record->requested_amount ?? 0) - $record->pendingReviewBaseUnits()
                    ))
                    ->color('success')
                    ->toggleable(),
                TextColumn::make('pending_review')->label('Pending review')
                    ->state(fn (AssistanceRequest $record): string => self::formatUsdc($record->pendingReviewBaseUnits()))
                    ->badge()
                    ->color(fn (string $state): string => self::formatUsdc(0) === $state ? 'gray' : 'warning')
                    ->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('academic_term_id')->label('Academic term')
                    ->relationship('academicTerm', 'name')->searchable()->preload(),
                SelectFilter::make('status')->options([
                    'submitted' => 'Submitted',
                    'pending' => 'Pending Review',
                    'in_progress' => 'In Progress',
                    'resolved' => 'Resolved',
                    'closed' => 'Closed',
                ]),
                SelectFilter::make('awaiting_human_approval')->label('Awaiting human approval')
                    ->options([
                        'yes' => 'Awaiting human approval',
                        'no' => 'No pending approval',
                    ])
                    ->query(function (Builder $query, array $state): Builder {
                        $awaiting = fn (Builder $q): Builder => $q
                            ->whereHas('agentDecisions', fn (Builder $inner) => $inner
                                ->where('requires_approval', true)
                                ->where('status', 'escalated')
                            );

                        return match ($state['value'] ?? null) {
                            'yes' => $awaiting($query),
                            'no' => $query->whereDoesntHave('agentDecisions', fn (Builder $inner) => $inner
                                ->where('requires_approval', true)
                                ->where('status', 'escalated')
                            ),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->recordActions([
                ApproveEscalatedAction::make(),
                ApproveEscalatedAction::rejectAction(),
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return Builder<AssistanceRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student.user', 'academicTerm', 'user', 'agentDecisions']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Dual-currency view of the deterministic decision, its locked FX quote,
     * and each individual policy check. Rendered only when the agent has
     * actually evaluated the request.
     */
    public static function agentDecisionSection(): Section
    {
        return Section::make('EduFlow AI decision')
            ->description('Deterministic policy evaluation. The AI never moves funds; it only proposes, and Laravel policy decides.')
            ->icon('heroicon-o-sparkles')
            ->collapsible()
            ->columnSpanFull()
            ->hidden(fn (?AssistanceRequest $record): bool => $record?->latestAgentDecision() === null)
            ->schema([
                TextEntry::make('decision_label')
                    ->label('Decision')
                    ->state(fn (?AssistanceRequest $record): string => self::latestDecision($record)?->decision->getLabel() ?? '—')
                    ->badge(),
                TextEntry::make('policy_checked')
                    ->label('Policy')
                    ->state(fn (?AssistanceRequest $record): string => self::latestDecision($record)?->policy_checked ?? '—'),
                TextEntry::make('split')
                    ->label('Autonomous vs pending')
                    ->state(fn (?AssistanceRequest $record): string => $record ? self::splitSummary($record) : '—'),
                TextEntry::make('locked_quote')
                    ->label('Locked exchange rate')
                    ->state(fn (?AssistanceRequest $record): string => self::lockedQuoteSummary(self::latestDecision($record)))
                    ->badge(),
                TextEntry::make('reasoning_summary')
                    ->label('Why')
                    ->state(fn (?AssistanceRequest $record): string => self::latestDecision($record)?->reasoning_summary ?? '—')
                    ->columnSpanFull(),
                TextEntry::make('checks')
                    ->label('Policy checks')
                    ->state(fn (?AssistanceRequest $record): string => self::checkSummary(self::latestDecision($record)))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function latestDecision(?AssistanceRequest $record): ?AgentDecision
    {
        return $record?->latestAgentDecision();
    }

    public static function displayCurrency(): CurrencyCode
    {
        $code = strtoupper((string) config('eduflow.display_currency', 'PHP'));

        return CurrencyCode::tryFrom($code) ?? CurrencyCode::PHP;
    }

    public static function splitSummary(AssistanceRequest $record): string
    {
        $converter = app(CurrencyConverter::class);
        $currency = self::displayCurrency();
        $requested = (int) ($record->requested_amount ?? 0);
        $pending = $record->pendingReviewBaseUnits();
        $auto = $requested - $pending;

        $parts = [];

        if ($auto > 0) {
            $parts[] = 'Auto-approved '.$converter->formatDual($auto, $currency);
        }

        if ($pending > 0) {
            $parts[] = 'Awaiting approval '.$converter->formatDual($pending, $currency);
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    public static function lockedQuoteSummary(?AgentDecision $decision): string
    {
        $quote = $decision?->input_snapshot['locked_quote'] ?? null;

        if (! is_array($quote)) {
            return '—';
        }

        return sprintf(
            '1 USDC = %s minor %s · %s · quoted %s',
            $quote['units_per_usdc'] ?? '?',
            $quote['quote'] ?? '?',
            $quote['provider'] ?? 'unknown',
            $quote['quoted_at'] ?? 'unknown',
        );
    }

    public static function checkSummary(?AgentDecision $decision): string
    {
        $checks = $decision?->input_snapshot['checks'] ?? null;

        if (! is_array($checks) || $checks === []) {
            return '—';
        }

        $lines = [];

        foreach ($checks as $name => $passed) {
            $lines[] = ($passed ? '✓' : '✗').' '.str_replace('_', ' ', (string) $name);
        }

        return implode(PHP_EOL, $lines);
    }

    public static function formatUsdc(int|string|null $amount): string
    {
        $amount = (string) ($amount ?? 0);
        $digits = str_pad($amount, 7, '0', STR_PAD_LEFT);

        return substr($digits, 0, -6).'.'.substr($digits, -6);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssistanceRequests::route('/'),
            'view' => ViewAssistanceRequest::route('/{record}'),
        ];
    }
}
