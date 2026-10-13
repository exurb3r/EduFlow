<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use App\Services\InstallationInstitution;
use App\Services\PaymentSettlementReconciler;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\confirm;

/**
 * Decide whether submitted references actually became money.
 *
 * This is the only command permitted to mark a submission settled, and it can
 * only do so on a `verified` verdict from the Arc settlement predicate. Every
 * other verdict leaves the entry unresolved with capacity still held.
 */
class ReconcilePaymentSubmissions extends Command
{
    protected $signature = 'eduflow:reconcile-payment-submissions
        {--limit=50 : Maximum entries to reconcile in one pass}
        {--mirror : Write a local ledger row for entries verified as settled}';

    protected $description = 'Verify submitted payment references against Arc and record only proven settlements';

    public function handle(PaymentSettlementReconciler $reconciler, InstallationInstitution $institutions): int
    {
        try {
            $institution = $institutions->require();
        } catch (ValidationException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('mirror') && ! confirm('Write local ledger rows for verified settlements? Amounts must be exactly representable in 2 decimals.', false)) {
            return self::SUCCESS;
        }

        $entries = PaymentSubmissionOutbox::query()
            ->where('organization_id', $institution->id)
            ->where(function (Builder $query): void {
                $query->whereIn('state', ['accepted', 'submitted'])
                    ->orWhere(function (Builder $uncertain): void {
                        $uncertain->where('state', 'unknown')
                            ->where(fn (Builder $identified): Builder => $identified->whereNotNull('provider_handle')
                                ->orWhereNotNull('result->provider_reference'));
                    });
            })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($entries->isEmpty()) {
            $this->info('No submitted references await verification.');

            return self::SUCCESS;
        }

        $verified = 0;
        $unresolved = 0;

        foreach ($entries as $entry) {
            $verdict = $reconciler->reconcile($entry);

            if (($verdict['settled'] ?? false) !== true) {
                $unresolved++;
                $this->warn(sprintf(
                    '  #%d %s — %s (capacity still held)',
                    $entry->id,
                    (string) ($verdict['verdict'] ?? 'unknown'),
                    (string) $verdict['reason'],
                ));

                continue;
            }

            $verified++;
            $mirrored = false;

            if ($this->option('mirror')) {
                $mirrored = $reconciler->mirrorSettlement($entry, $verdict) instanceof Transaction;
            }

            $this->info(sprintf(
                '  #%d verified in block %s — %s',
                $entry->id,
                (string) ($verdict['block_hash'] ?? '?'),
                $mirrored ? 'mirrored to ledger' : 'recorded on the entry only',
            ));
        }

        $this->info("Verified {$verified}; unresolved {$unresolved}. Unresolved work is still owed and stays held.");

        return self::SUCCESS;
    }
}
