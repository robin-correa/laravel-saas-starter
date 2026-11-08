<?php

namespace App\Models;

use App\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    public function firstActiveTenant(): ?Tenant
    {
        return $this->tenants()
            ->wherePivot('is_active', true)
            ->orderBy('tenant_users.created_at')
            ->orderBy('tenants.id')
            ->first();
    }

    public function ownsTenants(): bool
    {
        $assignments = config('permission.table_names.model_has_roles');
        $roles = config('permission.table_names.roles');
        $tenantKey = config('permission.column_names.team_foreign_key');
        $modelKey = config('permission.column_names.model_morph_key');
        $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';

        $ownedTenantIds = DB::table($assignments)
            ->select($assignments.'.'.$tenantKey)
            ->join($roles, $roles.'.id', '=', $assignments.'.'.$roleKey)
            ->where($assignments.'.'.$modelKey, $this->getKey())
            ->where($assignments.'.model_type', $this->getMorphClass())
            ->where($roles.'.name', TenantRole::Owner->value)
            ->where($roles.'.guard_name', 'web')
            ->where(fn (QueryBuilder $query): QueryBuilder => $query
                ->whereNull($roles.'.'.$tenantKey)
                ->orWhereColumn($roles.'.'.$tenantKey, $assignments.'.'.$tenantKey));

        return Tenant::query()->whereIn('tenants.id', $ownedTenantIds)->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
