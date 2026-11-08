<?php

namespace App\Models;

use App\TenantRole;
use Carbon\CarbonImmutable;
use Database\Factories\TenantInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $tenant_id
 * @property int|null $invited_by
 * @property string $email
 * @property TenantRole $role
 * @property string $token
 * @property CarbonImmutable $expires_at
 * @property-read Tenant $tenant
 */
#[Fillable(['email', 'role'])]
#[Hidden(['token'])]
class TenantInvitation extends Model
{
    /** @use HasFactory<TenantInvitationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => TenantRole::class, 'expires_at' => 'immutable_datetime'];
    }
}
