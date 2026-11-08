<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

class DeleteTenant
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function handle(User $user, Tenant $tenant): void
    {
        DB::transaction(function () use ($user, $tenant): void {
            $tenant->refreshForUpdate();
            $user->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($user)->authorize('delete', $tenant);

            $tenant->delete();
        });

        $this->permissions->forgetCachedPermissions();
    }
}
