<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantOnboardingController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('onboarding', [TenantOnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('onboarding', [TenantOnboardingController::class, 'store'])->name('onboarding.store');
});

require __DIR__.'/settings.php';
