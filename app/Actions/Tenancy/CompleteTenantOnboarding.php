<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompleteTenantOnboarding
{
    public function __construct(private CreateTenant $createTenant) {}

    public function handle(User $user, string $name): Tenant
    {
        try {
            return DB::transaction(function () use ($user, $name): Tenant {
                $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                return $lockedUser->firstActiveTenant() ?? $this->createTenant->handle($user, $name);
            });
        } finally {
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
