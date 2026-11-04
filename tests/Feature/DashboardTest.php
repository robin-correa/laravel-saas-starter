<?php

use App\Models\Tenant;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users without active memberships are redirected to onboarding', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('onboarding.create'));
});

test('users are redirected to their earliest active workspace', function () {
    $user = User::factory()->create();
    Tenant::factory()->hasAttached($user, ['is_active' => false])->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->create();
    $secondTenant = Tenant::factory()->hasAttached($user)->create();
    $user->tenants()->updateExistingPivot($firstTenant->id, ['created_at' => '2026-01-01 00:00:00']);
    $user->tenants()->updateExistingPivot($secondTenant->id, ['created_at' => '2026-02-01 00:00:00']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('tenant.dashboard', ['tenant' => $firstTenant->id]));
});
