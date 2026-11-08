<?php

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->end();
});

/**
 * @return array{User, User, Tenant}
 */
function createLifecycleWorkspace(string $actorRole = 'owner', string $memberRole = 'member'): array
{
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($actorRole);
    $member->assignRole($memberRole);
    setPermissionsTeamId(null);

    return [$user, $member, $tenant];
}

test('members and admins can leave without changing other workspace access', function (string $role, bool $hasOtherWorkspace) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $owner, $tenant] = createLifecycleWorkspace($role, 'owner');
    setPermissionsTeamId($tenant->id);
    $user->givePermissionTo('tenant.update');
    setPermissionsTeamId(null);
    $otherTenant = null;
    if ($hasOtherWorkspace) {
        $otherTenant = Tenant::factory()->hasAttached($user)->create();
        setPermissionsTeamId($otherTenant->id);
        $user->assignRole('owner');
        expect($user->hasRole('owner'))->toBeTrue();
    }

    $this->actingAs($user)->delete(route('tenant.membership.destroy', ['tenant' => $tenant->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $owner->id]);
    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $user->id]);
    $this->assertDatabaseMissing('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id]);
    $this->assertDatabaseMissing('model_has_permissions', ['tenant_id' => $tenant->id, 'model_id' => $user->id]);
    expect(tenancy()->initialized)->toBeFalse();

    if ($otherTenant) {
        $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $otherTenant->id, 'model_id' => $user->id]);
        $this->get(route('dashboard'))->assertRedirect(route('tenant.dashboard', ['tenant' => $otherTenant->id]));
    } else {
        $this->get(route('dashboard'))->assertRedirect(route('onboarding.create'));
    }
    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertNotFound();
})->with([
    'member leaves last workspace' => ['member', false],
    'admin owns a different workspace' => ['admin', true],
]);

test('owners must transfer ownership before leaving', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace();

    $this->actingAs($user)->delete(route('tenant.membership.destroy', ['tenant' => $tenant->id]))->assertForbidden();

    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $user->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id]);
});

test('ownership transfers to an active member and demotes the previous owner only in this workspace', function (string $memberRole) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace('owner', $memberRole);
    $otherTenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    setPermissionsTeamId($otherTenant->id);
    $user->assignRole('owner');
    $member->assignRole('member');
    setPermissionsTeamId(null);

    $this->actingAs($user)->put(route('tenant.ownership.update', ['tenant' => $tenant->id, 'member' => $member->id]), ['password' => 'password'])
        ->assertRedirect(route('tenant.members.index', ['tenant' => $tenant->id]));

    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('owner')->id]);
    $this->assertDatabaseMissing('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('admin')->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $otherTenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $otherTenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('member')->id]);
    $this->assertDatabaseCount('tenant_users', 4);
    $this->get(route('tenant.settings.edit', ['tenant' => $tenant->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canDelete', false)->where('canTransferOwnership', false)->where('canLeave', true));
    $this->actingAs($member)->get(route('tenant.settings.edit', ['tenant' => $tenant->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canDelete', true)->where('canTransferOwnership', true)->where('canLeave', false));
})->with(['member', 'admin']);

test('ownership of another workspace does not authorize a transfer or deletion here', function (string $method, string $route) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace('admin');
    $otherTenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($otherTenant->id);
    $user->assignRole('owner');
    expect($user->hasRole('owner'))->toBeTrue();

    $this->actingAs($user)->{$method}(route($route, ['tenant' => $tenant->id, 'member' => $member->id]), ['password' => 'password', 'confirmation' => 'DELETE'])
        ->assertForbidden();

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('admin')->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('member')->id]);
})->with([
    'transfer' => ['put', 'tenant.ownership.update'],
    'delete' => ['delete', 'tenant.destroy'],
]);

test('ownership transfer rejects self and existing owners', function (bool $self) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace('owner', 'owner');
    $target = $self ? $user : $member;

    $this->actingAs($user)->put(route('tenant.ownership.update', ['tenant' => $tenant->id, 'member' => $target->id]), ['password' => 'password'])
        ->assertSessionHasErrors(['member' => 'Choose another active member who is not already an owner.']);

    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('owner')->id]);
})->with([true, false]);

test('ownership cannot transfer to an inactive member or an outsider', function (bool $inactiveMember) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace();
    if ($inactiveMember) {
        $tenant->users()->updateExistingPivot($member->id, ['is_active' => false]);
    } else {
        $tenant->users()->detach($member->id);
        Tenant::factory()->hasAttached($member)->create();
    }

    $this->actingAs($user)->put(route('tenant.ownership.update', ['tenant' => $tenant->id, 'member' => $member->id]), ['password' => 'password'])
        ->assertNotFound();

    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id]);
    $this->assertDatabaseMissing('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('owner')->id]);
})->with([true, false]);

test('ownership transfer requires the correct password', function (?string $password, string $message) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace();

    $this->actingAs($user)->put(route('tenant.ownership.update', ['tenant' => $tenant->id, 'member' => $member->id]), ['password' => $password])
        ->assertSessionHasErrors(['password' => $message]);

    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('member')->id]);
})->with([
    'missing' => [null, 'The password field is required.'],
    'incorrect' => ['wrong-password', 'The password is incorrect.'],
]);

test('failed role reassignment rolls back the whole ownership transfer', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace();
    Role::findByName('admin')->delete();
    Exceptions::fake([RoleDoesNotExist::class]);

    $this->actingAs($user)->put(route('tenant.ownership.update', ['tenant' => $tenant->id, 'member' => $member->id]), ['password' => 'password'])
        ->assertServerError();

    Exceptions::assertReported(RoleDoesNotExist::class);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('member')->id]);
    $this->assertDatabaseMissing('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('owner')->id]);
    expect(getPermissionsTeamId())->toBeNull();
});

test('deleting a workspace removes its data and cached roles while preserving users and other workspaces', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace();
    $otherTenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    $scopedRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'scoped-role', 'guard_name' => 'web']);
    $scopedRole->givePermissionTo('members.view');
    setPermissionsTeamId($tenant->id);
    $member->assignRole($scopedRole);
    $member->givePermissionTo('tenant.update');
    setPermissionsTeamId($otherTenant->id);
    $user->assignRole('member');
    $member->assignRole('owner');
    $member->givePermissionTo('members.invite');
    $invitation = TenantInvitation::factory()->for($tenant)->create(['invited_by' => $user->id]);
    $otherInvitation = TenantInvitation::factory()->for($otherTenant)->create(['invited_by' => $member->id]);
    $permissions = app(PermissionRegistrar::class);
    expect($permissions->getPermissions()->flatMap(fn ($permission) => $permission->roles)->contains('id', $scopedRole->id))->toBeTrue();

    $this->actingAs($user)->delete(route('tenant.destroy', ['tenant' => $tenant->id]), ['password' => 'password', 'confirmation' => 'DELETE'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $tenant->id]);
    $this->assertDatabaseMissing('tenant_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseMissing('model_has_roles', ['tenant_id' => $tenant->id]);
    $this->assertDatabaseMissing('model_has_permissions', ['tenant_id' => $tenant->id]);
    $this->assertDatabaseMissing('roles', ['id' => $scopedRole->id]);
    $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $scopedRole->id]);
    $this->assertDatabaseHas('roles', ['id' => Role::findByName('owner')->id, 'tenant_id' => null]);
    $this->assertDatabaseHas('tenants', ['id' => $otherTenant->id]);
    $this->assertDatabaseHas('tenant_invitations', ['id' => $otherInvitation->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $otherTenant->id, 'model_id' => $member->id]);
    $this->assertDatabaseHas('model_has_permissions', ['tenant_id' => $otherTenant->id, 'model_id' => $member->id]);
    $this->assertDatabaseCount('users', 2);
    expect($permissions->getPermissions()->flatMap(fn ($permission) => $permission->roles)->contains('id', $scopedRole->id))->toBeFalse();
    expect(tenancy()->initialized)->toBeFalse();
    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertNotFound();
    $this->get(route('tenant.dashboard', ['tenant' => $otherTenant->id]))->assertOk();
});

test('workspace deletion requires a password and typed confirmation', function (array $input, array $errors) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace();

    $this->actingAs($user)->delete(route('tenant.destroy', ['tenant' => $tenant->id]), $input)->assertSessionHasErrors($errors);

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    $this->assertDatabaseCount('tenant_users', 2);
})->with([
    'missing fields' => [[], ['password' => 'The password field is required.', 'confirmation' => 'The confirmation field is required.']],
    'incorrect password' => [['password' => 'wrong-password', 'confirmation' => 'DELETE'], ['password' => 'The password is incorrect.']],
    'incorrect confirmation' => [['password' => 'password', 'confirmation' => 'delete'], ['confirmation' => 'The selected confirmation is invalid.']],
    'confirmation must be text' => [['password' => 'password', 'confirmation' => ['DELETE']], ['confirmation' => 'The confirmation field must be a string.']],
]);

test('lifecycle endpoints require authentication and verification', function (bool $guest, string $method, string $route) {
    $user = User::factory()->unverified()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    if (! $guest) {
        $this->actingAs($user);
    }

    $this->{$method}(route($route, ['tenant' => $tenant->id, 'member' => $member->id]), ['password' => 'password', 'confirmation' => 'DELETE'])
        ->assertRedirect($guest ? route('login') : route('verification.notice'));

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    $this->assertDatabaseCount('tenant_users', 2);
})->with([true, false])->with([
    'leave' => ['delete', 'tenant.membership.destroy'],
    'transfer' => ['put', 'tenant.ownership.update'],
    'delete' => ['delete', 'tenant.destroy'],
]);

test('inactive members and outsiders cannot use lifecycle endpoints', function (bool $inactiveMember, string $method, string $route) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createLifecycleWorkspace();
    if ($inactiveMember) {
        $tenant->users()->updateExistingPivot($user->id, ['is_active' => false]);
    } else {
        $tenant->users()->detach($user->id);
    }

    $this->actingAs($user)->{$method}(route($route, ['tenant' => $tenant->id, 'member' => $member->id]), ['password' => 'password', 'confirmation' => 'DELETE'])
        ->assertNotFound();

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $member->id]);
})->with([true, false])->with([
    'leave' => ['delete', 'tenant.membership.destroy'],
    'transfer' => ['put', 'tenant.ownership.update'],
    'delete' => ['delete', 'tenant.destroy'],
]);
