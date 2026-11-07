<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RemoveTenantMember
{
    public function handle(User $user, Tenant $tenant, string $memberId): void
    {
        DB::transaction(function () use ($user, $tenant, $memberId): void {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $member = $tenant->users()->whereKey($memberId)->firstOrFail();

            $user->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($user)->authorize('removeMember', [$tenant, $member]);

            $member->syncRoles([]);
            $member->syncPermissions([]);
            $tenant->users()->detach($member->getKey());
        });
    }
}
