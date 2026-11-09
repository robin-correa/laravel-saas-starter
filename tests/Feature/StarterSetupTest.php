<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
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

test('configured installation database commands seed required roles without demo data', function (string $script) {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($composer['scripts'][$script] as $command) {
        if (str_starts_with($command, '@php artisan migrate ') || str_starts_with($command, '@php artisan db:seed ')) {
            $this->artisan(substr($command, strlen('@php artisan ')))->assertSuccessful();
        }
    }

    $this->assertDatabaseCount('roles', 3);
    $this->assertDatabaseCount('permissions', 6);
    $this->assertDatabaseCount('role_has_permissions', 13);
    $this->assertDatabaseHas('roles', ['name' => 'owner', 'tenant_id' => null, 'guard_name' => 'web']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('tenants', 0);
    $this->assertDatabaseCount('model_has_roles', 0);
    Notification::assertNothingSent();
})->with(['setup', 'post-create-project-cmd']);

test('repeated default seeding preserves users memberships custom roles and permission context', function () {
    $this->seed();
    $user = User::factory()->create();
    $tenant = Tenant::factory()->hasAttached($user)->create();
    $customRole = Role::create(['name' => 'custom-viewer', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $customRole->givePermissionTo('tenant.view');
    setPermissionsTeamId($tenant->id);
    $user->assignRole($customRole);
    $user->givePermissionTo('tenant.update');

    $this->seed();
    $this->seed();

    $this->assertDatabaseCount('roles', 4);
    $this->assertDatabaseCount('permissions', 6);
    $this->assertDatabaseCount('role_has_permissions', 14);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('tenants', 1);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'is_active' => true]);
    $this->assertDatabaseHas('model_has_roles', ['tenant_id' => $tenant->id, 'model_id' => $user->id, 'role_id' => $customRole->id]);
    $this->assertDatabaseCount('model_has_permissions', 1);
    expect(getPermissionsTeamId())->toBe($tenant->id);
    expect($user->hasRole('custom-viewer'))->toBeTrue();
    expect($user->can('tenant.update'))->toBeTrue();
    expect($customRole->fresh()->getPermissionNames()->all())->toBe(['tenant.view']);
});

test('default database setup supports registration verification and first workspace creation', function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
    $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    $this->assertDatabaseCount('users', 0);

    $this->post(route('register.store'), [
        'name' => 'First Owner', 'email' => 'owner@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);
    $this->get($verificationUrl)->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('onboarding.create'));

    $response = $this->post(route('onboarding.store'), ['name' => 'First workspace']);

    $tenant = Tenant::query()->sole();
    $response->assertRedirect(route('tenant.dashboard', ['tenant' => $tenant->id]));
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'is_active' => true]);
    $this->assertDatabaseHas('model_has_roles', [
        'tenant_id' => $tenant->id, 'model_id' => $user->id,
        'model_type' => $user->getMorphClass(), 'role_id' => Role::findByName('owner')->id,
    ]);
    $this->get(route('tenant.dashboard', ['tenant' => $tenant->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenancy.current', ['id' => $tenant->id, 'name' => 'First workspace']));
});

test('optional demo seeding is repeatable and preserves an existing account password and profile', function () {
    $this->seed(DemoUserSeeder::class);
    $user = User::query()->sole();

    expect($user->email)->toBe('owner@example.com');
    expect(Hash::check('password', $user->password))->toBeTrue();
    expect($user->hasVerifiedEmail())->toBeTrue();

    $user->forceFill(['name' => 'Existing user', 'password' => 'private-password', 'email_verified_at' => null])->save();
    $this->seed(DemoUserSeeder::class);

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Existing user', 'email_verified_at' => null]);
    expect(Hash::check('private-password', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('tenants', 0);
    $this->assertDatabaseCount('model_has_roles', 0);
});

test('optional demo seeding does not create accounts outside local and test environments', function (string $environment) {
    $this->app->detectEnvironment(fn (): string => $environment);

    $this->artisan('db:seed', ['--class' => DemoUserSeeder::class, '--force' => true, '--no-interaction' => true])->assertSuccessful();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('tenants', 0);
})->with(['production', 'staging']);
