<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSubmissions;

use App\Filament\Resources\PaymentSubmissions\Pages\ListPaymentSubmissions;
use App\Filament\Resources\PaymentSubmissions\Pages\ViewPaymentSubmission;
use App\Filament\Resources\PaymentSubmissions\Tables\PaymentSubmissionsTable;
use App\Models\PaymentSubmissionOutbox;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The automated submission workflow, as the operator sees it.
 *
 * This resource exists because the lane that pays vendors unattended is
 * otherwise invisible: a payment goes out through a queue worker with nobody
 * watching, and the only evidence it happened is a row in a table. Showing the
 * state, the stage, the rail's own handle and the provider's own words in one
 * place is what makes "what is the automation doing right now" a answerable
 * question rather than an archaeology exercise.
 *
 * The resource is deliberately read-only. Nothing here may create, edit or
 * submit a payment. Writing is what the authorization workflow is for, and
 * giving an operator a button next to this table would put a path to the rail
 * one click from a dashboard.
 */
class PaymentSubmissionResource extends Resource
{
    protected static ?string $model = PaymentSubmissionOutbox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'request_key';

    #[\Override]
    public static function table(Table $table): Table
    {
        return PaymentSubmissionsTable::configure($table);
    }

    /**
     * View only.
     *
     * There is no create and no edit page, on purpose. A durable submission is
     * written by the authorization workflow inside its own transaction, and the
     * only actor permitted to advance one is the queue worker or reconciliation.
     *
     * @return array<string, PageRegistration>
     */
    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPaymentSubmissions::route('/'),
            'view' => ViewPaymentSubmission::route('/{record}'),
        ];
    }
}
