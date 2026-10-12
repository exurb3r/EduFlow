<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TuitionReceiptIntake;
use Illuminate\Console\Command;

/**
 * Recovery for committed settlements that no receipt covers yet.
 *
 * The after-commit wake is the normal delivery path. This sweep exists so a
 * broker outage, a disabled flag during a post, or a failed dispatch cannot
 * strand a verified movement outside Collections, and it is the only place
 * intake work is created without a payment being posted at that moment.
 */
class ReconcileTuitionReceipts extends Command
{
    protected $signature = 'eduflow:reconcile-tuition-receipts';

    protected $description = 'Redeliver committed pilot tuition settlements for reconciliation; recovery only, never a second receipt';

    public function handle(TuitionReceiptIntake $intake): int
    {
        if (! config('eduflow.student_tuition.auto_intake', false)) {
            $this->warn('Automatic tuition receipt intake is disabled; staff reconciliation remains available in Collections.');

            return self::SUCCESS;
        }

        $this->info('Dispatched '.$intake->dispatchDue().' tuition receipts for reconciliation.');

        return self::SUCCESS;
    }
}
