<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StudentBillPaymentDispatcher;
use Illuminate\Console\Command;

/**
 * Recovery for consented bill payments no worker has taken.
 *
 * The after-commit wake is the normal delivery path. This sweep exists so a
 * broker outage, a lane disabled at consent time, or a failed dispatch cannot
 * strand a student's payment outside the queue.
 */
class DispatchStudentBillPayments extends Command
{
    protected $signature = 'eduflow:dispatch-student-bill-payments';

    protected $description = 'Redeliver consented bill payments; recovery only, and never a second transfer';

    public function handle(StudentBillPaymentDispatcher $dispatcher): int
    {
        if (! config('eduflow.student_bills.enabled', false) && ! config('eduflow.student_bills.observe_enabled', false)) {
            $this->warn('Bill payment submission is disabled; consent is retained and staff reconciliation remains available.');

            return self::SUCCESS;
        }

        $this->info('Dispatched '.$dispatcher->dispatchDue().' bill payment(s).');

        return self::SUCCESS;
    }
}
