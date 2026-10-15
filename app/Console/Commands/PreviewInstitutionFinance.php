<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\InstallationInstitution;
use App\Services\InstitutionFinancePreview;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:finance-preview {--days=30 : Indicative forecast horizon, between 1 and 90 days}', aliases: ['eduflow:finance-preview'])]
#[Description('Observe institution treasury and review vendor bills without approving, reserving or sending money.')]
class PreviewInstitutionFinance extends Command
{
    public function handle(InstitutionFinancePreview $preview, InstallationInstitution $institutions): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);

        if ($days === false) {
            $this->error('Preview horizon must be between 1 and 90 days.');

            return self::FAILURE;
        }

        try {
            $institution = $institutions->require();
            $report = $preview->handle($institution, $days);

            activity('finance')->performedOn($institution)->event('finance_previewed')
                ->withProperties([
                    'source' => 'operator_cli',
                    'mode' => 'preview_only',
                    'activity' => $report['activity'],
                    'invoices_reviewed' => count($report['invoice_reviews']),
                    'payments_submitted' => 0,
                ])->log('Institution finance preview; no funds reserved or payments submitted');

            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Finance preview failed; no payment was submitted. Check institution configuration and stored monetary precision.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
