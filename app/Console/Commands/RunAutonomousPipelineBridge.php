<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StudentTuitionPayment;
use App\Services\AutonomousPipelineBridge;
use Illuminate\Console\Command;
use Throwable;

final class RunAutonomousPipelineBridge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eduflow:autonomous-pipeline-bridge
        {--payment= : The specific StudentTuitionPayment ID to bridge}
        {--invoice= : The target vendor invoice ID to settle}
        {--latest : Run on the latest settled student tuition payment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the Autonomous Pipeline Bridge: seamlessly reconcile tuition receipts, evaluate JEV policy, and disburse to approved vendors';

    /**
     * Execute the console command.
     */
    public function handle(AutonomousPipelineBridge $bridge): int
    {
        $this->info('Starting EduFlow Autonomous Pipeline Bridge...');

        $paymentId = $this->option('payment');
        $invoiceId = $this->option('invoice') ? (int) $this->option('invoice') : null;
        $latest = (bool) $this->option('latest');

        try {
            if ($paymentId !== null) {
                $payment = StudentTuitionPayment::query()->findOrFail((int) $paymentId);
                $this->info("Bridging specific payment #{$payment->id} (Amount: ".number_format($payment->amount_base_units / 1_000_000, 2).' USDC)...');
                $result = $bridge->bridgePayment($payment, $invoiceId);
            } elseif ($latest) {
                $this->info('Finding latest settled student tuition payment...');
                $result = $bridge->bridgeLatest($invoiceId);
            } else {
                $this->info('Attempting latest settled student payment by default...');
                $result = $bridge->bridgeLatest($invoiceId);
            }

            $this->newLine();
            $this->info('=== Autonomous Pipeline Bridge Telemetry ===');
            $this->line('Stage:                '.($result['stage'] ?? 'unknown'));
            $this->line('Payment ID:           '.($result['payment_id'] ?? 'none'));
            $this->line('Tuition Amount:       '.(isset($result['tuition_amount_usdc']) ? ($result['tuition_amount_usdc'].' USDC') : 'N/A'));
            $this->line('Collection Batch:     #'.($result['collection_batch_id'] ?? 'none'));
            $this->line('Budget Snapshot:      #'.($result['budget_snapshot_id'] ?? 'none'));

            if (isset($result['invoice_reference'])) {
                $this->line('Vendor Bill:          '.$result['invoice_reference'].' ('.$result['vendor_name'].')');
                $this->line('Disbursed Amount:     '.$result['disbursed_amount_usdc'].' USDC');
                $this->line('Recipient SCA:        '.$result['vendor_payout_address']);
                $this->line('Outbox State:         '.$result['outbox_state'].' ('.$result['outbox_stage'].')');
                $this->line('Disbursement Tx:      '.($result['disbursement_tx_hash'] ?? 'pending / provider handle: '.($result['provider_handle'] ?? 'none')));
                $this->line('Settled on Arc:       '.($result['settled'] ? 'YES (Verified)' : 'Pending on-chain verification'));
                $this->line('Invoice Status:       '.$result['invoice_status']);
            } elseif (isset($result['reason'])) {
                $this->warn('Notice: '.$result['reason']);
            }

            $this->newLine();
            $this->info('Autonomous Pipeline Bridge execution finished successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Autonomous Pipeline Bridge error: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
    }
}
