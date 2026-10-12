<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\InstallationInstitution;
use App\Services\PaymentSubmissionDispatch;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class DispatchPaymentSubmissions extends Command
{
    protected $signature = 'eduflow:dispatch-payment-submissions {--limit=50} {--recover-stale}';

    protected $description = 'Hand durably owed payment submissions to the queue and recover expired worker leases';

    public function handle(PaymentSubmissionDispatch $dispatch, InstallationInstitution $institutions): int
    {
        try {
            $institution = $institutions->require();

            if ($this->option('recover-stale')) {
                $recovered = $dispatch->recoverStaleLeases();
                $this->info("Recovered {$recovered} expired submission lease(s) to unknown; reconcile before any retry.");

                return self::SUCCESS;
            }

            $dispatched = $dispatch->dispatch(max(1, (int) $this->option('limit')));
            $this->info("Dispatched {$dispatched} owed submission(s) for {$institution->name}. Nothing was submitted.");

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }
    }
}
