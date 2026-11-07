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

test('guests must log in before viewing or submitting onboarding', function () {
    $this->get(route('onboarding.create'))->assertRedirect(route('login'));
    $this->post(route('onboarding.store'), ['name' => 'Acme'])->assertRedirect(route('login'));

    $this->assertDatabaseCount('tenants', 0);
});

test('users must verify their email before viewing or submitting onboarding', function () {
    $this->actingAs(User::factory()->unverified()->create());

    $this->get(route('onboarding.create'))->assertRedirect(route('verification.notice'));
    $this->post(route('onboarding.store'), ['name' => 'Acme'])->assertRedirect(route('verification.notice'));

    $this->assertDatabaseCount('tenants', 0);
});

test('users without an active workspace can view the onboarding form', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('onboarding.create'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('onboarding/create'));
});

test('onboarding creates a workspace and assigns ownership to the signed in user', function (string $name) {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.store'), [
        'name' => $name,
        'tenant_id' => 'client-controlled-id',
        'user_id' => $otherUser->id,
        'role' => 'member',
        'is_active' => false,
    ]);

    $tenant = Tenant::query()->sole();
    $response->assertRedirect(route('tenant.dashboard', ['tenant' => $tenant->id]));
    expect($tenant->id)->not->toBe('client-controlled-id');
    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => $name]);
    $this->assertDatabaseHas('tenant_users', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'is_active' => true,
    ]);
    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $otherUser->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id,
        'model_id' => $user->id,
        'model_type' => $user->getMorphClass(),
        'role_id' => Role::findByName('owner', 'web')->id,
    ]);
    expect(getPermissionsTeamId())->toBeNull();
    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertOk();
})->with([
    'ordinary name' => 'Acme',
    'maximum name length' => str_repeat('a', 255),
]);

test('workspace names are validated before anything is created', function (mixed $name, string $message) {
    $this->actingAs(User::factory()->create());

    $response = $this->from(route('onboarding.create'))->post(route('onboarding.store'), ['name' => $name]);

    $response->assertRedirect(route('onboarding.create'))->assertSessionHasErrors(['name' => $message]);
    $this->assertDatabaseCount('tenants', 0);
    $this->assertDatabaseCount('tenant_users', 0);
})->with([
    'required' => [null, 'The workspace name field is required.'],
    'blank name' => ['   ', 'The workspace name field is required.'],
    'string' => [['Acme'], 'The workspace name field must be a string.'],
    'maximum length' => [str_repeat('a', 256), 'The workspace name field must not be greater than 255 characters.'],
]);

test('Inertia onboarding submissions redirect to the tenant dashboard', function () {
    $this->seed(TenantRolesSeeder::class);
    $this->actingAs(User::factory()->create());

    $response = $this->withHeader('X-Inertia', 'true')->post(route('onboarding.store'), ['name' => 'Acme']);

    $tenant = Tenant::query()->sole();
    $response->assertRedirect(route('tenant.dashboard', ['tenant' => $tenant->id]));
});

test('active members skip onboarding without gaining ownership of their workspace', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('member');
    setPermissionsTeamId(null);
    $this->actingAs($user);

    $this->get(route('onboarding.create'))->assertRedirect(route('tenant.dashboard', ['tenant' => $tenant->id]));
    $this->post(route('onboarding.store'), ['name' => 'Unwanted workspace'])
        ->assertRedirect(route('tenant.dashboard', ['tenant' => $tenant->id]));

    $this->assertDatabaseCount('tenants', 1);
    $this->assertDatabaseMissing('tenants', ['name' => 'Unwanted workspace']);
    $this->assertDatabaseMissing('model_has_roles', [
        'tenant_id' => $tenant->id,
        'role_id' => Role::findByName('owner', 'web')->id,
    ]);
});

test('repeated onboarding submissions create only one workspace', function () {
    $this->seed(TenantRolesSeeder::class);
    $this->actingAs(User::factory()->create());
    $this->post(route('onboarding.store'), ['name' => 'Acme']);
    $tenant = Tenant::query()->sole();

    $response = $this->post(route('onboarding.store'), ['name' => 'Duplicate']);

    $response->assertRedirect(route('tenant.dashboard', ['tenant' => $tenant->id]));
    $this->assertDatabaseCount('tenants', 1);
    $this->assertDatabaseCount('tenant_users', 1);
    $this->assertDatabaseCount('model_has_roles', 1);
    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Acme']);
});

test('users with only inactive memberships can onboard without changing their old roles', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $oldTenant = Tenant::factory()->hasAttached($user, ['is_active' => false])->create();
    setPermissionsTeamId($oldTenant->id);
    $user->assignRole('member');
    $this->actingAs($user);

    $response = $this->post(route('onboarding.store'), ['name' => 'New workspace']);

    $newTenant = Tenant::query()->where('name', 'New workspace')->firstOrFail();
    $response->assertRedirect(route('tenant.dashboard', ['tenant' => $newTenant->id]));
    expect(getPermissionsTeamId())->toBe($oldTenant->id);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $oldTenant->id, 'user_id' => $user->id, 'is_active' => false]);
    expect($user->hasRole('member'))->toBeTrue();
    expect($user->hasRole('owner'))->toBeFalse();
});

test('failed owner assignment rolls back onboarding and restores the permission context', function () {
    $user = User::factory()->create();
    $previousTenant = Tenant::factory()->create();
    setPermissionsTeamId($previousTenant->id);
    Exceptions::fake([RoleDoesNotExist::class]);
    $this->actingAs($user);

    $response = $this->post(route('onboarding.store'), ['name' => 'Failed workspace']);

    $response->assertServerError();
    Exceptions::assertReported(RoleDoesNotExist::class);
    $this->assertDatabaseCount('tenants', 1);
    $this->assertDatabaseMissing('tenants', ['name' => 'Failed workspace']);
    $this->assertDatabaseCount('tenant_users', 0);
    $this->assertDatabaseCount('model_has_roles', 0);
    expect(getPermissionsTeamId())->toBe($previousTenant->id);
    expect($user->relationLoaded('roles'))->toBeFalse();
});
