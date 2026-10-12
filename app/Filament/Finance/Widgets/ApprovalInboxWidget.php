<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\Collections;
use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Pages\PaymentReviews;
use App\Models\CollectionBatch;
use App\Models\FinancePolicyVersion;
use App\Models\FinanceWorkflowRun;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\VendorDestinationVersion;
use App\Services\InstallationInstitution;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * One inbox for every decision that is waiting on a human.
 *
 * Cashier receipt review, plan acceptance and payment authorization are
 * different acts held by different people, so they are listed separately and
 * never merged into a single "pending approvals" count that could be read as
 * one authority. Reading this panel approves nothing.
 */
class ApprovalInboxWidget extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.finance.widgets.approval-inbox';

    #[\Override]
    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && Gate::forUser($user)->allows('viewAny', FinanceWorkflowRun::class);
    }

    #[\Override]
    protected function getViewData(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [
                'institutionMissing' => true,
                'collections' => [],
                'plans' => [],
                'payments' => [],
                'queues' => [],
            ];
        }

        return [
            'institutionMissing' => false,
            'collections' => $this->collectionItems($institution->id),
            'plans' => $this->planItems($institution->id),
            'payments' => $this->paymentItems($institution->id),
            'queues' => [
                'collections' => Collections::getUrl(panel: 'finance'),
                'plans' => FinanceSupervisor::getUrl(panel: 'finance'),
                'payments' => PaymentReviews::getUrl(panel: 'finance'),
            ],
        ];
    }

    /** @return list<array<string, string|int|null>> */
    private function collectionItems(int $institutionId): array
    {
        return CollectionBatch::query()
            ->where('organization_id', $institutionId)
            ->whereDoesntHave('reviews')
            ->with('preparer')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (CollectionBatch $batch): array => [
                'id' => $batch->id,
                'sourceStream' => $batch->source_stream,
                'sourceReference' => $batch->source_reference,
                'currency' => $batch->currency,
                'received' => Money::formatExact($batch->received_minor_units, $batch->currency),
                'restricted' => Money::formatExact($batch->restricted_minor_units, $batch->currency),
                'interval' => $this->interval($batch),
                'owner' => $this->ownerName($batch->preparer),
                'age' => $this->age($batch->created_at),
                'decision' => null,
                'url' => Collections::getUrl(panel: 'finance'),
            ])
            ->all();
    }

    /** @return list<array<string, string|int|null>> */
    private function planItems(int $institutionId): array
    {
        return FinanceWorkflowRun::query()
            ->where('organization_id', $institutionId)
            ->where('state', 'waiting_for_review')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (FinanceWorkflowRun $run): array => [
                'id' => $run->id,
                'kind' => $run->kind,
                'trigger' => $run->trigger_key,
                'currency' => (string) ($run->result['currency'] ?? ''),
                'budgetHeadroom' => Money::formatExact(data_get($run->result, 'headroom.budget_minor_units'), $run->result['currency'] ?? null),
                'cashHeadroom' => Money::formatExact(data_get($run->result, 'headroom.cash_minor_units'), $run->result['currency'] ?? null),
                'bills' => count($run->result['bills'] ?? []),
                'digest' => $run->source_digest,
                'age' => $this->age($run->created_at),
                'url' => FinanceSupervisor::getUrl(panel: 'finance'),
            ])
            ->all();
    }

    /** @return list<array<string, string|int|null>> */
    private function paymentItems(int $institutionId): array
    {
        return PaymentReservation::query()
            ->where('organization_id', $institutionId)
            ->whereDoesntHave('authorization')
            ->with(['intent.invoice', 'intent.preparer', 'intent.financePolicyVersion', 'intent.vendorDestinationVersion'])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (PaymentReservation $reservation): array {
                /** @var PaymentIntent $intent */
                $intent = $reservation->intent;
                $invoice = $intent->invoice;

                return [
                    'id' => $reservation->id,
                    'intentId' => $intent->id,
                    'reference' => $this->invoiceReference($invoice, $intent),
                    'amount' => Money::formatExact($reservation->amount_base_units, $intent->currency),
                    'fee' => Money::formatExact($reservation->max_fee_base_units, $intent->currency),
                    'dueDate' => $this->dueDate($invoice),
                    'policyVersion' => $this->policyVersion($intent->financePolicyVersion),
                    'destinationVersion' => $this->destinationVersion($intent->vendorDestinationVersion),
                    'chain' => $intent->chain.' '.$intent->chain_id,
                    'recipient' => $this->shortAddress($intent->recipient_address),
                    'owner' => $this->ownerName($intent->preparer),
                    'age' => $this->age($reservation->created_at),
                    'url' => PaymentReviews::getUrl(panel: 'finance'),
                ];
            })
            ->all();
    }

    private function interval(CollectionBatch $batch): string
    {
        return $batch->collected_from->toDateString().' → '.$batch->collected_until->toDateString();
    }

    private function shortAddress(?string $address): string
    {
        if ($address === null || $address === '') {
            return 'Not recorded';
        }

        return strlen($address) > 14 ? substr($address, 0, 6).'…'.substr($address, -4) : $address;
    }

    /**
     * Who recorded a row, or that the staff account is no longer readable.
     *
     * A deleted or unavailable record must not break the inbox, so every related
     * label is resolved explicitly: an absent record reports what is missing
     * instead of rendering as an empty cell.
     */
    private function ownerName(?User $user): string
    {
        return $user instanceof User ? $user->name : 'Unknown';
    }

    private function invoiceReference(?Invoice $invoice, PaymentIntent $intent): string
    {
        return $invoice instanceof Invoice ? $invoice->reference : 'Invoice #'.$intent->invoice_id;
    }

    private function dueDate(?Invoice $invoice): string
    {
        return $invoice instanceof Invoice ? $invoice->due_date->toDateString() : 'Not recorded';
    }

    private function policyVersion(?FinancePolicyVersion $policy): string
    {
        return $policy instanceof FinancePolicyVersion ? $policy->version : 'Not recorded';
    }

    private function destinationVersion(?VendorDestinationVersion $destination): string
    {
        return $destination instanceof VendorDestinationVersion ? $destination->version : 'Not recorded';
    }

    private function age(mixed $timestamp): string
    {
        if (! $timestamp instanceof Carbon) {
            return 'Unknown';
        }

        return $timestamp->diffForHumans();
    }
}
