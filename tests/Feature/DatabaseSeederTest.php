<?php

declare(strict_types=1);

use App\Enums\RoleEnums;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Modules\Blog\Filament\Resources\PostResource;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('database seeder creates the default roles', function (): void {
    Role::query()->delete();

    $this->seed(DatabaseSeeder::class);

    $roles = Role::query()->pluck('name')->all();

    foreach (RoleEnums::values() as $role) {
        expect($roles)->toContain($role);
    }

    expect(Role::query()->where('name', RoleEnums::USER->value)->where('guard_name', 'web')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'juan@eduflow.test')->exists())->toBeTrue()
        ->and(Student::where('student_number', 'DEMO-JUAN')->exists())->toBeTrue()
        ->and(AcademicTerm::exists())->toBeTrue()
        ->and(TuitionAccount::where('total_amount', 300000000)->where('paid_amount', 0)->exists())->toBeTrue();
});

test('database seeding outside demo environments creates roles but no users or demo records', function (string $environment): void {
    app()->instance('env', $environment);

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    expect(Role::count())->toBe(count(RoleEnums::cases()))
        ->and(Permission::count())->toBeGreaterThan(0)
        ->and(User::count())->toBe(0)
        ->and(Organization::count())->toBe(0)
        ->and(Student::count())->toBe(0)
        ->and(AcademicTerm::count())->toBe(0)
        ->and(TuitionAccount::count())->toBe(0)
        ->and(AssistanceRequest::count())->toBe(0);
})->with(['production', 'staging']);

test('direct role seeding cannot elevate a default-email account outside demo environments', function (string $environment): void {
    $existing = User::factory()->create(['email' => 'admin@admin.com']);
    $password = $existing->getRawOriginal('password');
    app()->instance('env', $environment);

    $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true])->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and($existing->fresh()->getRawOriginal('password'))->toBe($password)
        ->and($existing->fresh()->hasRole(RoleEnums::SUPER_ADMIN))->toBeFalse();
})->with(['production', 'staging']);

test('explicit demo user seeding preserves local accounts and is idempotent', function (string $environment): void {
    app()->instance('env', $environment);

    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoUsersSeeder::class);

    $admin = User::query()->where('email', 'admin@admin.com')->sole();

    expect(User::count())->toBe(2)
        ->and($admin->hasRole(RoleEnums::SUPER_ADMIN))->toBeTrue()
        ->and($admin->can('ViewAny:Post'))->toBeTrue()
        ->and($admin->can('AuthorizePayment:PaymentIntent'))->toBeTrue()
        ->and($admin->hasDirectPermission('AuthorizePayment:PaymentIntent'))->toBeFalse()
        ->and(Hash::check('password', $admin->password))->toBeTrue();
})->with(['local', 'testing']);

test('direct role seeding never creates users including in local and testing environments', function (string $environment): void {
    app()->instance('env', $environment);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(User::count())->toBe(0)->and(Role::count())->toBe(count(RoleEnums::cases()));
})->with(['local', 'testing']);

test('explicit demo user seeding refuses non demo environments before writing', function (string $environment): void {
    app()->instance('env', $environment);

    expect(fn () => (new DemoUsersSeeder)->run())->toThrow(RuntimeException::class)
        ->and(User::count())->toBe(0)
        ->and(Role::count())->toBe(0);
})->with(['production', 'staging']);

test('seeded admin receives the complete permission catalogue and can access module resources', function (): void {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@admin.com')->sole();
    Filament::setCurrentPanel('admin');
    $permissions = FilamentShield::getEntitiesPermissions();

    expect($permissions)->not->toBeEmpty();

    foreach ($permissions as $permission) {
        expect($admin->hasPermissionTo($permission, 'web'))->toBeTrue();
    }

    expect($admin->can('DeleteAny:AssistanceRequest'))->toBeTrue()
        ->and($admin->can('ManageSocialLoginSettings'))->toBeTrue()
        ->and($admin->can('AuthorizePayment:PaymentIntent'))->toBeTrue()
        ->and($admin->hasDirectPermission('AuthorizePayment:PaymentIntent'))->toBeFalse()
        ->and($admin->getAllPermissions()->count())->toBe(Permission::query()->where('guard_name', 'web')->count());

    $this->actingAs($admin)->get(PostResource::getUrl('index', panel: 'admin'))->assertSuccessful();
});

test('role seeding scopes permissions to the web guard and preserves the current panel', function (): void {
    Permission::findOrCreate('View:ApiOnly', 'api');
    Permission::findOrCreate('View:CustomReport', 'web');
    Filament::setCurrentPanel('finance');

    $this->seed(RolesAndPermissionsSeeder::class);

    $superAdmin = Role::findByName(RoleEnums::SUPER_ADMIN->value, 'web');

    expect(Filament::getCurrentPanel()->getId())->toBe('finance')
        ->and($superAdmin->hasPermissionTo('View:CustomReport', 'web'))->toBeTrue()
        ->and($superAdmin->permissions()->get()->contains('guard_name', 'api'))->toBeFalse()
        ->and($superAdmin->permissions()->count())->toBe(Permission::query()->where('guard_name', 'web')->count());
});

test('rerunning demo user seeding repairs admin permissions without changing existing credentials', function (): void {
    $admin = User::factory()->create(['email' => 'admin@admin.com']);
    $password = $admin->getRawOriginal('password');
    $name = $admin->name;

    $this->seed(DemoUsersSeeder::class);
    Role::findByName(RoleEnums::SUPER_ADMIN->value, 'web')->revokePermissionTo('ViewAny:Post');

    expect($admin->fresh()->can('ViewAny:Post'))->toBeFalse();

    $this->seed(DemoUsersSeeder::class);
    $admin = $admin->fresh();

    expect($admin->can('ViewAny:Post'))->toBeTrue()
        ->and($admin->getRawOriginal('password'))->toBe($password)
        ->and($admin->name)->toBe($name)
        ->and(User::query()->where('email', 'admin@admin.com')->count())->toBe(1);
});
