<?php

use App\Actions\Tenancy\GetAccessibleTenants;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantRolesSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->end();
});

test('tenant initialization preserves shared application asset URLs', function () {
    $tenant = Tenant::factory()->create();
    $assetUrl = asset('build/app.js');

    tenancy()->initialize($tenant);

    expect(asset('build/app.js'))->toBe($assetUrl);
});

test('the workspace list contains only active memberships with view permission for the signed in user', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Alpha', 'internal_note' => 'private']);
    $secondTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Beta']);
    $inactiveTenant = Tenant::factory()->hasAttached($user, ['is_active' => false])->create();
    Tenant::factory()->hasAttached($user)->create();
    $otherUsersTenant = Tenant::factory()->hasAttached($otherUser)->create();
    $otherUsersRole = Tenant::factory()->hasAttached($user)->hasAttached($otherUser)->create();
    setPermissionsTeamId($firstTenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId($secondTenant->id);
    $user->assignRole('member');
    setPermissionsTeamId($inactiveTenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId($otherUsersTenant->id);
    $otherUser->assignRole('owner');
    setPermissionsTeamId($otherUsersRole->id);
    $otherUser->assignRole('owner');
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $response = $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('tenancy.current', ['id' => $firstTenant->id, 'name' => 'Alpha'])
        ->where('tenancy.available', [
            ['id' => $firstTenant->id, 'name' => 'Alpha'],
            ['id' => $secondTenant->id, 'name' => 'Beta'],
        ]));
});

test('the URL selects the workspace independently of other requests and session values', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Alpha']);
    $secondTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Beta']);
    setPermissionsTeamId($firstTenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId($secondTenant->id);
    $user->assignRole('member');
    setPermissionsTeamId(null);
    $this->actingAs($user)->withSession(['tenant_id' => $secondTenant->id]);

    $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('tenancy.current.id', $firstTenant->id));
    $this->get(route('tenant.dashboard', ['tenant' => $secondTenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('tenancy.current.id', $secondTenant->id));
    $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('tenancy.current.id', $firstTenant->id));

    expect(getPermissionsTeamId())->toBeNull();
});

test('workspace discovery supports direct grants without changing the current permission context', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Alpha']);
    $secondTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Beta']);
    setPermissionsTeamId($secondTenant->id);
    $user->givePermissionTo('tenant.view');
    setPermissionsTeamId($firstTenant->id);
    $user->assignRole('owner');
    expect($user->hasRole('owner'))->toBeTrue();
    $roles = $user->getRelation('roles');

    $this->expectsDatabaseQueryCount(1);
    $workspaces = app(GetAccessibleTenants::class)->handle($user);

    expect($workspaces)->toBe([
        ['id' => $firstTenant->id, 'name' => 'Alpha'],
        ['id' => $secondTenant->id, 'name' => 'Beta'],
    ]);
    expect(getPermissionsTeamId())->toBe($firstTenant->id);
    expect($user->getRelation('roles'))->toBe($roles);
});

test('tenant specific roles grant access only within their own tenant', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Alpha']);
    $secondTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Beta']);
    $role = Role::create(['name' => 'workspace-viewer', 'guard_name' => 'web', 'tenant_id' => $firstTenant->id]);
    $role->givePermissionTo('tenant.view');
    setPermissionsTeamId($firstTenant->id);
    $user->assignRole($role);
    setPermissionsTeamId($secondTenant->id);
    $user->assignRole($role);
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tenancy.available', [['id' => $firstTenant->id, 'name' => 'Alpha']]));
    $this->get(route('tenant.dashboard', ['tenant' => $secondTenant->id]))->assertForbidden();
});

test('inactive workspaces disappear from the switcher and reject direct access', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Alpha']);
    $secondTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Beta']);
    setPermissionsTeamId($firstTenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId($secondTenant->id);
    $user->assignRole('member');
    setPermissionsTeamId(null);
    $this->actingAs($user);
    $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]))
        ->assertInertia(fn (Assert $page) => $page->has('tenancy.available', 2));

    $user->tenants()->updateExistingPivot($secondTenant->id, ['is_active' => false]);

    $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenancy.available', [['id' => $firstTenant->id, 'name' => 'Alpha']]));
    $this->get(route('tenant.dashboard', ['tenant' => $secondTenant->id]))->assertNotFound();
});

test('revoked view permissions disappear from the switcher and reject direct access', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Alpha']);
    $secondTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Beta']);
    setPermissionsTeamId($firstTenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId($secondTenant->id);
    $user->assignRole('member');
    setPermissionsTeamId(null);
    $this->actingAs($user);
    $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]))
        ->assertInertia(fn (Assert $page) => $page->has('tenancy.available', 2));

    setPermissionsTeamId($secondTenant->id);
    $user->removeRole('member');
    setPermissionsTeamId(null);

    $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenancy.available', [['id' => $firstTenant->id, 'name' => 'Alpha']]));
    $this->get(route('tenant.dashboard', ['tenant' => $secondTenant->id]))->assertForbidden();
});

test('global pages do not inherit a workspace from a previous request', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Alpha']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);
    $this->actingAs($user);
    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertOk();

    $this->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tenancy.current', null)
        ->where('tenancy.available', [['id' => $tenant->id, 'name' => 'Alpha']]));
});

test('signed out users receive no workspace data', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);
    $this->actingAs($user);
    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertOk();

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null)
        ->where('tenancy', ['current' => null, 'available' => []]));
});
