<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\TenantRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantInvitation>
 */
class TenantInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => TenantRole::Member,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(fn (): array => ['token' => hash('sha256', $token)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subSecond()]);
    }
}
