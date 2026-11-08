<?php

use App\Models\Tenant;
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

test('workspace creation requires authentication and email verification', function (bool $guest) {
    if (! $guest) {
        $this->actingAs(User::factory()->unverified()->create());
    }
    $redirect = $guest ? route('login') : route('verification.notice');

    $this->get(route('workspaces.create'))->assertRedirect($redirect);
    $this->post(route('workspaces.store'), ['name' => 'New workspace'])->assertRedirect($redirect);

    $this->assertDatabaseCount('tenants', 0);
    $this->assertDatabaseCount('tenant_users', 0);
})->with([true, false]);

test('verified users can open the creation form with or without an existing workspace', function (bool $hasWorkspace) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    if ($hasWorkspace) {
        $tenant = Tenant::factory()->hasAttached($user)->create();
        setPermissionsTeamId($tenant->id);
        $user->assignRole('member');
        setPermissionsTeamId(null);
    }

    $this->actingAs($user)->get(route('workspaces.create'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/create')->where('tenancy.current', null));
})->with([true, false]);

test('creating another workspace assigns ownership without changing existing memberships or roles', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $oldTenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Original workspace']);
    setPermissionsTeamId($oldTenant->id);
    $user->assignRole('member');
    expect($user->hasRole('member'))->toBeTrue();
    $this->actingAs($user);

    $response = $this->post(route('workspaces.store'), [
        'name' => 'Additional workspace', 'tenant_id' => $oldTenant->id, 'user_id' => $otherUser->id,
        'role' => 'member', 'is_active' => false,
    ]);

    $newTenant = Tenant::query()->where('name', 'Additional workspace')->firstOrFail();
    $response->assertRedirect(route('tenant.dashboard', ['tenant' => $newTenant->id]));
    expect($newTenant->id)->not->toBe($oldTenant->id);
    expect(getPermissionsTeamId())->toBe($oldTenant->id);
    expect($user->relationLoaded('roles'))->toBeFalse();
    $this->assertDatabaseCount('tenants', 2);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $newTenant->id, 'user_id' => $user->id, 'is_active' => true]);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $oldTenant->id, 'user_id' => $user->id, 'is_active' => true]);
    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $newTenant->id, 'user_id' => $otherUser->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $newTenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id,
    ]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $oldTenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('member')->id,
    ]);
    $this->assertDatabaseMissing('model_has_roles', [
        'tenant_id' => $oldTenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id,
    ]);
    $this->get(route('tenant.dashboard', ['tenant' => $newTenant->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenancy.current.id', $newTenant->id)
            ->where('tenancy.available', [
                ['id' => $newTenant->id, 'name' => 'Additional workspace'],
                ['id' => $oldTenant->id, 'name' => 'Original workspace'],
            ]));
});

test('workspace creation uses the existing name validation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('workspaces.create'))->post(route('workspaces.store'), ['name' => '   '])
        ->assertRedirect(route('workspaces.create'))
        ->assertSessionHasErrors(['name' => 'The workspace name field is required.']);

    $this->assertDatabaseCount('tenants', 0);
    $this->assertDatabaseCount('tenant_users', 0);
});

test('failed ownership assignment rolls back additional workspace creation and restores permission context', function () {
    $user = User::factory()->create();
    $previousTenant = Tenant::factory()->create();
    setPermissionsTeamId($previousTenant->id);
    Exceptions::fake([RoleDoesNotExist::class]);

    $this->actingAs($user)->post(route('workspaces.store'), ['name' => 'Failed workspace'])->assertServerError();

    Exceptions::assertReported(RoleDoesNotExist::class);
    $this->assertDatabaseCount('tenants', 1);
    $this->assertDatabaseMissing('tenants', ['name' => 'Failed workspace']);
    $this->assertDatabaseCount('tenant_users', 0);
    $this->assertDatabaseCount('model_has_roles', 0);
    expect(getPermissionsTeamId())->toBe($previousTenant->id);
    expect($user->relationLoaded('roles'))->toBeFalse();
});

test('workspace settings expose the selected workspace and permission to rename', function (string $role, bool $canUpdate) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Workspace A', 'internal_note' => 'private']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    setPermissionsTeamId(null);

    $this->actingAs($user)->get(route('tenant.settings.edit', ['tenant' => $tenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('tenant/settings')
        ->where('canUpdate', $canUpdate)->where('tenancy.current', ['id' => $tenant->id, 'name' => 'Workspace A'])
        ->where('canLeave', $role !== 'owner')->where('canDelete', $role === 'owner')
        ->where('canTransferOwnership', $role === 'owner')
        ->missing('tenancy.current.internal_note'));
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'member' => ['member', false],
]);

test('owners and admins rename only the selected workspace while its ID and other attributes stay unchanged', function (string $role) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Original', 'internal_note' => 'private']);
    $otherTenant = Tenant::factory()->create(['name' => 'Other workspace']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $this->patch(route('tenant.settings.update', ['tenant' => $tenant->id]), [
        'name' => 'Renamed', 'id' => $otherTenant->id, 'tenant_id' => $otherTenant->id,
        'data' => ['internal_note' => 'overwritten'], 'role' => 'owner',
    ])->assertRedirect(route('tenant.settings.edit', ['tenant' => $tenant->id]));

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Renamed']);
    $this->assertDatabaseHas('tenants', ['id' => $otherTenant->id, 'name' => 'Other workspace']);
    expect($tenant->fresh()->internal_note)->toBe('private');
    $this->assertDatabaseCount('tenant_users', 1);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName($role)->id,
    ]);
    $this->get(route('tenant.settings.edit', ['tenant' => $tenant->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenancy.current.name', 'Renamed')
            ->where('tenancy.available', [['id' => $tenant->id, 'name' => 'Renamed']]));
})->with(['owner', 'admin']);

test('members cannot forge a workspace rename request', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Original']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('member');
    setPermissionsTeamId(null);

    $this->actingAs($user)->patch(route('tenant.settings.update', ['tenant' => $tenant->id]), ['name' => 'Forged rename'])
        ->assertForbidden();

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Original']);
});

test('workspace renaming validates the name', function (mixed $name, string $message) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create(['name' => 'Original']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);

    $this->actingAs($user)->patch(route('tenant.settings.update', ['tenant' => $tenant->id]), ['name' => $name])
        ->assertSessionHasErrors(['name' => $message]);

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Original']);
})->with([
    'required' => [null, 'The workspace name field is required.'],
    'blank' => ['   ', 'The workspace name field is required.'],
    'string' => [['Invalid'], 'The workspace name field must be a string.'],
    'maximum length' => [str_repeat('a', 256), 'The workspace name field must not be greater than 255 characters.'],
]);

test('workspace renaming accepts the maximum name length', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);
    $name = str_repeat('a', 255);

    $this->actingAs($user)->patch(route('tenant.settings.update', ['tenant' => $tenant->id]), ['name' => $name])
        ->assertRedirect(route('tenant.settings.edit', ['tenant' => $tenant->id]));

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => $name]);
});

test('workspace settings require authentication and verification', function (bool $guest) {
    $tenant = Tenant::factory()->create(['name' => 'Original']);
    if (! $guest) {
        $user = User::factory()->unverified()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
    }
    $redirect = $guest ? route('login') : route('verification.notice');

    $this->get(route('tenant.settings.edit', ['tenant' => $tenant->id]))->assertRedirect($redirect);
    $this->patch(route('tenant.settings.update', ['tenant' => $tenant->id]), ['name' => 'Renamed'])->assertRedirect($redirect);

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Original']);
})->with([true, false]);

test('inactive members and outsiders cannot access or change workspace settings', function (bool $isMember) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create(['name' => 'Original']);
    if ($isMember) {
        $tenant->users()->attach($user, ['is_active' => false]);
    }
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    setPermissionsTeamId(null);

    $this->actingAs($user)->get(route('tenant.settings.edit', ['tenant' => $tenant->id]))->assertNotFound();
    $this->patch(route('tenant.settings.update', ['tenant' => $tenant->id]), ['name' => 'Forged rename'])->assertNotFound();

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Original']);
})->with([true, false]);

test('workspace creation is rate limited', function () {
    $this->seed(TenantRolesSeeder::class);
    $this->actingAs(User::factory()->create());

    for ($index = 0; $index < 6; $index++) {
        $this->post(route('workspaces.store'), ['name' => "Workspace {$index}"])->assertRedirect();
    }
    $this->post(route('workspaces.store'), ['name' => 'Rate limited'])->assertTooManyRequests();

    $this->assertDatabaseCount('tenants', 6);
    $this->assertDatabaseCount('tenant_users', 6);
});
