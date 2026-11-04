<?php

use App\Models\Tenant;
use App\Models\User;
use App\TenantRole;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->end();
});

test('tenant policies apply the role permissions to the active tenant', function (string $role, bool $canUpdate) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);

    expect(Gate::forUser($user)->allows('view', $tenant))->toBeTrue();
    expect(Gate::forUser($user)->allows('viewMembers', $tenant))->toBeTrue();
    expect(Gate::forUser($user)->allows('update', $tenant))->toBe($canUpdate);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'member' => ['member', false],
]);

test('tenant invitations respect the role hierarchy', function (string $role, TenantRole $invitedRole, bool $allowed) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);

    expect(Gate::forUser($user)->allows('inviteMember', [$tenant, $invitedRole]))->toBe($allowed);
})->with([
    'owner invites member' => ['owner', TenantRole::Member, true],
    'owner invites admin' => ['owner', TenantRole::Admin, true],
    'owner cannot invite owner' => ['owner', TenantRole::Owner, false],
    'admin invites member' => ['admin', TenantRole::Member, true],
    'admin cannot invite admin' => ['admin', TenantRole::Admin, false],
    'admin cannot invite owner' => ['admin', TenantRole::Owner, false],
    'member cannot invite' => ['member', TenantRole::Member, false],
]);

test('member role changes protect owners and prevent privilege escalation', function (string $role, string $memberRole, TenantRole $newRole, bool $allowed) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    $member->assignRole($memberRole);

    expect(Gate::forUser($user)->allows('updateMemberRole', [$tenant, $member, $newRole]))->toBe($allowed);
})->with([
    'owner promotes member' => ['owner', 'member', TenantRole::Admin, true],
    'owner demotes admin' => ['owner', 'admin', TenantRole::Member, true],
    'owner cannot demote owner' => ['owner', 'owner', TenantRole::Member, false],
    'owner cannot grant ownership' => ['owner', 'member', TenantRole::Owner, false],
    'admin cannot change roles' => ['admin', 'member', TenantRole::Admin, false],
    'member cannot change roles' => ['member', 'member', TenantRole::Admin, false],
]);

test('member removal respects the role hierarchy', function (string $role, array $memberRoles, bool $allowed) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    $member->assignRole($memberRoles);

    expect(Gate::forUser($user)->allows('removeMember', [$tenant, $member]))->toBe($allowed);
})->with([
    'owner removes member' => ['owner', ['member'], true],
    'owner removes admin' => ['owner', ['admin'], true],
    'owner cannot remove owner' => ['owner', ['owner'], false],
    'admin removes member' => ['admin', ['member'], true],
    'admin cannot remove admin' => ['admin', ['admin'], false],
    'admin cannot remove owner' => ['admin', ['owner'], false],
    'admin cannot remove admin with another role' => ['admin', ['admin', 'member'], false],
    'member cannot remove member' => ['member', ['member'], false],
]);

test('owners cannot manage users outside their tenant', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    Tenant::factory()->hasAttached($outsider)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');

    expect(Gate::forUser($user)->allows('removeMember', [$tenant, $outsider]))->toBeFalse();
    expect(Gate::forUser($user)->allows('updateMemberRole', [$tenant, $outsider, TenantRole::Admin]))->toBeFalse();
});

test('tenant policies deny access outside the selected tenant', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    $otherTenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');

    expect(Gate::forUser($user)->allows('view', $otherTenant))->toBeFalse();
    expect(Gate::forUser($user)->allows('update', $otherTenant))->toBeFalse();
});

test('tenant policies deny access to inactive members with owner permissions', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user, ['is_active' => false])->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');

    expect(Gate::forUser($user)->allows('view', $tenant))->toBeFalse();
    expect(Gate::forUser($user)->allows('update', $tenant))->toBeFalse();
});
