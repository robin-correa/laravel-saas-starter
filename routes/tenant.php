<?php

declare(strict_types=1);

use App\Http\Controllers\TenantDashboardController;
use App\Http\Controllers\TenantInvitationController;
use App\Http\Controllers\TenantMemberController;
use App\Http\Controllers\TenantSettingsController;
use App\Http\Middleware\EnsureActiveTenantMembership;
use App\Http\Middleware\SetTenantPermissions;
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
    SetTenantPermissions::class,
])->prefix('{tenant}')->name('tenant.')->group(function () {
    Route::get('/dashboard', TenantDashboardController::class)->name('dashboard');
    Route::get('/settings', [TenantSettingsController::class, 'edit'])->middleware('verified')->name('settings.edit');
    Route::patch('/settings', [TenantSettingsController::class, 'update'])->middleware('verified')->name('settings.update');
    Route::get('/members', [TenantMemberController::class, 'index'])->name('members.index');
    Route::patch('/members/{member}', [TenantMemberController::class, 'update'])->whereNumber('member')->name('members.update');
    Route::delete('/members/{member}', [TenantMemberController::class, 'destroy'])->whereNumber('member')->name('members.destroy');
    Route::post('/invitations', [TenantInvitationController::class, 'store'])->middleware(['verified', 'throttle:6,1'])->name('invitations.store');
    Route::post('/invitations/{invitation}/resend', [TenantInvitationController::class, 'resend'])
        ->whereNumber('invitation')->middleware(['verified', 'throttle:6,1'])->name('invitations.resend');
    Route::delete('/invitations/{invitation}', [TenantInvitationController::class, 'destroy'])
        ->whereNumber('invitation')->middleware('verified')->name('invitations.destroy');
});
