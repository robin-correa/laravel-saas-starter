<?php

namespace Database\Seeders;

use App\TenantPermission;
use App\TenantRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TenantRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(PermissionRegistrar $permissions): void
    {
        $previousTenantId = $permissions->getPermissionsTeamId();
        $permissions->setPermissionsTeamId(null);
        $permissions->forgetCachedPermissions();

        try {
            foreach (TenantPermission::cases() as $permission) {
                Permission::findOrCreate($permission->value, 'web');
            }

            foreach (TenantRole::cases() as $tenantRole) {
                $role = Role::query()->firstOrCreate([
                    'tenant_id' => null,
                    'name' => $tenantRole->value,
                    'guard_name' => 'web',
                ]);

                $role->syncPermissions(array_map(
                    fn (TenantPermission $permission): string => $permission->value,
                    $tenantRole->permissions(),
                ));
            }
        } finally {
            $permissions->setPermissionsTeamId($previousTenantId);
            $permissions->forgetCachedPermissions();
        }
    }
}
