<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\CompleteTenantOnboarding;
use App\Http\Requests\StoreTenantRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TenantOnboardingController extends Controller
{
    public function create(Request $request): InertiaResponse|RedirectResponse
    {
        if ($tenant = $request->user()->firstActiveTenant()) {
            return to_route('tenant.dashboard', ['tenant' => $tenant->getKey()]);
        }

        return Inertia::render('onboarding/create');
    }

    public function store(StoreTenantRequest $request, CompleteTenantOnboarding $onboarding): RedirectResponse
    {
        $tenant = $onboarding->handle($request->user(), $request->string('name')->toString());

        return to_route('tenant.dashboard', ['tenant' => $tenant->getKey()]);
    }
}
