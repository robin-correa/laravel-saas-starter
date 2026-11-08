<?php

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->end();
});

test('account deletion is blocked while the user owns a workspace', function (?bool $active) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    if ($active !== null) {
        $tenant->users()->attach($user, ['is_active' => $active]);
    }
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $this->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('ownsWorkspaces', true));
    $this->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['ownership' => 'Transfer ownership or delete your workspaces before deleting your account.']);

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id]);
})->with([
    'active ownership' => [true],
    'inactive ownership' => [false],
    'ownership grant without membership' => [null],
]);

test('ownership detection checks all workspaces in one query without changing permission context', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $ownedTenant = Tenant::factory()->hasAttached($user)->create();
    $currentTenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($ownedTenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId($currentTenant->id);
    $user->assignRole('member');
    expect($user->hasRole('member'))->toBeTrue();
    $roles = $user->getRelation('roles');
    $this->expectsDatabaseQueryCount(1);

    expect($user->ownsTenants())->toBeTrue();

    expect(getPermissionsTeamId())->toBe($currentTenant->id);
    expect($user->getRelation('roles'))->toBe($roles);
});

test('nonowners can delete their account and all memberships without deleting other users or workspaces', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->hasAttached($owner)->create();
    $secondTenant = Tenant::factory()->hasAttached($user)->hasAttached($owner)->create();
    setPermissionsTeamId($firstTenant->id);
    $user->assignRole('admin');
    $user->givePermissionTo('tenant.update');
    $owner->assignRole('owner');
    setPermissionsTeamId($secondTenant->id);
    $user->assignRole('member');
    $user->givePermissionTo('members.view');
    $owner->assignRole('owner');
    $invitation = TenantInvitation::factory()->for($firstTenant)->create(['invited_by' => $user->id]);
    $this->actingAs($user);

    $this->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('ownsWorkspaces', false));
    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('tenant_users', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id, 'model_type' => $user->getMorphClass()]);
    $this->assertDatabaseMissing('model_has_permissions', ['model_id' => $user->id, 'model_type' => $user->getMorphClass()]);
    $this->assertDatabaseHas('users', ['id' => $owner->id]);
    $this->assertDatabaseCount('tenants', 2);
    $this->assertDatabaseCount('tenant_users', 2);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $firstTenant->id, 'model_id' => $owner->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $secondTenant->id, 'model_id' => $owner->id]);
    $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id, 'invited_by' => null]);
});

test('ownership detection ignores other model types and tenant roles assigned outside their own tenant', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    $roleTenant = Tenant::factory()->create();
    $scopedOwner = Role::query()->create(['tenant_id' => $roleTenant->id, 'name' => 'owner', 'guard_name' => 'web']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole($scopedOwner);
    setPermissionsTeamId(null);
    DB::table('model_has_roles')->insert([
        'tenant_id' => $tenant->id, 'model_id' => $user->id, 'model_type' => 'AnotherModel',
        'role_id' => Role::findByName('owner')->id,
    ]);

    expect($user->ownsTenants())->toBeFalse();
    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseHas('model_has_roles', ['model_id' => $user->id, 'model_type' => 'AnotherModel']);
    $this->assertDatabaseCount('tenants', 2);
});

test('the former owner can delete their account after transferring ownership without orphaning the workspace', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    $member->assignRole('member');
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $this->put(route('tenant.ownership.update', ['tenant' => $tenant->id, 'member' => $member->id]), ['password' => 'password'])
        ->assertRedirect(route('tenant.members.index', ['tenant' => $tenant->id]));
    $this->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('ownsWorkspaces', false));
    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('owner')->id]);
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->actingAs($member)->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertOk();
});
