<?php

declare(strict_types=1);

use App\Actions\CaptureCollectionBatch;
use App\Actions\ImportTuitionPaymentAsCollection;
use App\Actions\ReviewCollectionBatch;
use App\Models\AcademicTerm;
use App\Models\CollectionBatch;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

/**
 * Reconciliation of verified student payments into Collections (PLAN §14.10 B1).
 *
 * The invariants under test are all about one thing: money that arrived by
 * chain must be able to enter the reviewed ledger exactly once, and must not be
 * able to enter it a second time by any route.
 */
const RECONCILE_TREASURY = '0x2629de3b67042842ab2a2e33a1bd81ca583d975c';

/** @return array{institution: Organization, cashier: User, reviewer: User} */
function reconcileContext(): array
{
    config([
        'eduflow.institution_id' => null,
        'lepton.default' => 'fake',
        'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.treasury' => RECONCILE_TREASURY,
    ]);

    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));

    /** @var Organization $institution */
    $institution = Organization::factory()->create(['currency' => 'PHP']);
    /** @var User $cashier */
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));

    Wallet::factory()->create([
        'organization_id' => $institution->id,
        'address' => RECONCILE_TREASURY,
        'balance' => 5.00,
        'status' => 'active',
    ]);

    return ['institution' => $institution, 'cashier' => $cashier, 'reviewer' => $reviewer];
}

/**
 * A student payment recorded as a settled arrival.
 *
 * The row is built directly rather than through the payment service: this file
 * is about what Collections does with a settled movement, and driving the whole
 * payment lane again would test that service twice over. What matters here is
 * that the record carries verified, non-simulated movement evidence.
 */
function settledPayment(int $amountBaseUnits = 5_000000, array $overrides = []): StudentTuitionPayment
{
    $student = Student::factory()->withoutPayoutAddress()->create();
    $wallet = StudentWallet::factory()->create([
        'student_id' => $student->id,
        'address' => '0x'.str_pad(dechex(crc32((string) Str::uuid())), 40, '0', STR_PAD_LEFT),
    ]);
    $account = TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    return StudentTuitionPayment::query()->create([
        'request_key' => (string) Str::uuid(),
        'student_id' => $student->id,
        'tuition_account_id' => $account->id,
        'student_wallet_id' => $wallet->id,
        'tx_hash' => '0x'.str_repeat('a', 64),
        'amount_base_units' => $amountBaseUnits,
        'applied_base_units' => $amountBaseUnits,
        'overpayment_base_units' => 0,
        'network_fee' => '0.02945113436514256',
        'payer_address' => $wallet->address,
        'recipient_address' => RECONCILE_TREASURY,
        'chain' => 'ARC-TESTNET',
        'tx_verified_on_chain' => true,
        'simulated' => false,
        'executed_at' => now()->subMinute(),
        ...$overrides,
    ]);
}

/** @param array{institution: Organization, cashier: User, reviewer: User} $c */
function reconcilePayment(array $c, StudentTuitionPayment $payment): CollectionBatch
{
    return app(ImportTuitionPaymentAsCollection::class)
        ->handle($c['cashier'], $payment, (string) Str::uuid());
}

/** @return array<string, mixed> */
function cashierTyping(string $reference = 'bank-credit-001'): array
{
    return ['capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-fees', 'source_reference' => $reference,
        'source_document_digest' => hash('sha256', 'synthetic-cashier-statement-'.Str::random(6)), 'currency' => 'PHP',
        'received_amount' => '100.00', 'restricted_amount' => '0',
        'collected_from' => now()->subDays(2)->toIso8601String(), 'collected_until' => now()->subDay()->toIso8601String(),
        'cash_evidence_reference' => 'synthetic-bank-credit', 'source_stream_disjoint' => true, 'received_not_forecast' => true];
}

// ---------------------------------------------------------------------------
// Importing a settled movement
// ---------------------------------------------------------------------------

test('a verified student payment reconciles into one immutable exact receipt', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000);

    $batch = reconcilePayment($c, $payment);

    expect($batch->hasValidSnapshot())->toBeTrue()
        ->and($batch->source_stream)->toBe(CollectionBatch::TUITION_PORTAL_STREAM)
        ->and($batch->currency)->toBe('USDC')
        ->and($batch->received_minor_units)->toBe(5_000000)
        ->and($batch->restricted_minor_units)->toBe(0)
        ->and($batch->student_tuition_payment_id)->toBe($payment->getKey())
        ->and($batch->isMovementSourced())->toBeTrue()
        ->and($batch->tuitionPayment->is($payment))->toBeTrue();

    // The receipt reconciles money already in the treasury. It must not claim a
    // second arrival, and it must carry the movement it rests on.
    expect($batch->snapshot['movement']['tx_hash'])->toBe($payment->tx_hash)
        ->and($batch->snapshot['evidence_origin'])->toBe('verified_chain_movement')
        ->and($batch->source_document_digest)->toBe(CollectionBatch::movementDigest($payment));
});

test('the treasury balance is not credited again by reconciling', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000);

    reconcilePayment($c, $payment);

    // 5 USDC is already in the balance from the settlement. Reconciling is a
    // bookkeeping step; crediting again is the double-count this whole lane
    // exists to prevent.
    expect((float) Wallet::query()->where('address', RECONCILE_TREASURY)->value('balance'))->toBe(5.00)
        ->and(Transaction::query()->count())->toBe(0);
});

test('an overpayment is held back rather than offered as unrestricted receipts', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(15_000000, ['applied_base_units' => 10_000000, 'overpayment_base_units' => 5_000000]);

    $batch = reconcilePayment($c, $payment);

    // The treasury did receive all 15, but 5 of it is owed back to the student.
    // Counting it as spendable is how a budget plans money it may have to return.
    expect($batch->received_minor_units)->toBe(15_000000)
        ->and($batch->restricted_minor_units)->toBe(5_000000)
        ->and($batch->evidence()['unrestricted_minor_units'])->toBe('0');
});

// ---------------------------------------------------------------------------
// What must never be reconciled
// ---------------------------------------------------------------------------

test('a rehearsal is never reconciled, because nothing settled', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000, ['simulated' => true, 'tx_verified_on_chain' => false]);

    expect(fn (): CollectionBatch => reconcilePayment($c, $payment))
        ->toThrow(ValidationException::class, 'simulation settles nothing');

    expect(CollectionBatch::query()->count())->toBe(0)
        ->and(app(ImportTuitionPaymentAsCollection::class)->eligiblePayments(RECONCILE_TREASURY)->count())->toBe(0);
});

test('a payment with a hash but no movement proof is never reconciled', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000, ['tx_verified_on_chain' => false]);

    expect(fn (): CollectionBatch => reconcilePayment($c, $payment))
        ->toThrow(ValidationException::class, 'verified on chain');

    expect(CollectionBatch::query()->count())->toBe(0);
});

test('a payment addressed elsewhere is not this installations money', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000, ['recipient_address' => '0x'.str_repeat('9', 40)]);

    expect(fn (): CollectionBatch => reconcilePayment($c, $payment))
        ->toThrow(ValidationException::class, 'not this institution');

    expect(CollectionBatch::query()->count())->toBe(0);
});

test('an unreconcilable payment is never offered on the screen', function (): void {
    reconcileContext();

    $settled = settledPayment(5_000000);
    settledPayment(5_000000, ['simulated' => true, 'tx_verified_on_chain' => false]);
    settledPayment(5_000000, ['recipient_address' => '0x'.str_repeat('9', 40)]);

    expect(app(ImportTuitionPaymentAsCollection::class)->eligiblePayments(RECONCILE_TREASURY)->pluck('id')->all())
        ->toBe([$settled->id]);
});

// ---------------------------------------------------------------------------
// Exactly once, by every route
// ---------------------------------------------------------------------------

test('the same movement reconciles once however many times it is retried', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000);

    $first = reconcilePayment($c, $payment);

    // A second attempt under a fresh identity is still the same cash.
    expect(fn (): CollectionBatch => reconcilePayment($c, $payment))
        ->toThrow(ValidationException::class, 'already reconciled');

    // A replay under the original identity returns the same receipt, so a
    // double-submitted form cannot produce a second one.
    $replay = app(ImportTuitionPaymentAsCollection::class)->handle($c['cashier'], $payment, $first->capture_key);

    expect($replay->is($first))->toBeTrue()
        ->and(CollectionBatch::query()->count())->toBe(1);
});

test('the database refuses a second receipt for the same movement', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000);

    $batch = reconcilePayment($c, $payment);

    // The application check is not the only guard: the unique index holds even
    // if a future caller reaches past the action.
    expect(fn () => DB::table('collection_batches')->insertGetId([
        'capture_key' => (string) Str::uuid(),
        'organization_id' => $batch->organization_id,
        'prepared_by' => $c['cashier']->id,
        'student_tuition_payment_id' => $payment->getKey(),
        'source_stream' => CollectionBatch::TUITION_PORTAL_STREAM,
        'source_reference' => 'other',
        'source_document_digest' => hash('sha256', 'other'),
        'currency' => 'USDC',
        'received_minor_units' => 5_000000,
        'restricted_minor_units' => 0,
        'collected_from' => now()->subMinutes(2),
        'collected_until' => now()->subMinute(),
        'snapshot' => '{}',
        'snapshot_digest' => hash('sha256', 'other'),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('two different movements each reconcile exactly once', function (): void {
    $c = reconcileContext();
    $first = settledPayment(5_000000);
    $second = settledPayment(7_000000, ['tx_hash' => '0x'.str_repeat('b', 64)]);

    reconcilePayment($c, $first);
    reconcilePayment($c, $second);

    expect(CollectionBatch::query()->count())->toBe(2)
        ->and(CollectionBatch::query()->sum('received_minor_units'))->toBe(12_000000);
});

// ---------------------------------------------------------------------------
// The manual path cannot record the same money again
// ---------------------------------------------------------------------------

test('a cashier cannot hand-type a receipt on the channel reserved for movements', function (): void {
    $c = reconcileContext();
    $input = cashierTyping();

    expect(fn () => app(CaptureCollectionBatch::class)->handle($c['cashier'], [
        ...$input,
        'source_stream' => CollectionBatch::TUITION_PORTAL_STREAM,
    ]))->toThrow(ValidationException::class, 'already settled on chain');

    expect(CollectionBatch::query()->count())->toBe(0);
});

test('a cashier cannot re-enter a settled movement as a bank credit', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000);

    // This is the realistic double-count: the cashier has the explorer hash in
    // front of them and types it as the receipt number.
    expect(fn () => app(CaptureCollectionBatch::class)->handle($c['cashier'], cashierTyping((string) $payment->tx_hash)))
        ->toThrow(ValidationException::class, 'not a new cash arrival');

    expect(CollectionBatch::query()->count())->toBe(0);

    // And the correct route still works.
    expect(reconcilePayment($c, $payment)->received_minor_units)->toBe(5_000000);
});

test('a manual receipt for an unrelated amount is unaffected', function (): void {
    $c = reconcileContext();
    settledPayment(5_000000);

    // Local-currency receipts are the normal case and must keep working
    // alongside a USDC movement without being blocked or merged with it.
    $batch = app(CaptureCollectionBatch::class)->handle($c['cashier'], cashierTyping());

    expect($batch->source_stream)->toBe('cashier-fees')
        ->and($batch->currency)->toBe('PHP')
        ->and($batch->isMovementSourced())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Review still governs financial use
// ---------------------------------------------------------------------------

test('a reconciled movement needs a separate reviewer before it can supply cash', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000);
    $batch = reconcilePayment($c, $payment);

    // Reconciling creates no authority: the receipt is unreviewed, so it
    // contributes nothing to planning until someone independent signs it.
    expect($batch->evidence()['receipts_reviewed'])->toBeFalse()
        ->and($batch->evidence()['unrestricted_minor_units'])->toBe('0');

    // The preparer cannot review their own reconciliation.
    expect(fn () => app(ReviewCollectionBatch::class)->handle($c['cashier'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'synthetic-check', 'Self review.'))->toThrow(Exception::class);

    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'synthetic-independent-accounting-check', 'Source, payer and restrictions checked against the movement.');

    expect($review->decision)->toBe('approve_receipts')
        ->and($batch->fresh()->evidence()['unrestricted_minor_units'])->toBe('5000000')
        // Reconciliation never becomes payment authority, whatever is reviewed.
        ->and($batch->evidence()['can_execute'])->toBeFalse()
        ->and($batch->evidence()['accounts_changed'])->toBeFalse();
});

test('a reconciled receipt keeps the cashier screen working with no students at all', function (): void {
    $c = reconcileContext();

    // The aggregate planning lane must not depend on a single student existing:
    // this is the zero-student case the pilot's cash review depends on.
    $batch = app(CaptureCollectionBatch::class)->handle($c['cashier'], cashierTyping());
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'synthetic-independent-cashier-check', 'Aggregate receipts checked.');

    expect($review->decision)->toBe('approve_receipts')
        ->and(StudentTuitionPayment::query()->count())->toBe(0)
        ->and(CollectionBatch::query()->whereNotNull('student_tuition_payment_id')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Tamper resistance
// ---------------------------------------------------------------------------

test('a receipt whose snapshot no longer matches its movement is not valid evidence', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000);
    $batch = reconcilePayment($c, $payment);

    $tampered = $batch->snapshot;
    $tampered['movement']['amount_base_units'] = 9_000000;

    // The snapshot is immutable through the model, so the tampered copy is
    // proved invalid by pointing the digest check at it directly.
    $probe = $batch->replicate();
    $probe->setRawAttributes(array_merge($batch->getAttributes(), ['snapshot' => json_encode($tampered)]));

    expect($probe->hasValidSnapshot())->toBeFalse()
        ->and($batch->fresh()->hasValidSnapshot())->toBeTrue();
});

test('a receipt with no movement link cannot claim movement evidence', function (): void {
    $c = reconcileContext();
    $payment = settledPayment(5_000000);
    $batch = reconcilePayment($c, $payment);

    $probe = $batch->replicate();
    $probe->setRawAttributes(array_merge($batch->getAttributes(), ['student_tuition_payment_id' => null]));

    expect($probe->hasValidSnapshot())->toBeFalse();
});
