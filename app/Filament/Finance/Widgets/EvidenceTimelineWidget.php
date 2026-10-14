<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\PaymentReviews;
use App\Filament\Support\LinkedHeading;
use App\Models\AgentDecision;
use App\Models\PaymentIntent;
use App\Services\InstallationInstitution;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The evidence chain behind every vendor payment, stage by stage.
 *
 * Each column is one link: source document, reviewed evidence, exact draft,
 * reservation, authorization, provider attempt, verification. Empty later
 * stages are stated plainly rather than hidden, because a chain that stops
 * at "reserved" is the normal and correct outcome on this installation and
 * must not read as an incomplete row.
 */
class EvidenceTimelineWidget extends TableWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, string>|null Recorded advisory summaries keyed by intent id. */
    private ?array $advisories = null;

    private const string HEADING = 'Evidence-linked payment timeline';

    private const string DESCRIPTION = 'Source event through to settlement. Recorded rule results and public summaries only — never model reasoning. Model output may propose or explain; it cannot authorize, loosen a limit or choose a beneficiary.';

    #[\Override]
    protected function getTableQuery(): Builder
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return PaymentIntent::query()->whereRaw('1 = 0');
        }

        return PaymentIntent::query()
            ->where('organization_id', $institution->id)
            ->with(['invoice.vendor', 'reservation', 'authorization', 'invoiceVersionReview', 'submission']);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading(LinkedHeading::make(self::HEADING, PaymentReviews::getUrl(panel: 'finance')))
            ->description(self::DESCRIPTION)
            ->poll('10s')
            ->striped()
            ->columns([
                TextColumn::make('id')->label('Draft')->prefix('#')->sortable(),
                TextColumn::make('bill')->label('Source document')
                    ->state(fn (PaymentIntent $record): string => $record->invoice === null ? 'Invoice #'.$record->invoice_id : $record->invoice->reference)
                    ->description(fn (PaymentIntent $record): string => sprintf(
                        'Version #%s · review %s',
                        $record->invoice_version_id ?? '—',
                        $this->label($record->invoice_version_review_id === null ? null : $this->sourceReviewDecision($record)) ?? 'Not reviewed',
                    ))
                    ->searchable(),
                TextColumn::make('amount')->label('Exact amount')->alignEnd()
                    ->state(fn (PaymentIntent $record): string => Money::formatExact($record->amount_base_units, $record->currency))
                    ->description(fn (PaymentIntent $record): string => 'Fee ceiling '.Money::formatExact($record->max_fee_base_units, $record->currency)),
                TextColumn::make('revision')->label('Rev')->alignEnd()
                    ->description(fn (PaymentIntent $record): string => $record->predecessor_id === null ? 'Original draft' : 'Supersedes #'.$record->predecessor_id),
                TextColumn::make('reservation_stage')->label('Reservation')
                    ->badge()
                    ->state(fn (PaymentIntent $record): string => $record->reservation === null ? 'Not held' : 'Held')
                    ->color(fn (PaymentIntent $record): string => $record->reservation === null ? 'gray' : 'warning')
                    ->description(fn (PaymentIntent $record): string => $record->reservation === null
                        ? 'No capacity reserved'
                        : 'Reservation #'.$record->reservation->id.' · application capacity only, external funds not locked'),
                TextColumn::make('authorization_stage')->label('Authorization')
                    ->badge()
                    ->state(fn (PaymentIntent $record): string => $this->label($record->authorization?->decision) ?? 'Not authorized')
                    ->color(fn (PaymentIntent $record): string => match ($record->authorization?->decision) {
                        'approve_payment' => 'success',
                        'reject_payment' => 'danger',
                        'hold_payment' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (PaymentIntent $record): string => $record->authorization === null
                        ? 'Requires fresh step-up authentication by an enrolled reviewer'
                        : $this->authorizationDetail($record)),
                TextColumn::make('settlement_stage')->label('Provider attempt & verification')
                    ->badge()
                    ->state(fn (PaymentIntent $record): string => $record->submission->state ?? 'Not queued')
                    ->color(fn (PaymentIntent $record): string => match ($record->submission?->state) {
                        'completed' => 'success', 'unknown', 'blocked', 'failed', 'running' => 'warning', default => 'gray',
                    })
                    ->description(fn (PaymentIntent $record): string => $record->submission === null
                        ? 'No submission work recorded. Exact payment approval remains separate.'
                        : ($record->submission->state === 'completed' && ($record->submission->result['settled'] ?? false) === true
                            ? 'Verified settlement recorded; local payable remains separate.'
                            : 'Stage: '.($record->submission->stage ?? 'queued').'. No verified settlement recorded.')),
                TextColumn::make('advisory')->label('Recorded advisory')
                    ->state(fn (PaymentIntent $record): string => $this->advisory($record) ?? 'None recorded')
                    ->limit(60)->wrap()
                    ->color('gray')
                    ->tooltip(fn (PaymentIntent $record): ?string => $this->advisory($record)),
            ])
            ->recordActions([
                Action::make('examinePayment')
                    ->label('Review')
                    ->icon(Heroicon::ArrowRightCircle)
                    ->color('primary')
                    ->url(fn (): string => PaymentReviews::getUrl(panel: 'finance')),
            ])
            ->defaultSort('id', 'desc');
    }

    private function sourceReviewDecision(PaymentIntent $record): ?string
    {
        return $record->invoiceVersionReview?->decision;
    }

    private function label(?string $value): ?string
    {
        return match ($value) {
            'approve_payment' => 'Approved',
            'reject_payment' => 'Rejected',
            'hold_payment' => 'Held',
            'approve_evidence' => 'Evidence approved',
            'reject' => 'Rejected',
            'hold' => 'Held',
            'approve_receipts' => 'Receipts approved',
            'approve_receipt' => 'Receipts approved',
            default => $value,
        };
    }

    private function authorizationDetail(PaymentIntent $record): string
    {
        $authorization = $record->authorization;

        if ($authorization === null) {
            return 'Requires fresh step-up authentication by an enrolled reviewer';
        }

        $mfa = $authorization->mfa_method === 'fortify_totp' ? 'TOTP' : 'App authentication';
        $scope = ($authorization->snapshot['is_fake'] ?? false) === true ? ' · SIMULATION ONLY' : '';

        return sprintf(
            'Reviewer #%d · %s%s · expires %s · current checks still required before execution',
            $authorization->reviewed_by,
            $mfa,
            $scope,
            $authorization->snapshot['valid_until'] ?? 'not recorded',
        );
    }

    private function advisory(PaymentIntent $record): ?string
    {
        if ($this->advisories === null) {
            $this->advisories = [];

            $institution = app(InstallationInstitution::class)->current();

            if ($institution !== null) {
                $summaries = AgentDecision::query()
                    ->where('organization_id', $institution->id)
                    ->where('reference_type', (new PaymentIntent)->getMorphClass())
                    ->orderByDesc('id')
                    ->pluck('reasoning_summary', 'reference_id');

                foreach ($summaries as $referenceId => $summary) {
                    $this->advisories[(int) $referenceId] = (string) $summary;
                }
            }
        }

        return $this->advisories[$record->id] ?? null;
    }
}
