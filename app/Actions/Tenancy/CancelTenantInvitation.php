<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CancelTenantInvitation
{
    public function handle(User $user, Tenant $tenant, string $invitationId): void
    {
        DB::transaction(function () use ($user, $tenant, $invitationId): void {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $invitation = $tenant->invitations()->whereKey($invitationId)->lockForUpdate()->firstOrFail();

            $user->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($user)->authorize('inviteMember', [$tenant, $invitation->role]);

            $invitation->delete();
        });
    }
}
