<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    tenancy()->end();
});

test('tenant routes identify the tenant from each request path', function () {
    $user = User::factory()->create();
    $firstTenant = Tenant::factory()->hasAttached($user)->create();
    $secondTenant = Tenant::factory()->hasAttached($user)->create();
    $this->actingAs($user);

    $this->get(route('tenant.dashboard', ['tenant' => $firstTenant->id]))
        ->assertOk()
        ->assertContent('This is your multi-tenant application. The id of the current tenant is '.$firstTenant->id);

    $this->get(route('tenant.dashboard', ['tenant' => $secondTenant->id]))
        ->assertOk()
        ->assertContent('This is your multi-tenant application. The id of the current tenant is '.$secondTenant->id);
});

test('tenant routes return 404 when the tenant ID does not exist', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('tenant.dashboard', ['tenant' => 'missing-tenant']));

    $response->assertNotFound();
});

test('deleting a tenant leaves the shared database available', function () {
    $tenant = Tenant::factory()->create();
    $databaseName = DB::connection()->getDatabaseName();

    $tenant->delete();

    $this->assertModelMissing($tenant);
    expect(DB::connection()->getDatabaseName())->toBe($databaseName);
});

test('tenants share the central database connection', function () {
    $tenant = Tenant::factory()->create();
    $centralConnection = DB::getDefaultConnection();
    $databaseName = DB::connection()->getDatabaseName();

    tenancy()->initialize($tenant);

    expect(DB::getDefaultConnection())->toBe($centralConnection);
    expect(DB::connection()->getDatabaseName())->toBe($databaseName);
});

test('tenants can use the database cache store', function () {
    config(['cache.default' => 'database']);
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);

    Cache::put('greeting', 'hello');

    expect(Cache::get('greeting'))->toBe('hello');
});
