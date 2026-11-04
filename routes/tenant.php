<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureActiveTenantMembership;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenancyServiceProvider and prefixed with
| /{tenant} to identify tenants by their ID in the URL.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    'auth',
    InitializeTenancyByPath::class,
    EnsureActiveTenantMembership::class,
])->prefix('{tenant}')->name('tenant.')->group(function () {
    Route::get('/dashboard', function (): string {
        return 'This is your multi-tenant application. The id of the current tenant is '.tenant('id');
    })->name('dashboard');
});
