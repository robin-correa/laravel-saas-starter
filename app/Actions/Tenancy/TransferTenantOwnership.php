<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransferTenantOwnership
{
    public function handle(User $user, Tenant $tenant, string $memberId): void
    {
        DB::transaction(function () use ($user, $tenant, $memberId): void {
            $tenant->refreshForUpdate();
            $user->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($user)->authorize('transferOwnership', $tenant);

            $member = $tenant->users()->whereKey($memberId)->wherePivot('is_active', true)->lockForUpdate()->firstOrFail();

            if ($member->is($user) || $member->hasRole(TenantRole::Owner)) {
                throw ValidationException::withMessages(['member' => __('Choose another active member who is not already an owner.')]);
            }

            $member->syncRoles(TenantRole::Owner);
            $user->syncRoles(TenantRole::Admin);
        });
    }
}
