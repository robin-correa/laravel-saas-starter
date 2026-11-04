<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\CompleteTenantOnboarding;
use App\Http\Requests\StoreTenantRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class TenantOnboardingController extends Controller
{
    public function create(Request $request): InertiaResponse|Response
    {
        if ($tenant = $request->user()->firstActiveTenant()) {
            return Inertia::location(route('tenant.dashboard', ['tenant' => $tenant->getKey()]));
        }

        return Inertia::render('onboarding/create');
    }

    public function store(StoreTenantRequest $request, CompleteTenantOnboarding $onboarding): Response
    {
        $tenant = $onboarding->handle($request->user(), $request->string('name')->toString());

        return Inertia::location(route('tenant.dashboard', ['tenant' => $tenant->getKey()]));
    }
}
