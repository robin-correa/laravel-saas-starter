<?php

namespace App;

enum TenantPermission: string
{
    case ViewTenant = 'tenant.view';
    case UpdateTenant = 'tenant.update';
    case ViewMembers = 'members.view';
    case InviteMembers = 'members.invite';
    case UpdateMemberRole = 'members.update-role';
    case RemoveMembers = 'members.remove';
}
