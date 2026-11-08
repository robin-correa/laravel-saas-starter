<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\TenantRole;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class CreateTenant
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function handle(User $user, string $name): Tenant
    {
        $previousTenantId = $this->permissions->getPermissionsTeamId();

        try {
            return DB::transaction(function () use ($user, $name): Tenant {
                $tenant = Tenant::create(['name' => $name]);
                $tenant->users()->attach($user->getKey(), ['is_active' => true]);

                $this->permissions->setPermissionsTeamId($tenant->getKey());
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->assignRole(TenantRole::Owner);

                return $tenant;
            });
        } finally {
            $this->permissions->setPermissionsTeamId($previousTenantId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
