<?php

declare(strict_types=1);

use App\Actions\CaptureCollectionBatch;
use App\Actions\ImportTuitionPaymentAsCollection;
use App\Actions\ReviewCollectionBatch;
use App\Filament\Pages\Collections;
use App\Jobs\ReconcileStudentBillReceipt;
use App\Models\CollectionBatch;
use App\Models\PaymentIntent;
use App\Models\StudentBill;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReviewedCollections;
use App\Services\StudentBillPaymentQuote;
use App\Services\StudentBillReceiptIntake;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

test('verified bill receipt enters reviewed planning cash once with overpayment restricted', function (): void {
    $c = billPaymentContext();
    $payment = billConsent($c);
    $service = app(StudentBillPaymentQuote::class);
    $service->recordSettlement($payment, billSettlementStub($c, $payment));
    $c['bill']->update(['status' => StudentBill::STATUS_VOID]);
    $payment = $service->allocate($payment->fresh());
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $reviewer = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
    $import = app(ImportTuitionPaymentAsCollection::class);
    $key = (string) Str::uuid();

    $batch = $import->handle($maker, $payment, $key);
    expect($import->handle($maker, $payment, $key)->id)->toBe($batch->id)
        ->and($batch->hasValidSnapshot())->toBeTrue()->and($batch->isMovementSourced())->toBeTrue()
        ->and($batch->student_bill_payment_id)->toBe($payment->id)->and($batch->student_tuition_payment_id)->toBeNull()
        ->and($batch->received_minor_units)->toBe(5_000000)->and($batch->restricted_minor_units)->toBe(5_000000)
        ->and($batch->billPayment->id)->toBe($payment->id)->and($payment->collections()->count())->toBe(1);

    $review = app(ReviewCollectionBatch::class)->handle($reviewer, $batch, $batch->snapshot_digest,
        'approve_receipts', 'synthetic-independent-check', 'Bill proof and student liability reviewed.');
    $entries = app(ReviewedCollections::class)->capture($payment->organization_id, 'USDC', [$review->id],
        now()->utc()->toIso8601String(), '5000000', '5000000');
    expect($entries[0]['movement_receipt_minor_units'])->toBe(5_000000)
        ->and($entries[0]['restricted_minor_units'])->toBe('5000000')
        ->and($batch->evidence()['unrestricted_minor_units'])->toBe('0')
        ->and($payment->authorizesOutgoingSpend())->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0)
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0);
    expect(fn () => $import->handle($maker, $payment, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect($import->eligibleBillPayments((string) config('lepton.arc.treasury'))->count())->toBe(0);
});

test('bill collection refuses a verification flag without immutable proof or allocation', function (string $case): void {
    $c = billPaymentContext();
    $payment = billConsent($c);
    $service = app(StudentBillPaymentQuote::class);
    if ($case !== 'flag_only') {
        $service->recordSettlement($payment, billSettlementStub($c, $payment));
        if ($case !== 'unallocated') {
            $payment = $service->allocate($payment->fresh());
        }
    }
    if ($case === 'flag_only') {
        DB::table('student_bill_payments')->where('id', $payment->id)->update(['tx_verified_on_chain' => true,
            'tx_hash' => '0x'.str_repeat('f', 64), 'executed_at' => now(), 'allocated_at' => now()]);
    } elseif ($case === 'tampered_proof') {
        DB::table('student_bill_payments')->where('id', $payment->id)->update(['settlement_evidence_digest' => str_repeat('0', 64)]);
    } elseif ($case === 'simulation') {
        DB::table('student_bill_payments')->where('id', $payment->id)->update(['simulated' => true]);
    }
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));

    expect(fn () => app(ImportTuitionPaymentAsCollection::class)->handle($maker, $payment->fresh(), (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(CollectionBatch::query()->count())->toBe(0);
})->with(['flag_only', 'unallocated', 'tampered_proof', 'simulation']);

test('manual collection cannot claim bill stream or reuse settled bill hash', function (string $case): void {
    $c = billPaymentContext();
    $payment = billSettledPayment($c);
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $data = ['source_stream' => $case === 'stream' ? CollectionBatch::BILL_PORTAL_STREAM : 'cashier-fees',
        'source_reference' => $case === 'hash' ? $payment->tx_hash : 'synthetic-report'];

    expect(fn () => app(CaptureCollectionBatch::class)->handle($maker, $data))->toThrow(ValidationException::class);
    expect(CollectionBatch::query()->count())->toBe(0);
})->with(['stream', 'hash']);

test('one chain hash cannot enter Collections again through a different student payment lane', function (): void {
    $c = billPaymentContext();
    $payment = billSettledPayment($c);
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    app(ImportTuitionPaymentAsCollection::class)->handle($maker, $payment, (string) Str::uuid());
    $tuition = StudentTuitionPayment::factory()->create(['tx_hash' => $payment->tx_hash,
        'recipient_address' => $payment->recipient_address, 'payer_address' => $payment->payer_address,
        'simulated' => false, 'tx_verified_on_chain' => true, 'amount_base_units' => 5_000000,
        'applied_base_units' => 5_000000, 'overpayment_base_units' => 0]);

    expect(fn () => app(ImportTuitionPaymentAsCollection::class)->handle($maker, $tuition, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(CollectionBatch::query()->count())->toBe(1);
});

test('bill collection rechecks its linked proof and database enforces one receipt per payment', function (): void {
    $c = billPaymentContext();
    $payment = billSettledPayment($c);
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $batch = app(ImportTuitionPaymentAsCollection::class)->handle($maker, $payment, (string) Str::uuid());
    $copy = $batch->getAttributes();
    unset($copy['id']);
    $copy['capture_key'] = (string) Str::uuid();
    $copy['source_reference'] = 'different-reference';
    $copy['source_document_digest'] = hash('sha256', 'different-document');
    expect(fn () => DB::table('collection_batches')->insert($copy))->toThrow(QueryException::class);

    DB::table('student_bill_payments')->where('id', $payment->id)->update(['settlement_evidence_digest' => str_repeat('0', 64)]);
    expect($batch->fresh()->hasValidSnapshot())->toBeFalse();

    $forged = $batch->fresh();
    $forged->snapshot = [...$forged->snapshot, 'evidence_origin' => 'staff_attestation'];
    $forged->snapshot_digest = PaymentIntent::digest($forged->snapshot);
    expect($forged->hasValidSnapshot())->toBeFalse();
});

test('bill receipt migration rollback preserves pre-existing exact collection guards', function (): void {
    $migration = require base_path('database/migrations/2026_10_13_125654_add_student_bill_payment_link_to_collection_batches_table.php');
    $migration->down();
    expect(Schema::hasColumn('collection_batches', 'student_bill_payment_id'))->toBeFalse();
    $triggers = DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'collection_batches_bounds_%'");
    expect(array_column($triggers, 'name'))->toContain('collection_batches_bounds_INSERT', 'collection_batches_bounds_UPDATE');
    $migration->up();
    expect(Schema::hasColumn('collection_batches', 'student_bill_payment_id'))->toBeTrue();
});

test('bill allocation wakes default-off intake and recovery converges on one unreviewed receipt', function (): void {
    $c = billPaymentContext();
    $owner = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    config(['eduflow.student_bills.auto_intake' => true, 'eduflow.student_bills.intake_owner_id' => $owner->id,
        'queue.connections.database.retry_after' => 360]);
    Bus::fake();
    $payment = billSettledPayment($c);
    Bus::assertDispatched(ReconcileStudentBillReceipt::class, fn (ReconcileStudentBillReceipt $job): bool => $job->paymentId === $payment->id);
    $intake = app(StudentBillReceiptIntake::class);
    expect($intake->dispatchDue())->toBe(1);
    $job = new ReconcileStudentBillReceipt($payment->id);
    $job->handle($intake);
    $job->handle($intake);
    $batch = CollectionBatch::query()->sole();

    expect($intake->reconcile($payment)->id)->toBe($batch->id)
        ->and($batch->capture_key)->toBe($intake->captureKey($payment))
        ->and($batch->prepared_by)->toBe($owner->id)
        ->and($batch->evidence()['receipts_reviewed'])->toBeFalse()
        ->and($intake->dispatchDue())->toBe(0)->and(Transaction::query()->count())->toBe(0);
    $this->artisan('eduflow:reconcile-student-bill-receipts')->assertSuccessful();
});

test('bill intake refuses disabled or improper owner without undoing payment allocation', function (string $case): void {
    $c = billPaymentContext();
    $payment = billSettledPayment($c);
    $owner = $case === 'payer' ? $c['student']->user : User::factory()->unverified()->create();
    $owner->assignRole(Role::findOrCreate('finance_officer', 'web'));
    config(['eduflow.student_bills.auto_intake' => $case !== 'disabled',
        'eduflow.student_bills.intake_owner_id' => $case === 'missing' ? null : $owner->id]);

    expect(fn () => app(StudentBillReceiptIntake::class)->reconcile($payment))->toThrow(ValidationException::class);
    expect(CollectionBatch::query()->count())->toBe(0)->and($payment->fresh()->isAllocated())->toBeTrue()
        ->and($c['bill']->fresh()->paid_base_units)->toBe(5_000000);
})->with(['disabled', 'missing', 'unverified', 'payer']);

test('finance reconciles bill receipt from existing Collections form without sending money', function (): void {
    $c = billPaymentContext();
    $payment = billSettledPayment($c);
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    Filament::setCurrentPanel('finance');
    $this->actingAs($maker);
    $page = Livewire::test(Collections::class);
    $page->__call('callAction', ['reconcileTuitionPayment', ['payment_kind' => 'bill', 'payment_id' => $payment->id]]);
    $page->__call('assertHasNoActionErrors', []);

    expect(CollectionBatch::query()->sole()->student_bill_payment_id)->toBe($payment->id)
        ->and(Transaction::query()->count())->toBe(0);
});
