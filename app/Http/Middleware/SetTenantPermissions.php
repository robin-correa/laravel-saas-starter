<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetTenantPermissions
{
    public function __construct(private PermissionRegistrar $permissions) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();
        $user = $request->user();

        abort_unless($tenant instanceof Tenant && $user instanceof User, 404);

        $previousTenantId = $this->permissions->getPermissionsTeamId();
        $this->permissions->setPermissionsTeamId($tenant->getKey());
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $next($request);
        } finally {
            $this->permissions->setPermissionsTeamId($previousTenantId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
