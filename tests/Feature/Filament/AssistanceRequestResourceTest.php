<?php

declare(strict_types=1);

use App\Enums\AssistanceStatus;
use App\Filament\Finance\Widgets\ApprovalInboxWidget;
use App\Filament\Finance\Widgets\ArcSettlementWidget;
use App\Filament\Finance\Widgets\AutonomyLaneWidget;
use App\Filament\Finance\Widgets\BudgetCapacityWidget;
use App\Filament\Finance\Widgets\BudgetHeadroomChart;
use App\Filament\Finance\Widgets\CollectionsOverviewWidget;
use App\Filament\Finance\Widgets\CollectionsTrendChart;
use App\Filament\Finance\Widgets\EvidenceTimelineWidget;
use App\Filament\Finance\Widgets\OperationsHealthWidget;
use App\Filament\Finance\Widgets\PaymentDecisionChart;
use App\Filament\Finance\Widgets\WorkflowRunStateChart;
use App\Filament\Finance\Widgets\WorkflowRunWidget;
use App\Filament\Pages\Collections;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Pages\PaymentReviews;
use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use App\Filament\Resources\AssistanceRequests\Pages\ListAssistanceRequests;
use App\Filament\Resources\AssistanceRequests\Pages\ViewAssistanceRequest;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Models\AssistanceRequest;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Policies\AssistanceRequestPolicy;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['admin', 'finance_officer', 'super_admin', 'student', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

test('assistance request resource is registered in admin panel', function (): void {
    $panel = Filament::getPanel('admin');
    $resources = $panel->getResources();

    $hasResource = collect($resources)->contains(
        fn ($resource): bool => $resource === AssistanceRequestResource::class
    );

    expect($hasResource)->toBeTrue();
});

test('super admin can access assistance requests page in admin panel', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $student = User::factory()->create(['name' => 'Student Alex']);
    $request = AssistanceRequest::factory()->create([
        'user_id' => $student->id,
        'subject' => 'Cannot view course timetable',
        'status' => AssistanceStatus::PENDING->value,
    ]);

    $response = $this->actingAs($admin)->get(AssistanceRequestResource::getUrl('index', panel: 'admin'));

    $response->assertSuccessful();
    $response->assertSee($request->ticket_number);
    $response->assertSee('Cannot view course timetable');
});

test('navigation badge reflects pending requests count', function (): void {
    AssistanceRequest::query()->delete();

    expect(AssistanceRequestResource::getNavigationBadge())->toBeNull();

    AssistanceRequest::factory()->create(['status' => AssistanceStatus::PENDING->value]);
    AssistanceRequest::factory()->create(['status' => AssistanceStatus::PENDING->value]);
    AssistanceRequest::factory()->create(['status' => AssistanceStatus::RESOLVED->value]);

    expect(AssistanceRequestResource::getNavigationBadge())->toBe('2');
});

test('finance panel exposes the supervisor dashboard, workflow pages and no plugins or clusters', function (): void {
    $panel = Filament::getPanel('finance');

    expect($panel->getResources())->toBe([StudentResource::class, TuitionAccountResource::class, AssistanceRequestResource::class])
        ->and($panel->getPages())->toBe([FinanceDashboard::class, FinanceSupervisor::class, Collections::class, PaymentReviews::class])
        ->and($panel->getClusters())->toBeEmpty()
        ->and($panel->getWidgets())->toEqualCanonicalizing([
            ApprovalInboxWidget::class,
            CollectionsOverviewWidget::class,
            CollectionsTrendChart::class,
            BudgetCapacityWidget::class,
            BudgetHeadroomChart::class,
            ArcSettlementWidget::class,
            WorkflowRunWidget::class,
            WorkflowRunStateChart::class,
            AutonomyLaneWidget::class,
            EvidenceTimelineWidget::class,
            PaymentDecisionChart::class,
            OperationsHealthWidget::class,
        ])
        ->and($panel->getPlugins())->toBeEmpty();
});

test('staff can access actual finance list and detail routes', function (string $role): void {
    $this->actingAs(User::factory()->create()->assignRole($role));
    $request = AssistanceRequest::factory()->create();

    $this->get(AssistanceRequestResource::getUrl('index', panel: 'finance'))->assertSuccessful();
    $this->get(AssistanceRequestResource::getUrl('view', ['record' => $request], panel: 'finance'))->assertSuccessful();
})->with(['admin', 'finance_officer', 'super_admin']);

test('nonstaff are denied actual finance routes including an owning student', function (string $role): void {
    $user = User::factory()->create()->assignRole($role);
    $student = Student::factory()->for($user)->create();
    $request = AssistanceRequest::factory()->for($student)->create();
    $this->actingAs($user);

    $this->get(AssistanceRequestResource::getUrl('index', panel: 'finance'))->assertForbidden();
    $this->get(AssistanceRequestResource::getUrl('view', ['record' => $request], panel: 'finance'))->assertForbidden();
})->with(['student', 'user']);

test('finance staff cannot enter the existing admin panel', function (string $role): void {
    $this->actingAs(User::factory()->create()->assignRole($role));

    $this->get(route('filament.admin.pages.dashboard'))->assertForbidden();
})->with(['admin', 'finance_officer', 'student', 'user']);

test('guests must authenticate to enter the finance panel', function (): void {
    $this->get(AssistanceRequestResource::getUrl('index', panel: 'finance'))
        ->assertRedirect(route('filament.finance.auth.login'));
});

test('staff can list and view assistance requests without mutation actions', function (string $role): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    $user = User::factory()->create()->assignRole($role);
    $request = AssistanceRequest::factory()->create(['requested_amount' => 9007199254740993]);
    $account = TuitionAccount::factory()->for($request->student)->create(['total_amount' => 123456789, 'paid_amount' => 1000000]);
    $this->actingAs($user);

    expect(Gate::getPolicyFor($request))->toBeInstanceOf(AssistanceRequestPolicy::class);

    $list = Livewire::test(ListAssistanceRequests::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$request])
        ->assertSee('9007199254.740993')
        ->assertActionDoesNotExist('create')
        ->assertTableActionExists('view', record: $request)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    expect($list->instance()->getTable()->getBulkActions())->toBeEmpty();

    Livewire::test(ViewAssistanceRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($request->reason)
        ->assertSee($request->student->student_number)
        ->assertSee($request->academicTerm->name)
        ->assertSee('9007199254.740993')
        ->assertSee($account->academicTerm->name)
        ->assertSee('123.456789')
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');

    expect(array_keys(AssistanceRequestResource::getPages()))->toBe(['index', 'view'])
        ->and(AssistanceRequestResource::canCreate())->toBeFalse();
})->with(['admin', 'finance_officer', 'super_admin']);

test('staff can search student numbers and names and filter term and status', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    $matching = AssistanceRequest::factory()->create();
    $other = AssistanceRequest::factory()->create();

    Livewire::test(ListAssistanceRequests::class)
        ->searchTable($matching->student->student_number)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable($matching->student->user->name)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('')
        ->filterTable('academic_term_id', $matching->academic_term_id)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('status', 'submitted')
        ->assertCanSeeTableRecords([$matching, $other])
        ->filterTable('status', 'nonexistent')
        ->assertCanNotSeeTableRecords([$matching, $other]);
});

test('nonstaff cannot browse requests even with general or resource permissions', function (string $role): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    $user = User::factory()->create()->assignRole($role);
    foreach (['ViewAny:User', 'ViewAny:AssistanceRequest', 'View:AssistanceRequest'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $request = AssistanceRequest::factory()->create();
    $this->actingAs($user);

    expect($user->can('viewAny', AssistanceRequest::class))->toBeFalse()
        ->and($user->can('view', $request))->toBeFalse();
    Livewire::test(ListAssistanceRequests::class)->assertForbidden();
    Livewire::test(ViewAssistanceRequest::class, ['record' => $request->getRouteKey()])->assertForbidden();
})->with(['student', 'user']);

test('only students with a linked profile can create and students can view only their own requests', function (): void {
    $studentUser = User::factory()->create()->assignRole('student');
    expect($studentUser->can('create', AssistanceRequest::class))->toBeFalse();

    $student = Student::factory()->for($studentUser)->create();
    $own = AssistanceRequest::factory()->for($student)->create();
    $other = AssistanceRequest::factory()->create();

    expect($studentUser->can('create', AssistanceRequest::class))->toBeTrue()
        ->and($studentUser->can('view', $own))->toBeTrue()
        ->and($studentUser->can('view', $other))->toBeFalse()
        ->and($studentUser->can('viewAny', AssistanceRequest::class))->toBeFalse();

    $studentUser->syncRoles('user');
    expect($studentUser->can('create', AssistanceRequest::class))->toBeFalse()
        ->and($studentUser->can('view', $own))->toBeFalse();
});

test('all roles are denied mutations including super admin', function (string $role): void {
    $user = User::factory()->create()->assignRole($role);
    $student = Student::factory()->for($user)->create();
    $request = AssistanceRequest::factory()->for($student)->create();

    foreach (['update', 'delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
        expect($user->can($ability, $request))->toBeFalse();
    }
    foreach (['deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'] as $ability) {
        expect($user->can($ability, AssistanceRequest::class))->toBeFalse();
    }
    expect($user->can('create', AssistanceRequest::class))->toBe($role === 'student');
})->with(['admin', 'finance_officer', 'super_admin', 'student', 'user']);

test('USDC formatting preserves all six decimal places without floating point rounding', function (int|string $amount, string $expected): void {
    expect(AssistanceRequestResource::formatUsdc($amount))->toBe($expected);
})->with([
    [0, '0.000000'],
    [1, '0.000001'],
    [1000000, '1.000000'],
    [123456789, '123.456789'],
    ['9223372036854775807', '9223372036854.775807'],
]);
