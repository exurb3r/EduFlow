<?php

declare(strict_types=1);

use App\Actions\InitializeInstitution;
use App\Models\User;
use App\Settings\ApplicationFeaturesSettings;

test('installed school refuses public registration GET and POST by default', function (): void {
    app(InitializeInstitution::class)->handle([
        'name' => 'Closed School', 'country' => 'PH', 'locale' => 'en', 'timezone' => 'UTC', 'currency' => 'USDC',
    ]);

    $this->get('/register')->assertForbidden();
    $this->post('/register', [
        'name' => 'Applicant', 'email' => 'applicant@school.test',
        'password' => 'School-Applicant-42!', 'password_confirmation' => 'School-Applicant-42!',
    ])->assertForbidden();
    expect(User::count())->toBe(0);
});

test('school registration opt in does not grant staff privileges', function (): void {
    app(InitializeInstitution::class)->handle([
        'name' => 'Open School', 'country' => 'PH', 'locale' => 'en', 'timezone' => 'UTC', 'currency' => 'USDC',
    ]);
    $features = app(ApplicationFeaturesSettings::class);
    $features->refresh();
    $features->registration_enabled = true;
    $features->save();

    $this->post('/register', [
        'name' => 'Applicant', 'email' => 'applicant@school.test',
        'password' => 'School-Applicant-42!', 'password_confirmation' => 'School-Applicant-42!',
    ])->assertRedirect();

    $user = User::where('email', 'applicant@school.test')->sole();
    expect($user->hasAnyRole(['super_admin', 'admin', 'finance_officer']))->toBeFalse()
        ->and($user->student)->toBeNull();
});

test('development registration behavior stays available without school setup', function (): void {
    $this->get('/register')->assertSuccessful();
});
