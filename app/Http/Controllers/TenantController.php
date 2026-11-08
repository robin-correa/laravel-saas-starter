<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateTenant;
use App\Http\Requests\StoreTenantRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('workspaces/create');
    }

    public function store(StoreTenantRequest $request, CreateTenant $createTenant): RedirectResponse
    {
        $tenant = $createTenant->handle($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace created.')]);

        return to_route('tenant.dashboard', ['tenant' => $tenant->getKey()]);
    }
}
