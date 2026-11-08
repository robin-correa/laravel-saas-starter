<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LeaveTenant
{
    public function handle(User $user, Tenant $tenant): void
    {
        DB::transaction(function () use ($user, $tenant): void {
            $tenant->refreshForUpdate();
            $user->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($user)->authorize('leave', $tenant);

            $user->syncRoles([]);
            $user->syncPermissions([]);
            $tenant->users()->detach($user->getKey());
        });
    }
}
