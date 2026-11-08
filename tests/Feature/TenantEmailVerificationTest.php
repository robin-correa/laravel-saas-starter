<?php

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Notification::fake();
});

afterEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->end();
});

/**
 * @return array{User, User, Tenant, TenantInvitation}
 */
function createTenantVerificationFixture(): array
{
    $user = User::factory()->unverified()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->hasAttached($member)->create(['name' => 'Original workspace']);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('owner');
    $member->assignRole('member');
    setPermissionsTeamId(null);
    $invitation = TenantInvitation::factory()->for($tenant)->create(['invited_by' => $user->id]);

    return [$user, $member, $tenant, $invitation];
}

test('every tenant endpoint rejects unverified members before returning tenant pages or changing data', function (string $method, string $routeName) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant, $invitation] = createTenantVerificationFixture();
    $parameters = ['tenant' => $tenant->id] + match ($routeName) {
        'tenant.members.update', 'tenant.members.destroy', 'tenant.ownership.update' => ['member' => $member->id],
        'tenant.invitations.resend', 'tenant.invitations.destroy' => ['invitation' => $invitation->id],
        default => [],
    };

    $this->actingAs($user);
    $url = route($routeName, $parameters);
    $response = $method === 'get' ? $this->get($url) : $this->{$method}($url, [
        'name' => 'Changed workspace', 'role' => 'admin', 'email' => 'new-invite@example.com',
        'password' => 'password', 'confirmation' => 'DELETE',
    ]);
    $response->assertRedirect(route('verification.notice'));

    $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Original workspace']);
    $this->assertDatabaseCount('tenant_users', 2);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => Role::findByName('owner')->id,
    ]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('member')->id,
    ]);
    $this->assertDatabaseCount('tenant_invitations', 1);
    $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id, 'token' => $invitation->token]);
    Notification::assertNothingSent();
    expect(getPermissionsTeamId())->toBeNull();
})->with([
    'dashboard' => ['get', 'tenant.dashboard'],
    'members' => ['get', 'tenant.members.index'],
    'change member role' => ['patch', 'tenant.members.update'],
    'remove member' => ['delete', 'tenant.members.destroy'],
    'workspace settings' => ['get', 'tenant.settings.edit'],
    'rename workspace' => ['patch', 'tenant.settings.update'],
    'leave workspace' => ['delete', 'tenant.membership.destroy'],
    'transfer ownership' => ['put', 'tenant.ownership.update'],
    'delete workspace' => ['delete', 'tenant.destroy'],
    'send invitation' => ['post', 'tenant.invitations.store'],
    'resend invitation' => ['post', 'tenant.invitations.resend'],
    'cancel invitation' => ['delete', 'tenant.invitations.destroy'],
]);

test('changing a profile email blocks direct tenant access until the new email is verified', function () {
    $this->seed(TenantRolesSeeder::class);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole('member');
    setPermissionsTeamId(null);
    $url = route('tenant.dashboard', ['tenant' => $tenant->id]);
    $this->actingAs($user)->get($url)->assertOk();

    $this->patch(route('profile.update'), ['name' => $user->name, 'email' => 'updated@example.com'])
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->email_verified_at)->toBeNull();
    $this->get($url)->assertRedirect(route('verification.notice'))->assertSessionHas('url.intended', $url);
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1('updated@example.com'),
    ]);
    $this->get($verificationUrl)->assertRedirect($url);
    $this->get($url)->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'is_active' => true]);
});

test('unverified JSON requests receive a forbidden response without changing membership roles', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $member, $tenant] = createTenantVerificationFixture();

    $this->actingAs($user)->patchJson(route('tenant.members.update', ['tenant' => $tenant->id, 'member' => $member->id]), ['role' => 'admin'])
        ->assertForbidden()->assertJsonPath('message', 'Your email address is not verified.');

    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $member->id, 'role_id' => Role::findByName('member')->id,
    ]);
    expect(getPermissionsTeamId())->toBeNull();
});

test('unverified inactive members and outsiders still receive 404 for tenant URLs', function (bool $isMember) {
    $user = User::factory()->unverified()->create();
    $tenant = Tenant::factory()->create();
    if ($isMember) {
        $tenant->users()->attach($user, ['is_active' => false]);
    }

    $this->actingAs($user)->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertNotFound();
})->with([true, false]);
