<?php

declare(strict_types=1);

use App\Enums\StudentBillCategory;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\CarbonInterface;
use LogicException;

/**
 * A bill is a record that a debt exists. Nothing in this lane proves money
 * arrived, so every test below also holds the line that writing a bill or
 * moving its status writes no ledger and moves no balance.
 */
test('a bill reports its exact remaining amount with no float drift', function (): void {
    $student = Student::factory()->create();

    $ordinary = StudentBill::factory()->for($student)->create([
        'amount_base_units' => 1_234_567_890_123,
        'paid_base_units' => 1_234_567_890_122,
    ]);

    // 2,000,000,000.000001 USDC. At this magnitude a float64 steps in blocks of
    // 256 base units, so a value that only ever passed through a float would
    // silently lose the last unit of the charge.
    $precise = StudentBill::factory()->for($student)->create([
        'amount_base_units' => 2_000_000_000_000_000_001,
        'paid_base_units' => 0,
    ]);

    expect($ordinary->amount_base_units)->toBe(1_234_567_890_123)
        ->and($ordinary->remainingAmount())->toBe(1)
        ->and($precise->amount_base_units)->toBe(2_000_000_000_000_000_001)
        ->and($precise->fresh()->remainingAmount())->toBe(2_000_000_000_000_000_001)
        // Proof the exact figure is unreachable as a float: the round-trip loses
        // a whole base unit at the smallest representable step.
        ->and((int) (float) $precise->remainingAmount())->toBe(2_000_000_000_000_000_000);
});

test('remaining amount never goes negative when the payment exceeds the bill', function (): void {
    $bill = StudentBill::factory()->create([
        'amount_base_units' => 150_000_000,
        'paid_base_units' => 150_000_001,
    ]);

    expect($bill->remainingAmount())->toBe(0)
        ->and($bill->fresh()->remainingAmount())->toBe(0);
});

test('the amount cannot be rewritten once money has been applied', function (): void {
    $bill = StudentBill::factory()->create([
        'amount_base_units' => 150_000_000,
        'paid_base_units' => 50_000_000,
    ]);

    expect(fn () => $bill->update(['amount_base_units' => 150_000_001]))->toThrow(LogicException::class)
        // And the refusal is total: applying the payment and moving the amount
        // in one write is the same rewrite, not a loophole.
        ->and(fn () => $bill->update(['amount_base_units' => 160_000_000, 'paid_base_units' => 60_000_000]))->toThrow(LogicException::class)
        ->and(StudentBill::query()->find($bill->id)?->amount_base_units)->toBe(150_000_000)
        ->and($bill->fresh()->paid_base_units)->toBe(50_000_000);
});

test('a bill with nothing applied against it may still have its amount corrected', function (): void {
    $bill = StudentBill::factory()->create([
        'amount_base_units' => 150_000_000,
        'paid_base_units' => 0,
    ]);

    $bill->update(['amount_base_units' => 175_000_000]);

    expect($bill->fresh()->amount_base_units)->toBe(175_000_000)
        ->and($bill->fresh()->remainingAmount())->toBe(175_000_000)
        ->and($bill->fresh()->paid_base_units)->toBe(0);
});

test('sync status derives open then partially paid then paid from the amounts', function (): void {
    $bill = StudentBill::factory()->create([
        'amount_base_units' => 150_000_000,
        'paid_base_units' => 0,
    ]);

    expect($bill->status)->toBe(StudentBill::STATUS_OPEN);

    $bill->update(['paid_base_units' => 50_000_000]);
    expect($bill->syncStatus()->status)->toBe(StudentBill::STATUS_PARTIALLY_PAID)
        ->and($bill->fresh()->status)->toBe(StudentBill::STATUS_PARTIALLY_PAID)
        ->and($bill->remainingAmount())->toBe(100_000_000);

    $bill->update(['paid_base_units' => 150_000_000]);
    expect($bill->syncStatus()->status)->toBe(StudentBill::STATUS_PAID)
        ->and($bill->fresh()->status)->toBe(StudentBill::STATUS_PAID)
        ->and($bill->remainingAmount())->toBe(0);

    // An overpayment settles the bill too: the excess is a liability held for
    // the student, never a second bill and never a negative balance owed.
    $bill->update(['paid_base_units' => 160_000_000]);
    expect($bill->syncStatus()->status)->toBe(StudentBill::STATUS_PAID)
        ->and($bill->remainingAmount())->toBe(0);
});

test('a void bill is not resurrected by a status refresh', function (): void {
    $bill = StudentBill::factory()->create([
        'amount_base_units' => 150_000_000,
        'paid_base_units' => 0,
    ]);
    $bill->update(['paid_base_units' => 150_000_000]);
    $bill->syncStatus();

    expect($bill->status)->toBe(StudentBill::STATUS_PAID);

    $bill->update(['status' => StudentBill::STATUS_VOID]);

    // The amounts still read as fully paid, but arithmetic may not undo a
    // withdrawal: a void is a decision, not a derived value.
    expect($bill->syncStatus()->status)->toBe(StudentBill::STATUS_VOID)
        ->and($bill->fresh()->status)->toBe(StudentBill::STATUS_VOID)
        ->and($bill->fresh()->amount_base_units)->toBe(150_000_000);
});

test('writing and advancing a bill moves no balance and writes no ledger row', function (): void {
    $student = Student::factory()->create();
    Wallet::factory()->create(['balance' => 25_420.00]);
    $balance = Wallet::query()->sole()->balance;

    $bill = StudentBill::factory()->for($student)->create([
        'category' => StudentBillCategory::MISCELLANEOUS,
        'description' => 'Miscellaneous fee — laboratory access card',
        'amount_base_units' => 150_000_000,
        'paid_base_units' => 0,
        'due_date' => '2026-11-15',
    ]);

    $bill->update(['paid_base_units' => 50_000_000]);
    $bill->syncStatus();
    $bill->update(['paid_base_units' => 150_000_000]);
    $bill->syncStatus();

    expect(Transaction::query()->count())->toBe(0)
        ->and(Wallet::query()->count())->toBe(1)
        ->and(Wallet::query()->sole()->balance)->toBe($balance)
        ->and($bill->fresh()->status)->toBe(StudentBill::STATUS_PAID)
        ->and($bill->fresh()->due_date?->toDateString())->toBe('2026-11-15')
        ->and($bill->fresh()->category)->toBe(StudentBillCategory::MISCELLANEOUS)
        ->and($bill->fresh()->student->is($student))->toBeTrue();
});

test('a bill belongs to a student and optionally to an academic term', function (): void {
    $student = Student::factory()->create();
    $term = AcademicTerm::factory()->create();

    $withTerm = StudentBill::factory()->for($student)->for($term)->create();
    $withoutTerm = StudentBill::factory()->for($student)->create(['academic_term_id' => null]);
    // One student legitimately owes more than one charge of the same kind in a
    // term, so nothing here is unique over (student, category).
    $secondLaboratoryCharge = StudentBill::factory()->for($student)->for($term)->create([
        'category' => StudentBillCategory::LABORATORY,
    ]);

    expect($withTerm->student->is($student))->toBeTrue()
        ->and($withTerm->academicTerm?->is($term))->toBeTrue()
        ->and($withoutTerm->student->is($student))->toBeTrue()
        ->and($withoutTerm->academicTerm)->toBeNull()
        ->and($withoutTerm->due_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($withoutTerm->fresh()->due_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($secondLaboratoryCharge->category)->toBe(StudentBillCategory::LABORATORY)
        ->and($secondLaboratoryCharge->is($withTerm))->toBeFalse()
        ->and(StudentBill::query()->where('student_id', $student->id)->count())->toBe(3)
        ->and(StudentBill::query()->where('academic_term_id', $term->id)->count())->toBe(2);
});
