<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Widgets;

use App\DTOs\WalletReadState;
use App\Models\Student;
use App\Models\StudentWallet;
use App\Services\CircleAgentWalletCli;
use App\Services\StudentWalletGenerationService;
use App\Services\StudentWalletService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * A student's wallet: live USDC balance, receive details, and provenance.
 *
 * The balance is read from the chain and never stored, so this panel shows the
 * same number the student sees on their own wallet screen.
 */
class StudentWalletWidget extends Widget
{
    protected string $view = 'filament.resources.students.widgets.student-wallet-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * The record is passed in by the ViewStudent page.
     *
     * Filament restores this through Livewire's native mechanism and does not
     * re-run the resource query or the policy for it, so every read of it is
     * re-authorised below rather than trusted.
     */
    public ?Model $record = null;

    public ?string $address = null;

    public ?string $balance = null;

    public ?string $reason = null;

    public bool $isAvailable = false;

    public bool $isFake = false;

    public string $state = 'missing';

    public ?string $explorerUrl = null;

    public string $chain = 'ARC-TESTNET';

    public bool $hasGeneratedWallet = false;

    public ?string $generatedProvider = null;

    public ?string $generatedAt = null;

    public int $fundingCount = 0;

    public ?string $lastFunding = null;

    /**
     * Slots left on the chain, or null when Circle could not be asked.
     *
     * The count is decoration on a button, so an unreadable chain is not worth
     * failing the whole admin page over -- the mint itself still refuses.
     */
    public ?int $remainingSlots = null;

    public function mount(?Model $record = null): void
    {
        $this->record = $record;
        $this->readSnapshot();
        $this->readGeneratedWallet();
    }

    /**
     * Mint a Circle agent wallet for this student and make it the payout address.
     *
     * Generation spends one of five Circle agent-wallet slots per blockchain and
     * cannot be undone, so it is confirmed in the view before it is called.
     */
    public function generateWallet(): void
    {
        $student = $this->authorizedStudent();

        if (! $student instanceof Student) {
            $this->readSnapshot();

            return;
        }

        try {
            app(StudentWalletGenerationService::class)->generate($student);

            Notification::make()
                ->title('Wallet generated')
                ->body('A Circle agent wallet was minted and set as this student\'s payout address.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('Could not generate a wallet')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->readSnapshot();
        $this->readGeneratedWallet();
    }

    /**
     * Re-read the chain, dropping the short cache first so this is a live
     * reading rather than the copy served a moment ago.
     */
    public function refresh(): void
    {
        $student = $this->authorizedStudent();
        $service = app(StudentWalletService::class);

        if ($student instanceof Student) {
            $service->forget($student);
        }

        $this->readSnapshot();
        $this->readGeneratedWallet();
    }

    /**
     * The view receives exactly these values, so a stale public property
     * cannot reach the template.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getViewData(): array
    {
        return [
            'address' => $this->address,
            'balance' => $this->balance,
            'reason' => $this->reason,
            'isAvailable' => $this->isAvailable,
            'isFake' => $this->isFake,
            'state' => $this->state,
            'explorerUrl' => $this->explorerUrl,
            'chain' => $this->chain,
            'shortAddress' => $this->shortAddress(),
            'hasGeneratedWallet' => $this->hasGeneratedWallet,
            'generatedProvider' => $this->generatedProvider,
            'generatedAt' => $this->generatedAt,
            'fundingCount' => $this->fundingCount,
            'lastFunding' => $this->lastFunding,
            'remainingSlots' => $this->remainingSlots,
            'remainingSlotsPhrase' => $this->remainingSlotsPhrase(),
            'slotSummary' => $this->slotSummary(),
            'walletLimit' => CircleAgentWalletCli::AGENT_WALLET_LIMIT,
        ];
    }

    /**
     * Wording for the irreversible-mint confirmation, which must stay truthful
     * even when the slot count could not be read.
     */
    private function remainingSlotsPhrase(): string
    {
        if ($this->remainingSlots === null) {
            return 'the remaining Circle agent-wallet slots';
        }

        return $this->remainingSlots.' remaining slots';
    }

    private function slotSummary(): string
    {
        if ($this->remainingSlots === null) {
            return sprintf(
                'Circle could not report how many of the %d agent-wallet slots remain on %s.',
                CircleAgentWalletCli::AGENT_WALLET_LIMIT,
                strtoupper($this->chain),
            );
        }

        return sprintf(
            '%d of %d Circle agent-wallet slots remain on %s.',
            $this->remainingSlots,
            CircleAgentWalletCli::AGENT_WALLET_LIMIT,
            strtoupper($this->chain),
        );
    }

    private function readSnapshot(): void
    {
        $student = $this->authorizedStudent();

        if (! $student instanceof Student) {
            $this->resetTo('missing', 'This wallet could not be loaded. You may not have access to this student.');

            return;
        }

        $snapshot = app(StudentWalletService::class)->snapshot($student);

        $this->address = $snapshot->address;
        $this->balance = $snapshot->balance;
        // A missing or malformed address gets the panel's own copy below; the
        // snapshot's reason is student-facing wording that does not fit here.
        $this->reason = $snapshot->state === WalletReadState::UNAVAILABLE ? $snapshot->reason : null;
        $this->isAvailable = $snapshot->isReadable();
        $this->isFake = $snapshot->isFake;
        $this->state = $snapshot->state->value;
        $this->explorerUrl = $snapshot->explorerUrl;
        $this->chain = $snapshot->chain;
    }

    private function readGeneratedWallet(): void
    {
        $student = $this->authorizedStudent();

        if (! $student instanceof Student) {
            return;
        }

        /** @var StudentWallet|null $wallet */
        $wallet = $student->wallet;

        $this->hasGeneratedWallet = $wallet instanceof StudentWallet;
        $this->generatedProvider = $wallet?->provider;
        $this->generatedAt = $wallet?->generated_at->toDayDateTimeString();
        $this->fundingCount = $student->walletFundings()->count();
        $this->lastFunding = $student->walletFundings()->latest('funded_at')->first()?->funded_at->toDayDateTimeString();
        $this->remainingSlots = $this->readRemainingSlots();

        if ($wallet !== null) {
            $this->chain = $wallet->chain;
        }
    }

    /**
     * Ask Circle how many agent-wallet slots are left, or null if it cannot say.
     *
     * A missing CLI binary or an expired session is a real deployment state, and
     * it must not turn an admin's student page into a 500. The count is only
     * used to describe the button, and generate() re-reads it before minting.
     */
    private function readRemainingSlots(): ?int
    {
        try {
            return app(StudentWalletGenerationService::class)->remainingSlots();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The wallet belongs to the record named in the URL, and nothing else.
     *
     * The public $record property is client-restorable, so a different record
     * id must not be able to read another student's wallet: the record is
     * re-checked against the student policy on every read.
     */
    private function authorizedStudent(): ?Student
    {
        $record = $this->record;

        if (! $record instanceof Student) {
            return null;
        }

        if (! Gate::allows('view', $record)) {
            return null;
        }

        return $record;
    }

    private function shortAddress(): ?string
    {
        if ($this->address === null || strlen($this->address) <= 14) {
            return $this->address;
        }

        return substr($this->address, 0, 6).'…'.substr($this->address, -4);
    }

    private function resetTo(string $state, string $reason): void
    {
        $this->state = $state;
        $this->reason = $reason;
        $this->address = null;
        $this->balance = null;
        $this->isAvailable = false;
        $this->isFake = false;
        $this->explorerUrl = null;
    }
}
