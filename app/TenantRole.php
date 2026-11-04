<?php

namespace App;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * @return list<TenantPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TenantPermission::cases(),
            self::Admin => [
                TenantPermission::ViewTenant,
                TenantPermission::UpdateTenant,
                TenantPermission::ViewMembers,
                TenantPermission::InviteMembers,
                TenantPermission::RemoveMembers,
            ],
            self::Member => [
                TenantPermission::ViewTenant,
                TenantPermission::ViewMembers,
            ],
        };
    }
}
