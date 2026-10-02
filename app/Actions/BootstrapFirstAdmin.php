<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RoleEnums;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class BootstrapFirstAdmin
{
    public function handle(
        string $name,
        string $email,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $confirmation,
    ): User {
        $email = Str::lower(trim($email));
        $name = trim($name);

        Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->validate();

        return DB::transaction(function () use ($name, $email, $password): User {
            $role = Role::query()->firstOrCreate(['name' => RoleEnums::SUPER_ADMIN->value, 'guard_name' => 'web']);
            // Serialize first-admin attempts before checking whether one already exists.
            Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();

            if (User::query()->whereHas('roles', fn ($query) => $query->whereKey($role->id))->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'A super administrator already exists. Use the authenticated staff-management or recovery flow.',
                ]);
            }

            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'An account already uses this email. Bootstrap cannot elevate or reset an existing user.',
                ]);
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
            $user->assignRole($role);

            activity('security')->performedOn($user)->event('admin_bootstrapped')
                ->withProperties(['source' => 'operator_cli'])
                ->log('First administrator bootstrapped by an operator');

            return $user;
        }, 3);
    }
}
