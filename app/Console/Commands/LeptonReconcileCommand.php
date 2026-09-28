<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\LeptonReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lepton:reconcile {--limit=200 : Maximum transactions to check} {--json : Machine-readable output} {--fix : Mark fabricated receipts as failed}')]
#[Description('Prove which recorded transactions actually settled on Arc.')]
class LeptonReconcileCommand extends Command
{
    public function handle(LeptonReconciliationService $reconciler): int
    {
        $report = $reconciler->verifyAll(limit: (int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'verdict' => $report['verdict'],
                'total' => $report['total'],
                'verified' => $report['verified'],
                'fabricated' => $report['fabricated'],
                'unverifiable' => $report['unverifiable'],
                'ledger_only' => $report['ledger_only'],
                'items' => $report['items']->map(fn (array $i): array => [
                    'id' => $i['transaction']->id,
                    'type' => $i['transaction']->type instanceof TransactionType ? $i['transaction']->type->value : (string) $i['transaction']->type,
                    'amount' => (float) $i['transaction']->amount,
                    'hash' => $i['checked'],
                    'verdict' => $i['verdict'],
                    'block' => $i['block'],
                    'reason' => $i['reason'],
                ])->all(),
            ], JSON_PRETTY_PRINT));

            return $report['verdict'] === 'clean' ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('  Arc settlement reconciliation');
        $this->line('  '.str_repeat('─', 72));

        $symbols = [
            'verified' => '<fg=green>✓</>',
            'fabricated' => '<fg=red>✗</>',
            'unverifiable' => '<fg=yellow>?</>',
            'ledger_only' => '<fg=gray>-</</>',
        ];

        foreach ($report['items'] as $item) {
            /** @var Transaction $transaction */
            $transaction = $item['transaction'];
            $type = $transaction->type instanceof TransactionType ? $transaction->type->value : (string) $transaction->type;

            $this->line(sprintf(
                '  %s  #%-4d %-19s %9.2f  %-12s %s',
                $symbols[$item['verdict']] ?? '?',
                $transaction->id,
                $type,
                (float) $transaction->amount,
                $item['verdict'],
                substr((string) $item['checked'], 0, 22),
            ));
        }

        $this->newLine();
        $this->line(sprintf('  %d verified · %d fabricated · %d unverifiable · %d ledger-only',
            $report['verified'], $report['fabricated'], $report['unverifiable'], $report['ledger_only']));
        $this->line('  '.str_repeat('─', 72));

        if ($report['fabricated'] > 0) {
            $this->line('  <fg=red>These hashes are absent from the chain. They were never settled.</>');
            $this->line('  <fg=gray>They were most likely produced by a fake-driver run. Fix with:</>');
            $this->line('  <fg=gray>php artisan lepton:reconcile --fix</>');
        }

        if ($report['unverifiable'] > 0) {
            $this->line('  <fg=yellow>Some receipts could not be checked. Ensure the Circle agent session is active.</>');
        }

        if ($this->option('fix') && $report['fabricated'] > 0) {
            $fixed = 0;

            foreach ($report['items'] as $item) {
                /** @var Transaction $transaction */
                $transaction = $item['transaction'];

                if ($item['verdict'] !== 'fabricated') {
                    continue;
                }

                $transaction->update([
                    'status' => TransactionStatus::FAILED,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'reconciliation' => 'failed',
                        'reconciled_at' => now()->toIso8601String(),
                        'reconciliation_note' => 'Hash absent from chain; receipt never settled.',
                    ]),
                ]);

                $fixed++;
            }

            $this->line(sprintf('  <fg=yellow>Marked %d transaction(s) as failed.</>', $fixed));
        }

        $this->newLine();

        return $report['verdict'] === 'clean' ? self::SUCCESS : self::FAILURE;
    }
}
