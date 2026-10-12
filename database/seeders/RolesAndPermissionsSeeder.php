<?php

namespace Database\Seeders;

use App\Enums\RoleEnums;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    protected array $permissionMap = [
        'viewAny' => 'ViewAny',
        'view' => 'View',
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
        'deleteAny' => 'DeleteAny',
        'restore' => 'Restore',
        'forceDelete' => 'ForceDelete',
        'forceDeleteAny' => 'ForceDeleteAny',
        'restoreAny' => 'RestoreAny',
        'replicate' => 'Replicate',
        'reorder' => 'Reorder',
        'authorizePayment' => 'AuthorizePayment',
    ];

    public function run(): void
    {
        $permissionRegistrar = app(PermissionRegistrar::class);
        $permissionRegistrar->forgetCachedPermissions();

        $permissions = array_values(array_unique(array_merge(
            $this->discoverPermissionsFromPolicies(),
            $this->discoverPermissionsFromFilament(),
        )));
        $this->createPermissions($permissions);

        $roles = $this->createRoles();
        $this->assignPermissionsToRoles($roles, $permissions);

        $permissionRegistrar->forgetCachedPermissions();
    }

    protected function discoverPermissionsFromPolicies(): array
    {
        $policiesPath = app_path('Policies');
        $permissions = [];

        if (! File::exists($policiesPath)) {
            return $permissions;
        }

        $policyFiles = File::files($policiesPath);

        foreach ($policyFiles as $file) {
            $policyName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $policyName = preg_replace('/Policy$/', '', $policyName);

            if (empty($policyName)) {
                continue;
            }

            $content = $file->getContents();

            foreach ($this->permissionMap as $method => $permissionSuffix) {
                if (preg_match('/function\s+'.$method.'\s*\(/', $content)) {
                    $permissions[] = "{$permissionSuffix}:{$policyName}";
                }
            }
        }

        return $permissions;
    }

    /** @return list<string> */
    protected function discoverPermissionsFromFilament(): array
    {
        $currentPanel = Filament::getCurrentPanel();

        try {
            Filament::setCurrentPanel(Filament::getDefaultPanel());

            return FilamentShield::getEntitiesPermissions() ?? [];
        } finally {
            Filament::setCurrentPanel($currentPanel);
        }
    }

    protected function createPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    protected function createRoles(): array
    {
        $roles = [];

        foreach (RoleEnums::cases() as $enumCase) {
            $role = Role::firstOrCreate([
                'name' => $enumCase->value,
                'guard_name' => 'web',
            ]);
            $roles[$enumCase->value] = $role;
        }

        return $roles;
    }

    protected function assignPermissionsToRoles(array $roles, array $permissions): void
    {
        $superAdminPermissions = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $adminPermissions = array_filter($permissions, fn ($p) => ! str_contains($p, 'Role:'));
        $userPermissions = array_filter($permissions, fn ($p) => str_starts_with($p, 'ViewAny:'));

        $roles[RoleEnums::SUPER_ADMIN->value]->syncPermissions($superAdminPermissions);
        $roles[RoleEnums::ADMIN->value]->syncPermissions($adminPermissions);
        $roles[RoleEnums::USER->value]->syncPermissions($userPermissions);
    }
}
