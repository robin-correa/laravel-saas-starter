<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\TenantPermission;
use App\TenantRole;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $this->hasTenantPermission($user, $tenant, TenantPermission::ViewTenant);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->hasTenantPermission($user, $tenant, TenantPermission::UpdateTenant);
    }

    public function leave(User $user, Tenant $tenant): bool
    {
        return $this->hasActiveMembership($user, $tenant) && ! $user->hasRole(TenantRole::Owner);
    }

    public function transferOwnership(User $user, Tenant $tenant): bool
    {
        return $this->hasActiveMembership($user, $tenant) && $user->hasRole(TenantRole::Owner);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $this->hasActiveMembership($user, $tenant) && $user->hasRole(TenantRole::Owner);
    }

    public function viewMembers(User $user, Tenant $tenant): bool
    {
        return $this->hasTenantPermission($user, $tenant, TenantPermission::ViewMembers);
    }

    public function inviteMember(User $user, Tenant $tenant, TenantRole $role = TenantRole::Member): bool
    {
        return $this->hasTenantPermission($user, $tenant, TenantPermission::InviteMembers)
            && $role !== TenantRole::Owner
            && ($role === TenantRole::Member || $user->hasRole(TenantRole::Owner));
    }

    public function updateMemberRole(User $user, Tenant $tenant, User $member, TenantRole $role): bool
    {
        return $this->hasTenantPermission($user, $tenant, TenantPermission::UpdateMemberRole)
            && $user->hasRole(TenantRole::Owner)
            && $role !== TenantRole::Owner
            && $tenant->users()->whereKey($member->getKey())->exists()
            && ! $member->roles()->where('name', TenantRole::Owner->value)->exists();
    }

    public function removeMember(User $user, Tenant $tenant, User $member): bool
    {
        if (! $this->hasTenantPermission($user, $tenant, TenantPermission::RemoveMembers)
            || ! $tenant->users()->whereKey($member->getKey())->exists()
            || $member->roles()->where('name', TenantRole::Owner->value)->exists()) {
            return false;
        }

        return $user->hasRole(TenantRole::Owner)
            || ! $member->roles()->where('name', TenantRole::Admin->value)->exists();
    }

    private function hasTenantPermission(User $user, Tenant $tenant, TenantPermission $permission): bool
    {
        return $this->hasActiveMembership($user, $tenant) && $user->can($permission->value);
    }

    private function hasActiveMembership(User $user, Tenant $tenant): bool
    {
        return getPermissionsTeamId() === $tenant->getKey()
            && $user->tenants()->whereKey($tenant->getKey())->wherePivot('is_active', true)->exists();
    }
}
