<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\InitializeInstitution;
use App\Enums\RoleEnums;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('eduflow:install {--institution= : Institution name} {--country= : Two-letter country code} {--timezone=UTC : School timezone} {--locale=en : Supported school locale} {--currency=USDC : Supported accounting currency} {--adopt-institution= : Explicit existing organization ID to adopt}')]
#[Description('Initialize one school without demo data, wallets, payments or credential changes. Requires applied migrations.')]
class InstallEduFlow extends Command
{
    public function handle(InitializeInstitution $initialize): int
    {
        if (! config('app.key')) {
            $this->error('APP_KEY must already be configured. Setup never generates or replaces encryption keys.');

            return self::FAILURE;
        }

        if (! app()->environment(['local', 'testing']) && config('app.debug')) {
            $this->error('Disable APP_DEBUG before school setup.');

            return self::FAILURE;
        }

        $name = $this->option('institution');
        $country = $this->option('country');

        if ($this->input->isInteractive()) {
            $name ??= $this->ask('Institution name');
            $country ??= $this->ask('Two-letter country code');
        }

        $adoptId = $this->option('adopt-institution');

        if ($adoptId !== null && filter_var($adoptId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('--adopt-institution must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $institution = $initialize->handle([
                'name' => (string) $name,
                'country' => (string) $country,
                'timezone' => (string) $this->option('timezone'),
                'locale' => (string) $this->option('locale'),
                'currency' => (string) $this->option('currency'),
            ], $adoptId === null ? null : (int) $adoptId);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Institution initialized: {$institution->name} (ID {$institution->id}).");
        $this->line('Institution identity is persisted; an optional EDUFLOW_INSTITUTION_ID must match it.');
        $this->warn('School setup is not production financial certification. Configure mail, MFA, policies, backups and data imports before access.');

        if (! User::query()->whereHas('roles', fn ($query) => $query
            ->where('name', RoleEnums::SUPER_ADMIN->value)->where('guard_name', 'web'))->exists()) {
            $this->line('Next: php artisan eduflow:bootstrap-admin');
        }

        return self::SUCCESS;
    }
}
