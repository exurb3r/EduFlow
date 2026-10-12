<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleEnums;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo users may only be seeded in local or testing environments.');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        DB::transaction(function (): void {
            $adminRole = Role::query()->firstOrCreate(['name' => RoleEnums::SUPER_ADMIN->value, 'guard_name' => 'web']);
            $userRole = Role::query()->firstOrCreate(['name' => RoleEnums::USER->value, 'guard_name' => 'web']);

            $admin = User::query()->firstOrCreate(['email' => 'admin@admin.com'], [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
            $admin->assignRole($adminRole);

            $user = User::query()->firstOrCreate(['email' => 'user@user.com'], [
                'name' => 'Regular User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
            $user->assignRole($userRole);
        });
    }
}
