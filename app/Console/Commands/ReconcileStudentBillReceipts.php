<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StudentBillReceiptIntake;
use Illuminate\Console\Command;

class ReconcileStudentBillReceipts extends Command
{
    protected $signature = 'eduflow:reconcile-student-bill-receipts';

    protected $description = 'Recover verified bill receipts missing from Collections; never creates a second cash arrival';

    public function handle(StudentBillReceiptIntake $intake): int
    {
        if (! config('eduflow.student_bills.auto_intake', false)) {
            $this->warn('Automatic bill receipt intake is disabled; staff reconciliation remains available in Collections.');

            return self::SUCCESS;
        }

        $this->info('Dispatched '.$intake->dispatchDue().' bill receipts for reconciliation.');

        return self::SUCCESS;
    }
}
