<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

afterEach(function () {
    tenancy()->end();
});

test('guests are redirected to login when visiting a tenant dashboard', function () {
    $tenant = Tenant::factory()->create();

    $response = $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]));

    $response->assertRedirect(route('login'));
});

test('members receive 404 when visiting another tenant dashboard', function () {
    $user = User::factory()->create();
    Tenant::factory()->hasAttached($user)->create();
    $otherTenant = Tenant::factory()->hasAttached(User::factory())->create();
    $this->actingAs($user);

    $response = $this->get(route('tenant.dashboard', ['tenant' => $otherTenant->id]));

    $response->assertNotFound();
});

test('inactive members receive 404 even when active in another tenant', function () {
    $user = User::factory()->create();
    Tenant::factory()->hasAttached($user)->create();
    $inactiveTenant = Tenant::factory()->hasAttached($user, ['is_active' => false])->create();
    $this->actingAs($user);

    $response = $this->get(route('tenant.dashboard', ['tenant' => $inactiveTenant->id]));

    $response->assertNotFound();
});

test('membership is checked again after it is deactivated', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('member');
    setPermissionsTeamId(null);
    $this->actingAs($user);
    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertOk();

    $user->tenants()->updateExistingPivot($tenant->id, ['is_active' => false]);

    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertNotFound();
});

test('tenant names are stored in their dedicated column', function (?string $name) {
    $tenant = Tenant::factory()->create(['name' => $name]);

    $tenant->refresh();

    expect($tenant->name)->toBe($name);
    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => $name]);
})->with([
    'named tenant' => 'Acme',
    'unnamed existing tenant' => null,
]);

test('a user can only have one membership in each tenant', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();

    expect(fn () => $tenant->users()->attach($user))
        ->toThrow(UniqueConstraintViolationException::class);
});
