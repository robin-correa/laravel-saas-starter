<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateTenantMemberRole
{
    public function handle(User $user, Tenant $tenant, string $memberId, TenantRole $role): void
    {
        DB::transaction(function () use ($user, $tenant, $memberId, $role): void {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $member = $tenant->users()->whereKey($memberId)->firstOrFail();

            $user->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($user)->authorize('updateMemberRole', [$tenant, $member, $role]);

            $member->syncRoles($role);
        });
    }
}
