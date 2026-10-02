<?php

declare(strict_types=1);

use App\Actions\BootstrapFirstAdmin;
use App\Console\Commands\BootstrapAdmin;
use App\Enums\RoleEnums;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Notification::fake();
});

test('first administrator bootstrap creates a unique admin without demo data or network calls', function (): void {
    app()->instance('env', 'production');

    $admin = app(BootstrapFirstAdmin::class)->handle(' School Operator ', ' OPERATOR@SCHOOL.TEST ', 'School-Admin-42!', 'School-Admin-42!');

    expect($admin->name)->toBe('School Operator')
        ->and($admin->email)->toBe('operator@school.test')
        ->and(Hash::check('School-Admin-42!', $admin->password))->toBeTrue()
        ->and($admin->email_verified_at)->toBeNull()
        ->and($admin->hasRole(RoleEnums::SUPER_ADMIN))->toBeTrue()
        ->and(User::count())->toBe(1)
        ->and(Organization::count())->toBe(0);

    $audit = Activity::where('event', 'admin_bootstrapped')->sole();
    expect($audit->subject_id)->toBe($admin->id)
        ->and($audit->properties->all())->toBe(['source' => 'operator_cli'])
        ->and(Activity::all()->toJson())->not->toContain('School-Admin-42!', $admin->password);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('bootstrap cannot reset or elevate an existing account even with different email casing', function (): void {
    $user = User::factory()->create(['email' => 'Operator@School.Test']);
    $originalPassword = $user->getRawOriginal('password');

    expect(fn () => app(BootstrapFirstAdmin::class)->handle('Operator', 'operator@school.test', 'School-Admin-42!', 'School-Admin-42!'))
        ->toThrow(ValidationException::class);

    expect(User::count())->toBe(1)
        ->and($user->fresh()->getRawOriginal('password'))->toBe($originalPassword)
        ->and($user->fresh()->roles)->toHaveCount(0)
        ->and(Role::count())->toBe(0)
        ->and(Activity::where('event', 'admin_bootstrapped')->count())->toBe(0);
});

test('bootstrap refuses a second super administrator without changing credentials', function (): void {
    $admin = app(BootstrapFirstAdmin::class)->handle('Operator', 'operator@school.test', 'School-Admin-42!', 'School-Admin-42!');
    $originalPassword = $admin->password;

    expect(fn () => app(BootstrapFirstAdmin::class)->handle('Other Operator', 'other@school.test', 'Another-Admin-42!', 'Another-Admin-42!'))
        ->toThrow(ValidationException::class);

    expect(User::count())->toBe(1)
        ->and($admin->fresh()->password)->toBe($originalPassword)
        ->and(Activity::where('event', 'admin_bootstrapped')->count())->toBe(1);
});

test('bootstrap rejects invalid input before persisting a role or user', function (string $name, string $email, string $password, string $confirmation): void {
    expect(fn () => app(BootstrapFirstAdmin::class)->handle($name, $email, $password, $confirmation))
        ->toThrow(ValidationException::class);

    expect(User::count())->toBe(0)->and(Role::count())->toBe(0);
})->with([
    'blank name' => ['', 'operator@school.test', 'School-Admin-42!', 'School-Admin-42!'],
    'invalid email' => ['Operator', 'not-an-email', 'School-Admin-42!', 'School-Admin-42!'],
    'known password' => ['Operator', 'operator@school.test', 'password', 'password'],
    'confirmation mismatch' => ['Operator', 'operator@school.test', 'School-Admin-42!', 'Different-Password-42!'],
    'missing mixed case' => ['Operator', 'operator@school.test', 'school-admin-42!', 'school-admin-42!'],
]);

test('bootstrap command requires interactive hidden password entry and exposes no password option', function (): void {
    $this->artisan('eduflow:bootstrap-admin', ['--name' => 'Operator', '--email' => 'operator@school.test', '--no-interaction' => true])
        ->expectsOutputToContain('requires an interactive terminal')
        ->assertFailed();

    expect(User::count())->toBe(0)
        ->and((new BootstrapAdmin)->getDefinition()->hasOption('password'))->toBeFalse();
});

test('bootstrap command reports invalid passwords without creating an administrator', function (): void {
    $this->artisan('eduflow:bootstrap-admin', ['--name' => 'Operator', '--email' => 'operator@school.test'])
        ->expectsQuestion('Administrator password (at least 12 characters, mixed case, number and symbol)', 'password')
        ->expectsQuestion('Confirm administrator password', 'password')
        ->assertFailed();

    expect(User::count())->toBe(0)->and(Role::count())->toBe(0);
});

test('bootstrap command refuses existing administrators before asking for secrets', function (): void {
    app(BootstrapFirstAdmin::class)->handle('Operator', 'operator@school.test', 'School-Admin-42!', 'School-Admin-42!');

    $this->artisan('eduflow:bootstrap-admin', ['--name' => 'Other Operator', '--email' => 'other@school.test'])
        ->expectsOutputToContain('A super administrator already exists.')
        ->assertFailed();

    expect(User::count())->toBe(1);
});

test('bootstrap action parameters redact passwords from exception backtraces', function (): void {
    $method = new ReflectionMethod(BootstrapFirstAdmin::class, 'handle');
    $parameters = $method->getParameters();

    expect($parameters[2]->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and($parameters[3]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});

test('bootstrap command creates first administrator through hidden prompts', function (): void {
    $this->artisan('eduflow:bootstrap-admin', ['--name' => 'Operator', '--email' => 'operator@school.test'])
        ->expectsQuestion('Administrator password (at least 12 characters, mixed case, number and symbol)', 'School-Admin-42!')
        ->expectsQuestion('Confirm administrator password', 'School-Admin-42!')
        ->expectsOutputToContain('First administrator created: operator@school.test')
        ->assertSuccessful();

    expect(User::where('email', 'operator@school.test')->sole()->hasRole(RoleEnums::SUPER_ADMIN))->toBeTrue();
});
