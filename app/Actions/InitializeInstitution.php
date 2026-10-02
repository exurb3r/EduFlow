<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CurrencyCode;
use App\Models\Organization;
use App\Settings\AiSettings;
use App\Settings\ApplicationFeaturesSettings;
use App\Settings\InstallationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelSettings\Models\SettingsProperty;
use Spatie\LaravelSettings\Support\SettingsCacheFactory;

class InitializeInstitution
{
    public function __construct(private readonly RolesAndPermissionsSeeder $roles) {}

    /** @param array{name: string, country: string, locale: string, timezone: string, currency: string} $data */
    public function handle(array $data, ?int $adoptInstitutionId = null): Organization
    {
        $data['name'] = trim($data['name']);
        $data['country'] = strtoupper(trim($data['country']));
        $data['currency'] = strtoupper(trim($data['currency']));

        $key = (string) config('app.key');
        $decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if (! is_string($decodedKey) || ! Encrypter::supported($decodedKey, (string) config('app.cipher'))) {
            throw ValidationException::withMessages(['name' => 'APP_KEY must contain valid existing encryption key material.']);
        }

        Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'locale' => ['required', Rule::in(['en'])],
            'timezone' => ['required', 'timezone'],
            'currency' => ['required', Rule::in(CurrencyCode::values())],
        ])->validate();

        if (config('settings.default_repository') !== 'database' || config('settings.repositories.database.connection') !== null) {
            throw ValidationException::withMessages(['name' => 'Institution installation requires settings in the application database.']);
        }

        try {
            return DB::transaction(function () use ($data, $adoptInstitutionId): Organization {
                // Existing settings row is the shared lock even when no organization exists yet.
                /** @var SettingsProperty|null $state */
                $state = SettingsProperty::query()->where('group', InstallationSettings::group())
                    ->where('name', 'institution_id')->lockForUpdate()->first();

                if ($state === null) {
                    throw ValidationException::withMessages(['name' => 'Apply the installation settings migration before running setup.']);
                }

                if ($state->getAttribute('locked')) {
                    throw ValidationException::withMessages(['name' => 'Installation identity is locked. No records were changed.']);
                }

                try {
                    $persistedId = json_decode((string) $state->getAttribute('payload'), true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw ValidationException::withMessages(['name' => 'Installation identity is corrupt. No records were changed.']);
                }
                /** @var Collection<int, Organization> $institutions */
                $institutions = Organization::query()->lockForUpdate()->limit(2)->get();

                if ($institutions->count() > 1) {
                    throw ValidationException::withMessages(['name' => 'Setup requires a database containing at most one institution.']);
                }

                $institution = $institutions->first();

                if ($persistedId !== null) {
                    if (! $institution instanceof Organization || ! is_int($persistedId) || $institution->id !== $persistedId) {
                        throw ValidationException::withMessages(['name' => 'Stored installation identity does not match the institution. No records were changed.']);
                    }

                    $this->validateIdentity($institution, $data, $adoptInstitutionId);
                    $this->validateConfiguredId($institution->id);

                    return $institution;
                }

                if ($institution instanceof Organization) {
                    if ($adoptInstitutionId !== $institution->id) {
                        throw ValidationException::withMessages(['name' => 'An institution already exists. Pass its ID with --adopt-institution after reviewing existing data.']);
                    }

                    $this->validateIdentity($institution, $data, $adoptInstitutionId);
                } else {
                    if ($adoptInstitutionId !== null) {
                        throw ValidationException::withMessages(['name' => 'The institution selected for adoption does not exist.']);
                    }

                    $institution = Organization::query()->create([
                        'name' => $data['name'],
                        'type' => 'school',
                        'currency' => $data['currency'],
                        'minimum_reserve' => 0,
                        'max_auto_payment' => 0,
                        'max_daily_disbursement' => 0,
                        'human_approval_threshold' => 0,
                    ]);
                }

                $this->validateConfiguredId($institution->id);
                $this->roles->run();
                $this->applySafeDefaults();

                $settings = new InstallationSettings;
                $settings->refresh()->fill([
                    'institution_id' => $institution->id,
                    'initialized_at' => now()->toIso8601String(),
                    'country' => $data['country'],
                    'locale' => $data['locale'],
                    'timezone' => $data['timezone'],
                    'currency' => $data['currency'],
                ])->save();

                if ($settings->institution_id !== $institution->id || $settings->initialized_at === null
                    || $settings->country !== $data['country'] || $settings->locale !== $data['locale']
                    || $settings->timezone !== $data['timezone'] || $settings->currency !== $data['currency']) {
                    throw ValidationException::withMessages(['name' => 'Installation settings could not be saved. No records were changed.']);
                }

                activity('installation')->performedOn($institution)->event('institution_initialized')
                    ->withProperties(['source' => 'operator_cli', 'adopted' => $adoptInstitutionId !== null])
                    ->log('Institution identity initialized by an operator');

                return $institution;
            }, 3);
        } finally {
            foreach (app(SettingsCacheFactory::class)->all() as $cache) {
                $cache->clear();
            }

            foreach ([InstallationSettings::class, ApplicationFeaturesSettings::class, AiSettings::class] as $settingsClass) {
                app()->forgetInstance($settingsClass);
            }
        }
    }

    /** @param array{name: string, country: string, locale: string, timezone: string, currency: string} $data */
    private function validateIdentity(Organization $institution, array $data, ?int $adoptInstitutionId): void
    {
        if ($institution->name !== $data['name'] || $institution->currency !== $data['currency']
            || ($adoptInstitutionId !== null && $adoptInstitutionId !== $institution->id)) {
            throw ValidationException::withMessages(['name' => 'Setup cannot replace or rename an existing institution or change its accounting currency.']);
        }

        $settings = new InstallationSettings;
        $settings->refresh();

        if ($settings->initialized_at !== null && ($settings->country !== $data['country']
            || $settings->locale !== $data['locale'] || $settings->timezone !== $data['timezone'])) {
            throw ValidationException::withMessages(['name' => 'Installation metadata differs. Setup cannot overwrite existing school configuration.']);
        }
    }

    private function validateConfiguredId(int $institutionId): void
    {
        $configuredId = config('eduflow.institution_id');

        if ($configuredId !== null && $configuredId !== ''
            && filter_var($configuredId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== $institutionId) {
            throw ValidationException::withMessages(['name' => 'EDUFLOW_INSTITUTION_ID conflicts with this institution. No records were changed.']);
        }
    }

    private function applySafeDefaults(): void
    {
        $features = new ApplicationFeaturesSettings;
        $features->refresh()->fill([
            'registration_enabled' => false,
            'user_impersonation_enabled' => false,
            'email_verification_required' => true,
            'two_factor_authentication_enabled' => true,
        ])->save();

        if ($features->registration_enabled || $features->user_impersonation_enabled
            || ! $features->email_verification_required || ! $features->two_factor_authentication_enabled) {
            throw ValidationException::withMessages(['name' => 'Locked feature settings prevent safe school defaults.']);
        }

        $ai = new AiSettings;
        $ai->refresh()->fill([
            'advisory_enabled' => false,
            'disclosure_accepted' => false,
            'allow_settlement_proposals' => false,
        ])->save();

        if ($ai->advisory_enabled || $ai->disclosure_accepted || $ai->allow_settlement_proposals) {
            throw ValidationException::withMessages(['name' => 'Locked AI settings prevent safe school defaults.']);
        }

    }
}
