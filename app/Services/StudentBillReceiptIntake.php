<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ImportTuitionPaymentAsCollection;
use App\Jobs\ReconcileStudentBillReceipt;
use App\Models\CollectionBatch;
use App\Models\StudentBillPayment;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Source-linked intake only; independent receipt review and payment authority remain separate. */
final readonly class StudentBillReceiptIntake
{
    public function __construct(private ImportTuitionPaymentAsCollection $import) {}

    public function captureKey(StudentBillPayment $payment): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:bill-intake:'.$payment->movement_key)->toString();
    }

    public function reconcile(StudentBillPayment $payment): CollectionBatch
    {
        if (! config('eduflow.student_bills.auto_intake', false)) {
            throw ValidationException::withMessages(['intake' => 'Automatic bill receipt intake is disabled; staff reconciliation remains available.']);
        }
        if (! $payment->hasValidSettlement() || ! $payment->isAllocated()
            || ! is_string($payment->movement_key) || $payment->movement_key === '') {
            throw ValidationException::withMessages(['intake' => 'Bill intake requires immutable consent, verified settlement proof and posted allocation.']);
        }

        $owner = User::query()->find(config('eduflow.student_bills.intake_owner_id'));
        if (! $owner instanceof User || ! $owner->hasVerifiedEmail()
            || ! $owner->hasAnyRole(['finance_officer', 'admin', 'super_admin']) || $owner->id === $payment->student->user_id) {
            throw ValidationException::withMessages(['intake' => 'Name a verified finance intake owner who is not the payer.']);
        }

        return $this->import->handle($owner, $payment, $this->captureKey($payment));
    }

    public function wakeAfterCommit(int $paymentId): void
    {
        if (config('eduflow.student_bills.auto_intake', false)) {
            DB::afterCommit(fn (): bool => $this->deliver($paymentId));
        }
    }

    public function dispatchDue(): int
    {
        if (! config('eduflow.student_bills.auto_intake', false)) {
            return 0;
        }

        $count = 0;
        $payments = $this->import->eligibleBillPayments((string) config('lepton.arc.treasury'))
            ->whereNotNull('movement_key')->reorder('id')->limit(50)->get();
        foreach ($payments as $payment) {
            if ($payment->hasValidSettlement()) {
                $count += (int) $this->deliver($payment->id);
            }
        }

        return $count;
    }

    public function deliver(int $paymentId): bool
    {
        if (! config('eduflow.student_bills.auto_intake', false)) {
            return false;
        }

        $connection = (string) config('eduflow.student_bills.queue_connection', 'database');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 120) {
            report(new \RuntimeException('Bill receipt intake needs a durable queue with retry_after greater than 120 seconds; nothing dispatched.'));

            return false;
        }

        try {
            Bus::dispatch(new ReconcileStudentBillReceipt($paymentId));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
