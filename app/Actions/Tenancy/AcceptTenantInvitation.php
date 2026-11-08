<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class AcceptTenantInvitation
{
    public function __construct(private PermissionRegistrar $permissions) {}

    public function handle(User $user, string $token): Tenant
    {
        $invitation = TenantInvitation::query()->where('token', hash('sha256', $token))->firstOrFail();
        $previousTenantId = $this->permissions->getPermissionsTeamId();

        try {
            return DB::transaction(function () use ($user, $invitation, $token): Tenant {
                $tenant = Tenant::query()->whereKey($invitation->tenant_id)->lockForUpdate()->firstOrFail();
                $invitation = $tenant->invitations()->whereKey($invitation->getKey())
                    ->where('token', hash('sha256', $token))->lockForUpdate()->firstOrFail();

                abort_if($invitation->isExpired(), 410, __('This invitation has expired. Ask the workspace administrator to resend it.'));
                abort_unless(Str::lower($user->email) === $invitation->email, 403);
                abort_if($invitation->role === TenantRole::Owner, 403);
                abort_if($tenant->users()->whereKey($user->getKey())->exists(), 403, __('You already belong to this workspace.'));

                $this->permissions->setPermissionsTeamId($tenant->getKey());
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->syncRoles($invitation->role);
                $user->syncPermissions([]);
                $tenant->users()->attach($user->getKey(), ['is_active' => true]);
                $invitation->delete();

                return $tenant;
            });
        } finally {
            $this->permissions->setPermissionsTeamId($previousTenantId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
