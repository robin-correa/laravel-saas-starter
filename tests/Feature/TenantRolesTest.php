<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->end();
});

test('role seeding is repeatable and preserves the current tenant context', function () {
    $tenant = Tenant::factory()->create();
    setPermissionsTeamId($tenant->id);

    $this->seed(TenantRolesSeeder::class);
    $this->seed(TenantRolesSeeder::class);

    expect(getPermissionsTeamId())->toBe($tenant->id);
    $this->assertDatabaseCount('roles', 3);
    $this->assertDatabaseCount('permissions', 6);
    $this->assertDatabaseMissing('roles', ['tenant_id' => $tenant->id]);
});

test('roles grant the agreed tenant permissions', function (string $role, array $expectedPermissions) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);

    foreach ($expectedPermissions as $permission => $allowed) {
        expect($user->can($permission))->toBe($allowed);
    }
})->with([
    'owner' => ['owner', [
        'tenant.view' => true,
        'tenant.update' => true,
        'members.view' => true,
        'members.invite' => true,
        'members.update-role' => true,
        'members.remove' => true,
    ]],
    'admin' => ['admin', [
        'tenant.view' => true,
        'tenant.update' => true,
        'members.view' => true,
        'members.invite' => true,
        'members.update-role' => false,
        'members.remove' => true,
    ]],
    'member' => ['member', [
        'tenant.view' => true,
        'tenant.update' => false,
        'members.view' => true,
        'members.invite' => false,
        'members.update-role' => false,
        'members.remove' => false,
    ]],
]);

test('an active member without a role receives 403 on the tenant dashboard', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    $this->actingAs($user);

    $response = $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]));

    $response->assertForbidden();
    expect(getPermissionsTeamId())->toBeNull();
    expect($user->relationLoaded('roles'))->toBeFalse();
});

test('tenant permissions follow the URL before route binding and are cleared after requests', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $ownedTenant = Tenant::factory()->hasAttached($user)->create();
    $memberTenant = Tenant::factory()->hasAttached($user)->create(['id' => 'account-demo']);
    $directPermissionTenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($ownedTenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId($memberTenant->id);
    $user->assignRole('member');
    setPermissionsTeamId($directPermissionTenant->id);
    $user->assignRole('member');
    $user->givePermissionTo('tenant.update');
    setPermissionsTeamId(null);
    $this->actingAs($user);

    Route::bind('permissionProbe', fn (string $value): array => [
        'can_update' => Gate::allows('update', tenant()),
    ]);
    Route::get('/{tenant}/permission-probe/{permissionProbe}', fn (array $permissionProbe): array => $permissionProbe)
        ->middleware(Route::getRoutes()->getByName('tenant.dashboard')->gatherMiddleware());
    Route::get('/central-permission-probe', fn (): array => [
        'can_update' => $user->can('tenant.update'),
    ])->middleware(['web', 'auth']);

    $this->get('/'.$ownedTenant->id.'/permission-probe/check')
        ->assertOk()->assertExactJson(['can_update' => true]);
    $this->get('/'.$memberTenant->id.'/permission-probe/check')
        ->assertOk()->assertExactJson(['can_update' => false]);
    $this->get('/'.$directPermissionTenant->id.'/permission-probe/check')
        ->assertOk()->assertExactJson(['can_update' => true]);
    $this->get('/'.$memberTenant->id.'/permission-probe/check')
        ->assertOk()->assertExactJson(['can_update' => false]);
    $this->get('/central-permission-probe')
        ->assertOk()->assertExactJson(['can_update' => false]);
    $this->get('/'.$ownedTenant->id.'/permission-probe/check')
        ->assertOk()->assertExactJson(['can_update' => true]);

    expect(getPermissionsTeamId())->toBeNull();
    expect($user->relationLoaded('roles'))->toBeFalse();
    expect($user->relationLoaded('permissions'))->toBeFalse();
});

test('the owner role cannot bypass an inactive membership', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user, ['is_active' => false])->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertNotFound();
});

test('the owner role cannot bypass a missing membership', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertNotFound();
});

test('deleting a tenant removes its permission assignments and preserves reusable roles', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('member');
    $user->givePermissionTo('members.invite');

    $tenant->delete();

    $this->assertDatabaseMissing('model_has_roles', ['tenant_id' => $tenant->id]);
    $this->assertDatabaseMissing('model_has_permissions', ['tenant_id' => $tenant->id]);
    $this->assertDatabaseCount('roles', 3);
});
