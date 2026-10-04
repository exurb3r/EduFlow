<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BootstrapFirstAdmin;
use App\Enums\RoleEnums;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('eduflow:bootstrap-admin {--name= : First administrator display name} {--email= : First administrator email address}')]
#[Description('Create the first administrator using hidden password prompts without changing existing accounts.')]
class BootstrapAdmin extends Command
{
    public function handle(BootstrapFirstAdmin $bootstrap): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Administrator bootstrap requires an interactive terminal for hidden password prompts.');

            return self::FAILURE;
        }

        if (User::query()->whereHas('roles', fn ($query) => $query
            ->where('name', RoleEnums::SUPER_ADMIN->value)->where('guard_name', 'web'))->exists()) {
            $this->error('A super administrator already exists. Use the authenticated staff-management or recovery flow.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?? $this->ask('Administrator name');
        $email = $this->option('email') ?? $this->ask('Administrator email');
        $password = $this->secret('Administrator password (at least 12 characters, mixed case, number and symbol)', fallback: false);
        $confirmation = $this->secret('Confirm administrator password', fallback: false);

        try {
            $admin = $bootstrap->handle((string) $name, (string) $email, (string) $password, (string) $confirmation);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("First administrator created: {$admin->email}");
        $this->warn('Verify the email address and configure staff MFA before opening access to the school.');

        return self::SUCCESS;
    }
}
