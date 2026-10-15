<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FinanceWorkflowDispatcher;
use Illuminate\Console\Command;
use Throwable;

class DispatchFinanceWorkflows extends Command
{
    protected $signature = 'app:dispatch-finance-workflows';

    protected $aliases = ['eduflow:dispatch-finance-workflows'];

    protected $description = 'Recover and dispatch bounded read-only finance work; never executes payments.';

    public function handle(FinanceWorkflowDispatcher $dispatcher): int
    {
        try {
            $this->info('Finance planning jobs dispatched: '.$dispatcher->dispatch().'. No payment execution.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Finance dispatch unavailable. Check enablement, institution, migrations and durable queue configuration.');

            return self::FAILURE;
        }
    }
}
