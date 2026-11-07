<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\TenantPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class GetAccessibleTenants
{
    /**
     * @return list<array{id: string, name: string|null}>
     */
    public function handle(User $user): array
    {
        $roleAssignments = config('permission.table_names.model_has_roles');
        $permissionAssignments = config('permission.table_names.model_has_permissions');
        $tenantKey = config('permission.column_names.team_foreign_key');
        $modelKey = config('permission.column_names.model_morph_key');
        $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $permissionKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $rolesTable = (new Role)->getTable();

        $viewRoles = Role::query()
            ->select((new Role)->getQualifiedKeyName())
            ->where('guard_name', 'web')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull($rolesTable.'.'.$tenantKey)
                ->orWhereColumn($rolesTable.'.'.$tenantKey, $roleAssignments.'.'.$tenantKey))
            ->whereHas('permissions', fn (Builder $query): Builder => $query
                ->where('name', TenantPermission::ViewTenant->value)
                ->where('guard_name', 'web'));

        $roleTenantIds = DB::table($roleAssignments)
            ->select($tenantKey)
            ->where($modelKey, $user->getKey())
            ->where('model_type', $user->getMorphClass())
            ->whereIn($roleKey, $viewRoles);

        $directTenantIds = DB::table($permissionAssignments)
            ->select($tenantKey)
            ->where($modelKey, $user->getKey())
            ->where('model_type', $user->getMorphClass())
            ->whereIn($permissionKey, Permission::query()
                ->select((new Permission)->getQualifiedKeyName())
                ->where('name', TenantPermission::ViewTenant->value)
                ->where('guard_name', 'web'));

        return array_values($user->tenants()
            ->wherePivot('is_active', true)
            ->whereIn('tenants.id', $roleTenantIds->union($directTenantIds))
            ->orderBy('tenants.name')
            ->orderBy('tenants.id')
            ->select(['tenants.id', 'tenants.name'])
            ->cursor()
            ->map(fn (Tenant $tenant): array => [
                'id' => (string) $tenant->getKey(),
                'name' => $tenant->name,
            ])->all());
    }
}
