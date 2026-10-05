<?php

declare(strict_types=1);

use App\Actions\InitializeInstitution;
use App\Enums\RoleEnums;
use App\Models\AiProvider;
use App\Models\AssistancePolicyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Settings\AiSettings;
use App\Settings\ApplicationFeaturesSettings;
use App\Settings\InstallationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelSettings\Models\SettingsProperty;
use Spatie\Permission\Models\Role;

function schoolInstallationData(array $changes = []): array
{
    return array_replace([
        'name' => 'Pilot School',
        'country' => 'PH',
        'locale' => 'en',
        'timezone' => 'Asia/Manila',
        'currency' => 'PHP',
    ], $changes);
}

beforeEach(function (): void {
    config(['eduflow.institution_id' => null, 'app.debug' => false]);
    Http::preventStrayRequests();
});

test('school installer creates one institution and safe defaults without users wallets or providers', function (): void {
    app()->instance('env', 'production');
    $key = config('app.key');

    $this->artisan('eduflow:install', [
        '--institution' => 'Pilot School', '--country' => 'PH', '--timezone' => 'Asia/Manila',
        '--currency' => 'PHP', '--no-interaction' => true,
    ])->expectsOutputToContain('Institution initialized: Pilot School')->assertSuccessful();

    $school = Organization::query()->sole();
    $settings = new InstallationSettings;
    $features = new ApplicationFeaturesSettings;
    $ai = new AiSettings;

    expect($settings->institution_id)->toBe($school->id)
        ->and($settings->country)->toBe('PH')
        ->and($settings->timezone)->toBe('Asia/Manila')
        ->and($settings->currency)->toBe('PHP')
        ->and($settings->initialized_at)->not->toBeNull()
        ->and(app(InstallationInstitution::class)->require()->id)->toBe($school->id)
        ->and($school->max_auto_payment)->toBe(0.0)
        ->and($features->registration_enabled)->toBeFalse()
        ->and($features->user_impersonation_enabled)->toBeFalse()
        ->and($ai->mayCallProvider())->toBeFalse()
        ->and(Role::count())->toBe(count(RoleEnums::cases()))
        ->and(User::count())->toBe(0)
        ->and(Wallet::count())->toBe(0)
        ->and(AiProvider::count())->toBe(0)
        ->and(AssistancePolicyVersion::count())->toBe(0)
        ->and(config('app.key'))->toBe($key);
    Http::assertNothingSent();
});

test('same setup resumes without overwriting policy budgets admin credentials or enabled settings', function (): void {
    $school = app(InitializeInstitution::class)->handle(schoolInstallationData());
    $school->update(['minimum_reserve' => 45, 'max_auto_payment' => 10]);
    $admin = User::factory()->create();
    $password = $admin->password;
    $features = new ApplicationFeaturesSettings;
    $features->registration_enabled = true;
    $features->save();
    $initializedAt = (new InstallationSettings)->initialized_at;

    $again = app(InitializeInstitution::class)->handle(schoolInstallationData());

    expect($again->id)->toBe($school->id)
        ->and(Organization::count())->toBe(1)
        ->and($again->minimum_reserve)->toBe(45.0)
        ->and($again->max_auto_payment)->toBe(10.0)
        ->and($admin->fresh()->password)->toBe($password)
        ->and((new ApplicationFeaturesSettings)->registration_enabled)->toBeTrue()
        ->and((new InstallationSettings)->initialized_at)->toBe($initializedAt)
        ->and(Activity::where('event', 'institution_initialized')->count())->toBe(1);
});

test('setup cannot silently adopt existing data and explicit adoption preserves balances', function (): void {
    $school = Organization::factory()->create(['name' => 'Pilot School', 'currency' => 'PHP', 'minimum_reserve' => 80]);

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class);

    $adopted = app(InitializeInstitution::class)->handle(schoolInstallationData(), $school->id);

    expect($adopted->id)->toBe($school->id)
        ->and($adopted->minimum_reserve)->toBe(80.0)
        ->and(Organization::count())->toBe(1)
        ->and((new InstallationSettings)->institution_id)->toBe($school->id);
});

test('setup refuses changed identity or metadata without changing existing school', function (array $change): void {
    $school = app(InitializeInstitution::class)->handle(schoolInstallationData());

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData($change)))->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(1)
        ->and($school->fresh()->name)->toBe('Pilot School')
        ->and($school->fresh()->currency)->toBe('PHP');
})->with([
    'name' => [['name' => 'Another School']],
    'currency' => [['currency' => 'USD']],
    'timezone' => [['timezone' => 'UTC']],
    'country' => [['country' => 'US']],
]);

test('setup rejects invalid inputs before any persistence', function (array $change): void {
    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData($change)))
        ->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(0)
        ->and(Role::count())->toBe(0)
        ->and((new InstallationSettings)->institution_id)->toBeNull();
})->with([
    'empty name' => [['name' => '']],
    'unknown currency' => [['currency' => 'FAKE']],
    'invalid timezone' => [['timezone' => 'Wrong/Zone']],
    'unsupported language' => [['locale' => 'xx']],
    'invalid country' => [['country' => 'PHL']],
]);

test('setup refuses ambiguous multiple schools and config conflicts without writing identity', function (): void {
    Organization::factory()->count(2)->create();

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class)
        ->and((new InstallationSettings)->institution_id)->toBeNull();
});

test('conflicting configured institution rolls back new school and safe default changes', function (): void {
    config(['eduflow.institution_id' => 9999]);

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(0)
        ->and((new InstallationSettings)->institution_id)->toBeNull();
});

test('installer refuses missing key unsafe debug or missing input without changing database', function (string $condition): void {
    app()->instance('env', 'production');
    if ($condition === 'key') {
        config(['app.key' => null]);
    } elseif ($condition === 'debug') {
        config(['app.debug' => true]);
    }

    $options = ['--no-interaction' => true];
    if ($condition !== 'input') {
        $options += ['--institution' => 'Pilot School', '--country' => 'PH'];
    }

    $this->artisan('eduflow:install', $options)->assertFailed();
    expect(Organization::count())->toBe(0);
})->with(['key', 'debug', 'input']);

test('stored setup identity works across resolver instances and config override cannot switch it', function (): void {
    $school = app(InitializeInstitution::class)->handle(schoolInstallationData());
    app()->instance('env', 'production');

    expect((new InstallationInstitution)->require()->id)->toBe($school->id);

    config(['eduflow.institution_id' => $school->id + 1]);
    expect((new InstallationInstitution)->current())->toBeNull();
});

test('setup refuses a missing or locked settings row instead of pretending persistence succeeded', function (bool $locked): void {
    $query = SettingsProperty::query()->where('group', 'installation')->where('name', 'institution_id');
    if ($locked) {
        $query->update(['locked' => true]);
    } else {
        $query->delete();
    }

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(0);
})->with([true, false]);

test('invalid key material cannot initialize school identity', function (): void {
    config(['app.key' => 'invalid-key']);

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(0);
});

test('locked unsafe defaults roll back institution roles and settings', function (): void {
    SettingsProperty::query()->where('group', 'application_features')->where('name', 'registration_enabled')
        ->update(['locked' => true, 'payload' => 'true']);

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(0)
        ->and(Role::count())->toBe(0)
        ->and((new InstallationSettings)->institution_id)->toBeNull();
});

test('installed school prevents creating another organization through Eloquent', function (): void {
    app(InitializeInstitution::class)->handle(schoolInstallationData());

    expect(fn () => Organization::factory()->create())->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(1);
});

test('rollback removes stale loaded feature settings before a setup retry', function (): void {
    SettingsProperty::query()->where('group', 'ai')->where('name', 'advisory_enabled')
        ->update(['locked' => true, 'payload' => 'true']);

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class)
        ->and(app(ApplicationFeaturesSettings::class)->registration_enabled)->toBeTrue()
        ->and(Organization::count())->toBe(0);

    SettingsProperty::query()->where('group', 'ai')->where('name', 'advisory_enabled')->update(['locked' => false]);
    $school = app(InitializeInstitution::class)->handle(schoolInstallationData());

    expect(app(InstallationInstitution::class)->require()->id)->toBe($school->id);
});

test('corrupt setup identity cannot create or adopt an institution', function (): void {
    SettingsProperty::query()->where('group', 'installation')->where('name', 'institution_id')->update(['payload' => 'broken-json']);

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(0);
});

test('locked metadata cannot leave a partially initialized school', function (): void {
    SettingsProperty::query()->where('group', 'installation')->where('name', 'country')->update(['locked' => true]);

    expect(fn () => app(InitializeInstitution::class)->handle(schoolInstallationData()))->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(0)
        ->and((new InstallationSettings)->institution_id)->toBeNull();
});

test('corrupt selected identity also blocks direct organization creation', function (): void {
    SettingsProperty::query()->where('group', 'installation')->where('name', 'institution_id')->update(['payload' => 'broken-json']);

    expect(fn () => Organization::factory()->create())->toThrow(ValidationException::class)
        ->and(Organization::count())->toBe(0);
});

test('installation identity persists only after settings writes and audit complete', function (): void {
    DB::beginTransaction();
    try {
        app(InitializeInstitution::class)->handle(schoolInstallationData());
    } finally {
        DB::rollBack();
    }

    expect(Organization::count())->toBe(0)
        ->and((new InstallationInstitution)->current())->toBeNull()
        ->and((new InstallationSettings)->institution_id)->toBeNull();
});
