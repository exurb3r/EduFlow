<?php

declare(strict_types=1);

use App\Filament\Resources\TuitionAccounts\Pages\CreateTuitionAccount;
use App\Filament\Resources\TuitionAccounts\Pages\EditTuitionAccount;
use App\Filament\Resources\TuitionAccounts\Pages\ListTuitionAccounts;
use App\Filament\Resources\TuitionAccounts\Pages\ViewTuitionAccount;
use App\Filament\Resources\TuitionAccounts\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Filament\Resources\TuitionAccounts\Widgets\TuitionAccountOverviewWidget;
use App\Filament\Resources\TuitionAccounts\Widgets\TuitionAccountsStatsWidget;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\TuitionAccount;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'finance_officer', 'student', 'user'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->term = AcademicTerm::factory()->create();
});

test('tuition accounts resource is registered in the admin and finance panels', function (): void {
    expect(TuitionAccountResource::getUrl('index', panel: 'admin'))->toContain('tuition-accounts')
        ->and(TuitionAccountResource::getUrl('index', panel: 'finance'))->toContain('tuition-accounts');
});

test('staff can open the tuition accounts list in both panels', function (string $role, string $panel): void {
    $this->actingAs(User::factory()->create()->assignRole($role));

    $this->get(TuitionAccountResource::getUrl('index', panel: $panel))->assertSuccessful();
})->with([
    ['super_admin', 'admin'],
    ['super_admin', 'finance'],
    ['admin', 'finance'],
    ['finance_officer', 'finance'],
]);

test('nonstaff cannot open the tuition accounts list', function (string $role): void {
    $this->actingAs(User::factory()->create()->assignRole($role));

    $this->get(TuitionAccountResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $this->get(TuitionAccountResource::getUrl('index', panel: 'finance'))->assertForbidden();
})->with(['student', 'user']);

test('staff can set a tuition balance with exact base-unit storage', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $student = Student::factory()->create();

    Livewire::test(CreateTuitionAccount::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'academic_term_id' => $this->term->getKey(),
            'total_amount' => '300.00',
            'paid_amount' => '25.50',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TuitionAccount::query()->count())->toBe(1);

    $account = TuitionAccount::query()->firstOrFail();
    expect($account->total_amount)->toBe(300000000)
        ->and($account->paid_amount)->toBe(25500000)
        ->and($account->remainingAmount())->toBe(274500000);
});

test('paid amount cannot exceed total tuition', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $student = Student::factory()->create();

    Livewire::test(CreateTuitionAccount::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'academic_term_id' => $this->term->getKey(),
            'total_amount' => '100.00',
            'paid_amount' => '100.01',
        ])
        ->call('create')
        ->assertHasFormErrors(['paid_amount']);

    expect(TuitionAccount::query()->count())->toBe(0);
});

test('duplicate student and term accounts are rejected', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $account = TuitionAccount::factory()->create();

    Livewire::test(CreateTuitionAccount::class)
        ->fillForm([
            'student_id' => $account->student_id,
            'academic_term_id' => $account->academic_term_id,
            'total_amount' => '300.00',
            'paid_amount' => '0.00',
        ])
        ->call('create')
        ->assertHasFormErrors(['student_id']);

    expect(TuitionAccount::query()->count())->toBe(1);
});

test('staff can update the paid amount from the edit page', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    filament()->setCurrentPanel('admin');

    $account = TuitionAccount::factory()->create([
        'total_amount' => 300000000,
        'paid_amount' => 0,
    ]);

    Livewire::test(EditTuitionAccount::class, ['record' => $account->getKey()])
        ->fillForm(['paid_amount' => '150.25'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh()->paid_amount)->toBe(150250000)
        ->and($account->fresh()->remainingAmount())->toBe(149750000);
});

test('staff can open the view tuition account page in both panels', function (string $role, string $panel): void {
    $this->actingAs(User::factory()->create()->assignRole($role));
    filament()->setCurrentPanel($panel);

    $account = TuitionAccount::factory()->create([
        'total_amount' => 500000000,
        'paid_amount' => 200000000,
    ]);

    $this->get(TuitionAccountResource::getUrl('view', ['record' => $account->getKey()], panel: $panel))
        ->assertSuccessful();

    Livewire::test(ViewTuitionAccount::class, ['record' => $account->getKey()])
        ->assertSuccessful()
        ->assertSee($account->student->user->name)
        ->assertSee($account->student->student_number)
        ->assertSee($account->academicTerm->name)
        ->assertSee('500.00 USDC')
        ->assertSee('200.00 USDC')
        ->assertSee('300.00 USDC')
        ->assertSee('Partially paid')
        ->assertSee('40% Settled');
})->with([
    ['super_admin', 'admin'],
    ['super_admin', 'finance'],
    ['admin', 'finance'],
    ['finance_officer', 'finance'],
]);

test('overview widget renders correct balance and settlement progress', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $account = TuitionAccount::factory()->create([
        'total_amount' => 1000000000,
        'paid_amount' => 1000000000,
    ]);

    Livewire::test(TuitionAccountOverviewWidget::class, ['record' => $account])
        ->assertSuccessful()
        ->assertSee('1,000.00 USDC')
        ->assertSee('Paid in full')
        ->assertSee('100% Complete');
});

test('staff can record payment from the view page action', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $account = TuitionAccount::factory()->create([
        'total_amount' => 300000000,
        'paid_amount' => 50000000,
    ]);

    Livewire::test(ViewTuitionAccount::class, ['record' => $account->getKey()])
        ->mountAction('recordPayment')
        ->set('mountedActions.0.data.amount', '100.00')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($account->fresh()->paid_amount)->toBe(150000000)
        ->and($account->fresh()->remainingAmount())->toBe(150000000);
});

test('recording payment rejects amount exceeding remaining balance', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $account = TuitionAccount::factory()->create([
        'total_amount' => 300000000,
        'paid_amount' => 200000000,
    ]);

    Livewire::test(ViewTuitionAccount::class, ['record' => $account->getKey()])
        ->mountAction('recordPayment')
        ->set('mountedActions.0.data.amount', '150.00')
        ->callMountedAction()
        ->assertHasActionErrors(['amount']);

    expect($account->fresh()->paid_amount)->toBe(200000000);
});

test('payments relation manager displays settled tuition receipts', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $account = TuitionAccount::factory()->create();
    $payment = StudentTuitionPayment::factory()->create([
        'tuition_account_id' => $account->id,
        'student_id' => $account->student_id,
        'amount_base_units' => 75000000,
        'applied_base_units' => 75000000,
        'tx_hash' => '0x'.str_repeat('f', 64),
        'tx_verified_on_chain' => true,
    ]);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewTuitionAccount::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$payment])
        ->assertSee('75.00 USDC');
});

test('tuition accounts stats widget renders directory totals', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    TuitionAccount::factory()->create([
        'total_amount' => 500000000,
        'paid_amount' => 200000000,
    ]);

    Livewire::test(TuitionAccountsStatsWidget::class)
        ->assertSuccessful()
        ->assertSee('Tuition Accounts')
        ->assertSee('Assessed Tuition')
        ->assertSee('Settled Payments')
        ->assertSee('Outstanding Receivables');
});

test('tuition accounts directory tabs filter records correctly on index page', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $term = AcademicTerm::factory()->create();
    $student1 = Student::factory()->create();
    $student2 = Student::factory()->create();

    $debtAccount = TuitionAccount::factory()->for($student1)->for($term)->create([
        'total_amount' => 500000000,
        'paid_amount' => 100000000,
    ]);

    $settledAccount = TuitionAccount::factory()->for($student2)->for($term)->create([
        'total_amount' => 300000000,
        'paid_amount' => 300000000,
    ]);

    Livewire::test(ListTuitionAccounts::class)
        ->assertSuccessful()
        ->set('activeTab', 'outstanding')
        ->assertCanSeeTableRecords([$debtAccount])
        ->assertCanNotSeeTableRecords([$settledAccount])
        ->set('activeTab', 'settled')
        ->assertCanSeeTableRecords([$settledAccount])
        ->assertCanNotSeeTableRecords([$debtAccount]);
});
