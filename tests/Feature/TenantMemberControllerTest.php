<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    DB::disableQueryLog();
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->end();
});

test('member endpoints require authentication', function (string $method, string $route) {
    $tenant = Tenant::factory()->create();
    $member = User::factory()->create();

    $this->{$method}(route($route, ['tenant' => $tenant->id, 'member' => $member->id]), ['role' => 'admin'])
        ->assertRedirect(route('login'));
})->with([
    'list' => ['get', 'tenant.members.index'],
    'change role' => ['patch', 'tenant.members.update'],
    'remove' => ['delete', 'tenant.members.destroy'],
]);

test('members see only the selected workspace and actions allowed by their role', function (string $role, bool $canUpdate, bool $canRemoveAdmin, bool $canRemoveMember) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create(['name' => 'Z Viewer']);
    $owner = User::factory()->create(['name' => 'A Owner']);
    $admin = User::factory()->create(['name' => 'B Admin']);
    $member = User::factory()->create(['name' => 'C Member']);
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($owner)->hasAttached($admin)
        ->hasAttached($member, ['is_active' => false])->create();
    $otherTenant = Tenant::factory()->hasAttached($member)->hasAttached(User::factory()->create())->create();
    setPermissionsTeamId($otherTenant->id);
    $member->assignRole('owner');
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    $owner->assignRole('owner');
    $admin->assignRole('admin');
    $member->assignRole('member');
    setPermissionsTeamId(null);

    $this->actingAs($user)->get(route('tenant.members.index', ['tenant' => $tenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('tenant/members')
        ->where('members.total', 4)
        ->has('members.data', 4)
        ->where('members.data.0.id', $owner->id)
        ->where('members.data.0.can_update_role', false)
        ->where('members.data.0.can_remove', false)
        ->where('members.data.0.can_transfer_ownership', false)
        ->where('members.data.1.id', $admin->id)
        ->where('members.data.1.can_update_role', $canUpdate)
        ->where('members.data.1.can_remove', $canRemoveAdmin)
        ->where('members.data.1.can_transfer_ownership', $canUpdate)
        ->where('members.data.2.id', $member->id)
        ->where('members.data.2.email', $member->email)
        ->where('members.data.2.is_active', false)
        ->where('members.data.2.can_transfer_ownership', false)
        ->where('members.data.2.roles', ['member'])
        ->where('members.data.2.can_update_role', $canUpdate)
        ->where('members.data.2.can_remove', $canRemoveMember)
        ->missing('members.data.0.password')
        ->missing('members.data.0.two_factor_secret'));
})->with([
    'owner' => ['owner', true, true, true],
    'admin' => ['admin', false, false, true],
    'member' => ['member', false, false, false],
]);

test('viewing members requires the members view permission', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('tenant.view');
    setPermissionsTeamId(null);

    $this->actingAs($user)->get(route('tenant.members.index', ['tenant' => $tenant->id]))->assertForbidden();
});

test('inactive members and nonmembers cannot access member endpoints', function (bool $isMember, string $method, string $route) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($member)->create();
    if ($isMember) {
        $tenant->users()->attach($user, ['is_active' => false]);
    }
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);

    $this->actingAs($user)->{$method}(route($route, ['tenant' => $tenant->id, 'member' => $member->id]), ['role' => 'admin'])
        ->assertNotFound();

    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $member->id]);
})->with([
    'inactive list' => [true, 'get', 'tenant.members.index'],
    'nonmember update' => [false, 'patch', 'tenant.members.update'],
    'nonmember removal' => [false, 'delete', 'tenant.members.destroy'],
]);

test('owners change roles only in the selected workspace using validated input', function (string $oldRole, string $newRole) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create(['name' => 'Original Name']);
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    $otherTenant = Tenant::factory()->hasAttached($member)->create();
    setPermissionsTeamId($otherTenant->id);
    $member->assignRole('owner');
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    $member->assignRole($oldRole);
    setPermissionsTeamId(null);

    $this->actingAs($user)->patch(route('tenant.members.update', ['tenant' => $tenant->id, 'member' => $member->id]), [
        'role' => $newRole,
        'name' => 'Forged Name',
        'tenant_id' => $otherTenant->id,
        'is_active' => false,
    ])->assertRedirect(route('tenant.members.index', ['tenant' => $tenant->id]));

    $this->assertDatabaseHas('users', ['id' => $member->id, 'name' => 'Original Name']);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $member->id, 'is_active' => true]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $member->id,
        'role_id' => Role::findByName($newRole)->id,
    ]);
    $this->assertDatabaseMissing('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $member->id,
        'role_id' => Role::findByName($oldRole)->id,
    ]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $otherTenant->id, 'model_id' => $member->id,
        'role_id' => Role::findByName('owner')->id,
    ]);
    expect(getPermissionsTeamId())->toBeNull();
})->with([
    'promotion' => ['member', 'admin'],
    'demotion' => ['admin', 'member'],
]);

test('admins and members cannot forge a role change', function (string $role) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    $member->assignRole('member');
    setPermissionsTeamId(null);

    $this->actingAs($user)->patch(route('tenant.members.update', ['tenant' => $tenant->id, 'member' => $member->id]), ['role' => 'admin'])
        ->assertForbidden();

    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('member')->id,
    ]);
    $this->assertDatabaseMissing('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('admin')->id,
    ]);
})->with(['admin', 'member']);

test('owners cannot be demoted or removed through member endpoints', function (string $method, string $route) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($owner)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    $owner->assignRole('owner');
    setPermissionsTeamId(null);

    $this->actingAs($user)->{$method}(route($route, ['tenant' => $tenant->id, 'member' => $owner->id]), ['role' => 'member'])
        ->assertForbidden();

    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $owner->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $owner->id, 'role_id' => Role::findByName('owner')->id,
    ]);
})->with([
    'role change' => ['patch', 'tenant.members.update'],
    'removal' => ['delete', 'tenant.members.destroy'],
]);

test('role changes reject missing invalid and ownership values', function (array $input, string $message) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    $member->assignRole('member');
    setPermissionsTeamId(null);

    $this->actingAs($user)->patch(route('tenant.members.update', ['tenant' => $tenant->id, 'member' => $member->id]), $input)
        ->assertSessionHasErrors(['role' => $message]);

    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('member')->id,
    ]);
})->with([
    'missing' => [[], 'The role field is required.'],
    'invalid' => [['role' => 'super-admin'], 'The selected role is invalid.'],
    'ownership' => [['role' => 'owner'], 'The selected role is invalid.'],
]);

test('member mutations return 404 for users outside the selected workspace', function (string $method, string $route) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    $otherTenant = Tenant::factory()->hasAttached($outsider)->create();
    setPermissionsTeamId($otherTenant->id);
    $outsider->assignRole('member');
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);

    $this->actingAs($user)->{$method}(route($route, ['tenant' => $tenant->id, 'member' => $outsider->id]), ['role' => 'admin'])
        ->assertNotFound();

    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $otherTenant->id, 'user_id' => $outsider->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $otherTenant->id, 'model_id' => $outsider->id, 'role_id' => Role::findByName('member')->id,
    ]);
})->with([
    'role change' => ['patch', 'tenant.members.update'],
    'removal' => ['delete', 'tenant.members.destroy'],
]);

test('removal clears tenant grants and preserves the account and other workspace access', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    $otherTenant = Tenant::factory()->hasAttached($member)->create();
    setPermissionsTeamId($otherTenant->id);
    $member->assignRole('owner');
    $member->givePermissionTo('members.invite');
    setPermissionsTeamId($tenant->id);
    $user->assignRole('admin');
    $member->assignRole('member');
    $member->givePermissionTo('tenant.view');
    setPermissionsTeamId(null);

    $this->actingAs($user)->delete(route('tenant.members.destroy', ['tenant' => $tenant->id, 'member' => $member->id]))
        ->assertRedirect(route('tenant.members.index', ['tenant' => $tenant->id]));

    $this->assertDatabaseHas('users', ['id' => $member->id]);
    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $member->id]);
    $this->assertDatabaseMissing('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id]);
    $this->assertDatabaseMissing('model_has_permissions', ['tenant_id' => $tenant->id, 'model_id' => $member->id]);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $otherTenant->id, 'user_id' => $member->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $otherTenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('owner')->id,
    ]);
    $this->assertDatabaseHas('model_has_permissions', [
        'tenant_id' => $otherTenant->id, 'model_id' => $member->id, 'permission_id' => Permission::findByName('members.invite')->id,
    ]);
    $this->actingAs($member)->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertNotFound();
    $this->get(route('tenant.dashboard', ['tenant' => $otherTenant->id]))->assertOk();

    $tenant->users()->attach($member);

    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertForbidden();
});

test('unauthorized removal requests do not change memberships or roles', function (string $role, string $memberRole) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    $member->assignRole($memberRole);
    setPermissionsTeamId(null);

    $this->actingAs($user)->delete(route('tenant.members.destroy', ['tenant' => $tenant->id, 'member' => $member->id]))
        ->assertForbidden();

    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $member->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName($memberRole)->id,
    ]);
})->with([
    'member cannot remove a member' => ['member', 'member'],
    'admin cannot remove another admin' => ['admin', 'admin'],
]);

test('members are paginated and ordered within the selected workspace', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create(['name' => 'Z Owner']);
    $tenant = Tenant::factory()->hasAttached($user)->create();
    for ($index = 0; $index < 21; $index++) {
        $tenant->users()->attach(User::factory()->create(['name' => sprintf('Member %02d', $index)]));
    }
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $this->get(route('tenant.members.index', ['tenant' => $tenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('members.data', 20)->where('members.total', 22)
        ->where('members.data.0.name', 'Member 00'));
    $this->get(route('tenant.members.index', ['tenant' => $tenant->id, 'page' => 2]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('members.data', 2)->where('members.current_page', 2)
        ->where('members.data.0.name', 'Member 20')->where('members.data.1.id', $user->id));
});

test('removing your own membership with a direct grant redirects outside that workspace', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('member');
    $user->givePermissionTo('members.remove');
    setPermissionsTeamId(null);

    $this->actingAs($user)->delete(route('tenant.members.destroy', ['tenant' => $tenant->id, 'member' => $user->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $user->id]);
    $this->assertDatabaseMissing('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id]);
    $this->assertDatabaseMissing('model_has_permissions', ['tenant_id' => $tenant->id, 'model_id' => $user->id]);
});

test('listing more members does not add queries for each member', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);
    $this->actingAs($user);
    $url = route('tenant.members.index', ['tenant' => $tenant->id]);
    $this->get($url)->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get($url)->assertOk();
    $initialQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $tenant->users()->attach(User::factory()->count(8)->create()->modelKeys());

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('members.data', 9));
    $expandedQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($initialQueryCount)->toBeGreaterThan(0);
    expect($expandedQueryCount)->toBe($initialQueryCount);
});
