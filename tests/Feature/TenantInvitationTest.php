<?php

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\TenantRole;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Notification::fake();
});

afterEach(function () {
    DB::disableQueryLog();
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    tenancy()->end();
});

/**
 * @return array{User, Tenant}
 */
function createInvitationManager(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    setPermissionsTeamId($tenant->id);
    $user->assignRole($role);
    setPermissionsTeamId(null);

    return [$user, $tenant];
}

test('authorized users send normalized invitations with hashed tokens and no membership yet', function (string $actorRole, string $invitedRole) {
    $this->travelTo(now()->startOfSecond());
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager($actorRole);

    $this->actingAs($user)->post(route('tenant.invitations.store', ['tenant' => $tenant->id]), [
        'email' => ' Invitee@Example.COM ', 'role' => $invitedRole,
        'tenant_id' => 'forged-tenant', 'invited_by' => 999, 'token' => 'forged-token',
    ])->assertRedirect(route('tenant.members.index', ['tenant' => $tenant->id]));

    $invitation = TenantInvitation::query()->sole();
    expect($invitation->email)->toBe('invitee@example.com');
    expect($invitation->tenant_id)->toBe($tenant->id);
    expect($invitation->invited_by)->toBe($user->id);
    expect($invitation->role->value)->toBe($invitedRole);
    expect($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
    $this->assertDatabaseCount('tenant_users', 1);
    $this->assertDatabaseCount('users', 1);
    Notification::assertSentOnDemand(TenantInvitationNotification::class, function ($notification, array $channels, $notifiable) use ($invitation): bool {
        $token = basename(parse_url($notification->acceptUrl, PHP_URL_PATH));

        return $notifiable->routes['mail'] === 'invitee@example.com'
            && $channels === ['mail'] && strlen($token) === 64
            && $invitation->token === hash('sha256', $token) && $invitation->token !== $token;
    });
})->with([
    'owner invites member' => ['owner', 'member'],
    'owner invites admin' => ['owner', 'admin'],
    'admin invites member' => ['admin', 'member'],
]);

test('invitation creation enforces the role hierarchy', function (string $actorRole, string $invitedRole) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager($actorRole);

    $this->actingAs($user)->post(route('tenant.invitations.store', ['tenant' => $tenant->id]), [
        'email' => 'invitee@example.com', 'role' => $invitedRole,
    ])->assertForbidden();

    $this->assertDatabaseCount('tenant_invitations', 0);
    Notification::assertNothingSent();
})->with([
    'admin cannot invite admin' => ['admin', 'admin'],
    'member cannot invite' => ['member', 'member'],
]);

test('invitation creation validates its input', function (array $input, array $errors) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();

    $this->actingAs($user)->post(route('tenant.invitations.store', ['tenant' => $tenant->id]), $input)
        ->assertSessionHasErrors($errors);

    $this->assertDatabaseCount('tenant_invitations', 0);
    Notification::assertNothingSent();
})->with([
    'required fields' => [[], ['email' => 'The email field is required.', 'role' => 'The role field is required.']],
    'invalid email' => [['email' => 'invalid', 'role' => 'member'], ['email' => 'The email field must be a valid email address.']],
    'long email' => [['email' => str_repeat('a', 250).'@example.com', 'role' => 'member'], ['email' => 'The email field must not be greater than 255 characters.']],
    'invalid role' => [['email' => 'invitee@example.com', 'role' => 'super-admin'], ['role' => 'The selected role is invalid.']],
    'owner role' => [['email' => 'invitee@example.com', 'role' => 'owner'], ['role' => 'The selected role is invalid.']],
]);

test('existing active and inactive memberships cannot be invited again', function (bool $active) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $tenant->users()->attach($member, ['is_active' => $active]);

    $this->actingAs($user)->post(route('tenant.invitations.store', ['tenant' => $tenant->id]), [
        'email' => 'MEMBER@example.com', 'role' => 'member',
    ])->assertSessionHasErrors(['email' => 'That email already belongs to this workspace.']);

    $this->assertDatabaseCount('tenant_invitations', 0);
    Notification::assertNothingSent();
})->with([true, false]);

test('duplicate invitations must be resent or cancelled instead of silently replaced', function (bool $expired) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $invitation = TenantInvitation::factory()->for($tenant)->create([
        'invited_by' => $user->id, 'email' => 'invitee@example.com',
        'expires_at' => $expired ? now()->subDay() : now()->addDay(),
    ]);

    $this->actingAs($user)->post(route('tenant.invitations.store', ['tenant' => $tenant->id]), [
        'email' => 'INVITEE@example.com', 'role' => 'admin',
    ])->assertSessionHasErrors(['email' => 'An invitation already exists for this email. Resend or cancel it first.']);

    expect($invitation->fresh()->token)->toBe($invitation->token);
    $this->assertDatabaseCount('tenant_invitations', 1);
    Notification::assertNothingSent();
})->with([true, false]);

test('pending invitation data respects tenant boundaries and management permissions', function (string $role, bool $canManageAdmin) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager($role);
    $memberInvite = TenantInvitation::factory()->for($tenant)->expired()->create(['invited_by' => $user->id, 'email' => 'member@example.com']);
    $adminInvite = TenantInvitation::factory()->for($tenant)->create(['invited_by' => $user->id, 'email' => 'admin@example.com', 'role' => TenantRole::Admin]);
    TenantInvitation::factory()->create(['email' => 'private@example.com']);

    $this->actingAs($user)->get(route('tenant.members.index', ['tenant' => $tenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('inviteRoles', $role === 'owner' ? ['member', 'admin'] : ['member'])
        ->has('invitations.data', 2)
        ->where('invitations.data.0.id', $adminInvite->id)
        ->where('invitations.data.0.can_manage', $canManageAdmin)
        ->where('invitations.data.1.id', $memberInvite->id)
        ->where('invitations.data.1.expired', true)
        ->where('invitations.data.1.can_manage', true)
        ->missing('invitations.data.0.token')->missing('invitations.data.0.tenant_id'));
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', false],
]);

test('members receive no invitation list or invitation controls', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager('member');
    TenantInvitation::factory()->for($tenant)->create(['invited_by' => $user->id]);

    $this->actingAs($user)->get(route('tenant.members.index', ['tenant' => $tenant->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('inviteRoles', [])->where('invitations', null));
});

test('resending renews expired invitations and invalidates the previous link', function () {
    $this->travelTo(now()->startOfSecond());
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $token = str_repeat('a', 64);
    $recipient = User::factory()->create();
    $invitation = TenantInvitation::factory()->for($tenant)->withToken($token)->expired()->create([
        'invited_by' => $user->id, 'email' => $recipient->email,
    ]);

    $this->actingAs($user)->post(route('tenant.invitations.resend', ['tenant' => $tenant->id, 'invitation' => $invitation->id]))
        ->assertRedirect(route('tenant.members.index', ['tenant' => $tenant->id]));

    $renewed = $invitation->fresh();
    expect($renewed->token)->not->toBe($invitation->token);
    expect($renewed->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
    $this->assertDatabaseCount('tenant_invitations', 1);
    $this->actingAs($recipient)->get(route('invitations.show', ['token' => $token]))->assertNotFound();
    Notification::assertSentOnDemand(TenantInvitationNotification::class, fn ($notification): bool => hash('sha256', basename(parse_url($notification->acceptUrl, PHP_URL_PATH))) === $renewed->token);
});

test('cancelling an invitation invalidates its link without creating a membership', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $token = str_repeat('a', 64);
    $recipient = User::factory()->create();
    $invitation = TenantInvitation::factory()->for($tenant)->withToken($token)->create([
        'invited_by' => $user->id, 'email' => $recipient->email,
    ]);

    $this->actingAs($user)->delete(route('tenant.invitations.destroy', ['tenant' => $tenant->id, 'invitation' => $invitation->id]))
        ->assertRedirect(route('tenant.members.index', ['tenant' => $tenant->id]));

    $this->assertDatabaseMissing('tenant_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $recipient->id]);
    $this->actingAs($recipient)->get(route('invitations.show', ['token' => $token]))->assertNotFound();
    Notification::assertNothingSent();
});

test('resend and cancellation enforce the invitation role hierarchy', function (string $role, TenantRole $invitedRole, string $method, string $route) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager($role);
    $invitation = TenantInvitation::factory()->for($tenant)->create(['invited_by' => $user->id, 'role' => $invitedRole]);

    $this->actingAs($user)->{$method}(route($route, ['tenant' => $tenant->id, 'invitation' => $invitation->id]))->assertForbidden();

    $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id, 'token' => $invitation->token]);
    Notification::assertNothingSent();
})->with([
    'admin cannot resend admin' => ['admin', TenantRole::Admin, 'post', 'tenant.invitations.resend'],
    'admin cannot cancel admin' => ['admin', TenantRole::Admin, 'delete', 'tenant.invitations.destroy'],
    'member cannot resend' => ['member', TenantRole::Member, 'post', 'tenant.invitations.resend'],
    'member cannot cancel' => ['member', TenantRole::Member, 'delete', 'tenant.invitations.destroy'],
]);

test('invitation management cannot access another tenants invitations', function (string $method, string $route) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $invitation = TenantInvitation::factory()->create();

    $this->actingAs($user)->{$method}(route($route, ['tenant' => $tenant->id, 'invitation' => $invitation->id]))->assertNotFound();

    $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id, 'token' => $invitation->token]);
    Notification::assertNothingSent();
})->with([
    'resend' => ['post', 'tenant.invitations.resend'],
    'cancel' => ['delete', 'tenant.invitations.destroy'],
]);

test('invitation management requires an authenticated active member', function (bool $guest) {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $tenant->users()->updateExistingPivot($user->id, ['is_active' => false]);
    if (! $guest) {
        $this->actingAs($user);
    }

    $response = $this->post(route('tenant.invitations.store', ['tenant' => $tenant->id]), ['email' => 'invitee@example.com', 'role' => 'member']);

    if ($guest) {
        $response->assertRedirect(route('login'));
    } else {
        $response->assertNotFound();
    }
    $this->assertDatabaseCount('tenant_invitations', 0);
    Notification::assertNothingSent();
})->with([true, false]);

test('invitation creation requires a verified email address', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($user)->post(route('tenant.invitations.store', ['tenant' => $tenant->id]), ['email' => 'invitee@example.com', 'role' => 'member'])
        ->assertRedirect(route('verification.notice'));

    $this->assertDatabaseCount('tenant_invitations', 0);
    Notification::assertNothingSent();
});

test('acceptance creates only the invited membership and role while preserving other tenant grants', function (TenantRole $role) {
    $this->seed(TenantRolesSeeder::class);
    $recipient = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->hasAttached($recipient)->create();
    $token = str_repeat('a', 64);
    $invitation = TenantInvitation::factory()->for($tenant)->withToken($token)->create(['email' => $recipient->email, 'role' => $role]);
    setPermissionsTeamId($tenant->id);
    $recipient->assignRole('owner');
    $recipient->givePermissionTo('tenant.update');
    setPermissionsTeamId($otherTenant->id);
    $recipient->assignRole('owner');
    $recipient->givePermissionTo('members.invite');
    $this->actingAs($recipient);

    $this->get(route('invitations.show', ['token' => $token]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitations/show')
            ->where('invitation.email_matches', true)->where('invitation.expired', false));
    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $recipient->id]);

    $this->post(route('invitations.accept', ['token' => $token]), ['role' => 'owner', 'tenant_id' => $otherTenant->id])
        ->assertRedirect(route('tenant.dashboard', ['tenant' => $tenant->id]));

    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $recipient->id, 'is_active' => true]);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $otherTenant->id, 'user_id' => $recipient->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $recipient->id, 'role_id' => Role::findByName($role->value)->id,
    ]);
    $this->assertDatabaseMissing('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $recipient->id, 'role_id' => Role::findByName('owner')->id,
    ]);
    $this->assertDatabaseMissing('model_has_permissions', ['tenant_id' => $tenant->id, 'model_id' => $recipient->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $otherTenant->id, 'model_id' => $recipient->id, 'role_id' => Role::findByName('owner')->id,
    ]);
    $this->assertDatabaseHas('model_has_permissions', [
        'tenant_id' => $otherTenant->id, 'model_id' => $recipient->id, 'permission_id' => Permission::findByName('members.invite')->id,
    ]);
    $this->assertDatabaseMissing('tenant_invitations', ['id' => $invitation->id]);
    expect(getPermissionsTeamId())->toBe($otherTenant->id);
    $this->post(route('invitations.accept', ['token' => $token]))->assertNotFound();
    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertOk();
})->with([TenantRole::Member, TenantRole::Admin]);

test('a different account cannot accept an invitation', function () {
    $recipient = User::factory()->create();
    $token = str_repeat('a', 64);
    $invitation = TenantInvitation::factory()->withToken($token)->create(['email' => 'someone-else@example.com']);

    $this->actingAs($recipient)->get(route('invitations.show', ['token' => $token]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('invitation.email_matches', false));
    $this->post(route('invitations.accept', ['token' => $token]))->assertForbidden();

    $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseCount('tenant_users', 0);
    $this->assertDatabaseCount('model_has_roles', 0);
    expect(getPermissionsTeamId())->toBeNull();
});

test('expired invitations can be viewed but cannot be accepted', function () {
    $recipient = User::factory()->create();
    $token = str_repeat('a', 64);
    $invitation = TenantInvitation::factory()->withToken($token)->expired()->create(['email' => $recipient->email]);
    $this->travelTo($invitation->expires_at);

    $this->actingAs($recipient)->get(route('invitations.show', ['token' => $token]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('invitation.expired', true));
    $this->post(route('invitations.accept', ['token' => $token]))->assertGone();

    $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseCount('tenant_users', 0);
});

test('acceptance never changes an existing membership or grants ownership', function (bool $existingMember, bool $active, TenantRole $invitedRole) {
    $this->seed(TenantRolesSeeder::class);
    $recipient = User::factory()->create();
    $tenant = Tenant::factory()->create();
    if ($existingMember) {
        $tenant->users()->attach($recipient, ['is_active' => $active]);
        setPermissionsTeamId($tenant->id);
        $recipient->assignRole('owner');
        setPermissionsTeamId(null);
    }
    $token = str_repeat('a', 64);
    $invitation = TenantInvitation::factory()->for($tenant)->withToken($token)->create(['email' => $recipient->email, 'role' => $invitedRole]);

    $this->actingAs($recipient)->post(route('invitations.accept', ['token' => $token]))->assertForbidden();

    $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id]);
    if ($existingMember) {
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $recipient->id, 'is_active' => $active]);
        $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $recipient->id, 'role_id' => Role::findByName('owner')->id]);
    } else {
        $this->assertDatabaseCount('tenant_users', 0);
        $this->assertDatabaseCount('model_has_roles', 0);
    }
})->with([
    'active membership' => [true, true, TenantRole::Member],
    'inactive membership' => [true, false, TenantRole::Member],
    'ownership invitation' => [false, false, TenantRole::Owner],
]);

test('unknown invitation tokens cannot be viewed or accepted', function (string $method, string $route) {
    $recipient = User::factory()->create();

    $this->actingAs($recipient)->{$method}(route($route, ['token' => str_repeat('a', 64)]))->assertNotFound();

    $this->assertDatabaseCount('tenant_users', 0);
})->with([
    'view' => ['get', 'invitations.show'],
    'accept' => ['post', 'invitations.accept'],
]);

test('signed out recipients return to the invitation after login', function () {
    $recipient = User::factory()->create();
    $token = str_repeat('a', 64);
    TenantInvitation::factory()->withToken($token)->create(['email' => $recipient->email]);
    $url = route('invitations.show', ['token' => $token]);

    $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
    $this->post(route('login.store'), ['email' => $recipient->email, 'password' => 'password'])->assertRedirect($url);
});

test('new recipients return to the invitation after registration and email verification', function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
    $token = str_repeat('a', 64);
    $invitation = TenantInvitation::factory()->withToken($token)->create(['email' => 'new@example.com']);
    $url = route('invitations.show', ['token' => $token]);

    $this->get($url)->assertRedirect(route('login'));
    $this->post(route('register.store'), [
        'name' => 'New Member', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect($url);
    $this->get($url)->assertRedirect(route('verification.notice'));
    $recipient = User::query()->where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $recipient->id, 'hash' => sha1($recipient->email),
    ]);
    $this->get($verificationUrl)->assertRedirect($url);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('invitations/show'));

    $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $invitation->tenant_id, 'user_id' => $recipient->id]);
    $this->assertDatabaseCount('tenants', 1);
});

test('unverified recipients cannot accept invitations', function () {
    $recipient = User::factory()->unverified()->create();
    $token = str_repeat('a', 64);
    $invitation = TenantInvitation::factory()->withToken($token)->create(['email' => $recipient->email]);

    $this->actingAs($recipient)->post(route('invitations.accept', ['token' => $token]))->assertRedirect(route('verification.notice'));

    $this->assertDatabaseHas('tenant_invitations', ['id' => $invitation->id]);
    $this->assertDatabaseCount('tenant_users', 0);
});

test('invitation email escapes workspace names and includes the acceptance link', function () {
    $url = route('invitations.show', ['token' => str_repeat('a', 64)]);
    $notification = new TenantInvitationNotification('<script>alert("xss")</script>', TenantRole::Member, $url);
    $message = $notification->toMail(new AnonymousNotifiable);
    $html = (string) $message->render();

    expect($message->actionUrl)->toBe($url);
    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>alert');
});

test('invitations are paginated independently from members', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    TenantInvitation::factory()->for($tenant)->count(12)->create(['invited_by' => $user->id]);

    $this->actingAs($user)->get(route('tenant.members.index', ['tenant' => $tenant->id, 'invitations_page' => 2]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('invitations.current_page', 2)->where('invitations.total', 12)
        ->has('invitations.data', 2)->where('members.current_page', 1)->has('members.data', 1));
});

test('resend cannot bypass an existing inactive membership', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $recipient = User::factory()->create();
    $invitation = TenantInvitation::factory()->for($tenant)->create(['invited_by' => $user->id, 'email' => $recipient->email]);
    $tenant->users()->attach($recipient, ['is_active' => false]);

    $this->actingAs($user)->post(route('tenant.invitations.resend', ['tenant' => $tenant->id, 'invitation' => $invitation->id]))
        ->assertSessionHasErrors(['email' => 'That email already belongs to this workspace.']);

    expect($invitation->fresh()->token)->toBe($invitation->token);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $recipient->id, 'is_active' => false]);
    Notification::assertNothingSent();
});

test('listing more invitations does not add queries for each invitation', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    TenantInvitation::factory()->for($tenant)->create(['invited_by' => $user->id]);
    $url = route('tenant.members.index', ['tenant' => $tenant->id]);
    $this->actingAs($user)->get($url)->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get($url)->assertOk();
    $initialQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    TenantInvitation::factory()->for($tenant)->count(8)->create(['invited_by' => $user->id]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('invitations.data', 9));
    $expandedQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($initialQueryCount)->toBeGreaterThan(0);
    expect($expandedQueryCount)->toBe($initialQueryCount);
});

test('invitation sending is rate limited', function () {
    $this->seed(TenantRolesSeeder::class);
    [$user, $tenant] = createInvitationManager();
    $this->actingAs($user);
    $url = route('tenant.invitations.store', ['tenant' => $tenant->id]);

    for ($index = 0; $index < 6; $index++) {
        $this->post($url, ['email' => "invitee{$index}@example.com", 'role' => 'member'])->assertRedirect();
    }
    $this->post($url, ['email' => 'limited@example.com', 'role' => 'member'])->assertTooManyRequests();

    $this->assertDatabaseCount('tenant_invitations', 6);
    Notification::assertSentOnDemandTimes(TenantInvitationNotification::class, 6);
});
