<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSubmissions\Pages;

use App\Filament\Resources\PaymentSubmissions\PaymentSubmissionResource;
use App\Models\PaymentSubmissionOutbox;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

/**
 * What the automation actually did with this payment.
 *
 * The view is ordered as the payment moved: authority, then the durable
 * submission, then the rail, then the verdict. Every claim it makes is a fact
 * recorded by the component that made it — nothing here infers, and nothing
 * here concludes. Where the chain has not answered yet, it says so.
 */
class ViewPaymentSubmission extends ViewRecord
{
    protected static string $resource = PaymentSubmissionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewOnExplorer')
                ->label('View on Arc explorer')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                // Only when a real on-chain reference exists. A Circle
                // transaction id is not a URL, and turning it into one produces
                // a link that 404s.
                ->visible(fn (): bool => $this->onChainReference() !== null)
                ->url(fn (): ?string => $this->onChainReference() === null ? null : $this->explorerFor($this->onChainReference()))
                ->openUrlInNewTab(),
        ];
    }

    #[\Override]
    public function infolist(Schema $schema): Schema
    {
        /** @var PaymentSubmissionOutbox $entry */
        $entry = $this->getRecord();

        return $schema
            ->record($entry)
            ->columns(1)
            ->schema([
                Section::make('What the automation did, in order')
                    ->description('Read top to bottom. Each step is what the component recorded, not what it intended.')
                    ->schema([
                        TextEntry::make('state')
                            ->label('Where it is now')
                            ->state(fn (): string => self::plainState($entry))
                            ->badge()
                            ->color(fn (): string => self::stateColor($entry->state)),

                        TextEntry::make('stage')
                            ->label('How far it got')
                            ->state(fn (): string => $entry->stage ?? 'not yet dispatched')
                            ->weight('medium'),

                        TextEntry::make('explanation')
                            ->label('What that means')
                            ->state(fn (): string => self::explain($entry))
                            ->color('gray'),
                    ]),

                Section::make('Authority')
                    ->description('Who authorized this, and on what evidence. No payment is sent on anything less.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('snapshot.authorized_by')
                            ->label('Authorized by')
                            ->state(fn (): string => (string) ($entry->snapshot['authorized_by'] ?? 'unknown')),
                        TextEntry::make('snapshot.authorized_until')
                            ->label('Authority valid until')
                            ->state(fn (): ?string => $entry->snapshot['authorized_until'] ?? null)
                            ->placeholder('not recorded'),
                        TextEntry::make('snapshot.source_address')
                            ->label('From')
                            ->copyable(),
                        TextEntry::make('snapshot.recipient_address')
                            ->label('To')
                            ->copyable(),
                        TextEntry::make('snapshot.amount_base_units')
                            ->label('Amount')
                            ->state(fn (): string => self::amount($entry)),
                        TextEntry::make('snapshot.chain')
                            ->label('Chain')
                            ->state(fn (): string => sprintf('%s (%s)', $entry->snapshot['chain'] ?? 'unknown', $entry->snapshot['chain_id'] ?? '?')),
                        TextEntry::make('snapshot.max_fee_base_units')
                            ->label('Approved fee ceiling')
                            ->state(fn (): string => self::fee($entry))
                            ->helperText('bounds on-chain gas only — the rail may charge more'),
                    ]),

                Section::make('The rail')
                    ->description('What the rail itself returned. A handle is its own identifier; it is not an on-chain reference.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('provider_idempotency_key')
                            ->label('Provider identity')
                            ->copyable()
                            ->helperText('the rail recognises this key on a retry, so a replay cannot pay twice'),
                        TextEntry::make('provider_handle')
                            ->label("Rail's transaction id")
                            ->copyable()
                            ->placeholder('none — nothing in flight')
                            ->state(fn (): ?string => $entry->provider_handle),
                        TextEntry::make('result.provider_reference')
                            ->label('On-chain reference')
                            ->copyable()
                            ->placeholder('not published yet')
                            ->state(fn (): ?string => $entry->result['provider_reference'] ?? null),
                        TextEntry::make('attempts')
                            ->label('Submissions attempted')
                            ->state(fn (): string => sprintf('%d of %d', (int) $entry->attempts, (int) $entry->max_attempts)),
                    ]),

                Section::make('What the rail said, in its own words')
                    ->schema([
                        TextEntry::make('last_error')
                            ->label('Message')
                            ->state(fn (): ?string => $entry->last_error)
                            ->placeholder('The rail had nothing to report on this payment.')
                            // The provider's wording is shown unedited. Rewriting
                            // it turns a refusal into a shrug.
                            ->color(fn (): string => $entry->last_error ? 'danger' : 'gray'),
                    ]),

                Section::make('Every attempt, append-only')
                    ->description('One row per submission. Nothing here is ever rewritten — an uncertain outcome is reconciled, not edited.')
                    ->schema([
                        RepeatableEntry::make('attemptRecords')
                            ->hiddenLabel()
                            ->state(fn (): array => self::attempts($entry))
                            ->schema([
                                TextEntry::make('happened_at')
                                    ->label('When')
                                    ->state(fn (array $state): string => (string) ($state['observed_at'] ?? 'unknown')),
                                TextEntry::make('outcome')
                                    ->label('Outcome')
                                    ->badge()
                                    ->color(fn (array $state): string => match ((string) ($state['outcome'] ?? '')) {
                                        'submitted' => 'info',
                                        'accepted' => 'warning',
                                        'blocked', 'failed', 'unknown' => 'danger',
                                        default => 'gray',
                                    })
                                    ->state(fn (array $state): string => (string) ($state['outcome'] ?? 'unknown')),
                                TextEntry::make('reference')
                                    ->label('Reference')
                                    ->state(fn (array $state): string => (string) ($state['provider_reference'] ?? 'none recorded'))
                                    ->copyable(fn (array $state): bool => ! empty($state['provider_reference'])),
                                TextEntry::make('detail')
                                    ->label('Response')
                                    ->state(fn (array $state): string => (string) ($state['detail'] ?? 'no response recorded'))
                                    ->color('gray'),
                            ])
                            ->columns(4),
                    ]),

                Section::make('Evidence')
                    ->description('The digests that bind this submission to the authorization it was created from. Change any one and the row stops verifying.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('request_key')->label('Submission identity')->copyable(),
                        TextEntry::make('snapshot_digest')
                            ->label('Evidence digest')
                            ->copyable()
                            ->limit(20)
                            ->tooltip(fn (): string => $entry->snapshot_digest),
                    ]),
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function attempts(PaymentSubmissionOutbox $entry): array
    {
        return $entry->attempts()
            ->orderBy('attempt_number')
            ->get()
            ->map(fn ($attempt): array => [
                'attempt_number' => $attempt->attempt_number,
                'outcome' => $attempt->outcome,
                'provider_reference' => $attempt->provider_reference,
                'observed_at' => $attempt->observed_at?->toDateTimeString(),
                'detail' => self::detailFor($attempt),
            ])
            ->all();
    }

    private static function detailFor($attempt): string
    {
        $response = $attempt->response_snapshot ?? [];
        $detail = $response['detail'] ?? [];

        if ($detail === [] || $detail === null) {
            return 'no response recorded';
        }

        return is_string($detail) ? $detail : json_encode($detail);
    }

    private static function plainState(PaymentSubmissionOutbox $entry): string
    {
        return match ($entry->state) {
            'queued' => 'queued for the worker',
            'running' => 'being submitted right now',
            'accepted' => 'the rail has taken it; no on-chain reference yet',
            'submitted' => 'an on-chain reference exists; settlement not yet proven',
            'completed' => 'verified settled on Arc',
            'unknown' => 'unresolved — nobody knows what happened',
            'blocked' => 'blocked before reaching the rail',
            'failed' => 'the rail reported it failed',
            'paused' => 'held by an operator',
            default => (string) $entry->state,
        };
    }

    private static function stateColor(PaymentSubmissionOutbox|string $state): string
    {
        $state = $state instanceof PaymentSubmissionOutbox ? $state->state : $state;

        return match ($state) {
            'queued', 'running' => 'warning',
            'accepted', 'submitted' => 'info',
            'completed' => 'success',
            'unknown', 'blocked', 'failed' => 'danger',
            default => 'gray',
        };
    }

    /**
     * What a given state actually means, said once and said plainly.
     *
     * The distinction that matters most is between "the rail has it" and "the
     * money moved". Conflating them is the failure this whole subsystem exists
     * to prevent, so the explanation never does.
     */
    private static function explain(PaymentSubmissionOutbox $entry): string
    {
        return match ($entry->state) {
            'accepted' => 'Circle has accepted the transfer and issued its own transaction id. No on-chain '
                .'reference exists yet, so nothing has been proven either way. Reconciliation observes the rail '
                .'until it publishes one. This is in flight, not settled.',
            'submitted' => 'An on-chain reference exists. Reconciliation checks it against the chain to prove the '
                .'money actually moved to the authorized recipient.',
            'completed' => 'Arc verified a successful execution with the authorized sender, recipient and amount. '
                .'The ledger was written from that verdict alone.',
            'unknown' => 'The rail could not answer, or answered in a shape that named nothing. The payment may '
                .'well have been accepted. Capacity stays held and no retry is attempted until it is reconciled.',
            'blocked' => 'Stopped before reaching the rail — an expired authority, a disabled runtime or the stop '
                .'switch. Nothing was sent, and nothing should be inferred from it.',
            'failed' => 'The rail reported the transfer as failed. No USDC moved.',
            default => 'Still waiting for the worker. Nothing has been sent.',
        };
    }

    private function onChainReference(): ?string
    {
        $reference = $this->getRecord()->result['provider_reference'] ?? null;

        return is_string($reference) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $reference) === 1
            ? $reference
            : null;
    }

    private function explorerFor(string $reference): string
    {
        try {
            return app(ArcNetworkGateway::class)->explorerUrl($reference);
        } catch (Throwable) {
            return 'https://explorer.testnet.arc.io/tx/'.$reference;
        }
    }

    private static function amount(PaymentSubmissionOutbox $entry): string
    {
        $base = $entry->snapshot['amount_base_units'] ?? null;

        if (! is_numeric($base)) {
            return 'unknown';
        }

        return number_format(((float) $base) / 1_000000, 2).' USDC';
    }

    private static function fee(PaymentSubmissionOutbox $entry): string
    {
        $base = $entry->snapshot['max_fee_base_units'] ?? null;

        if (! is_numeric($base)) {
            return 'unknown';
        }

        return number_format(((float) $base) / 1_000000, 2).' USDC';
    }
}
