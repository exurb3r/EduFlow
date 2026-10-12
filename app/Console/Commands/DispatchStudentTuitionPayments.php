<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StudentTuitionPaymentDispatcher;
use Illuminate\Console\Command;

class DispatchStudentTuitionPayments extends Command
{
    protected $signature = 'eduflow:dispatch-student-tuition';

    protected $description = 'Dispatch consented pilot tuition work; recovery observes existing attempts and never resends';

    public function handle(StudentTuitionPaymentDispatcher $dispatcher): int
    {
        $this->info('Dispatched '.$dispatcher->dispatch().' tuition work items. No new payment consent created.');

        return self::SUCCESS;
    }
}
