<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateTenant;
use App\Actions\Tenancy\DeleteTenant;
use App\Http\Requests\DeleteTenantRequest;
use App\Http\Requests\StoreTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function destroy(DeleteTenantRequest $request, DeleteTenant $deleteTenant): RedirectResponse
    {
        $tenant = $request->attributes->get('currentTenant');
        abort_unless($tenant instanceof Tenant, 404);
        $deleteTenant->handle($request->user(), $tenant);
        tenancy()->end();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace deleted.')]);

        return to_route('dashboard');
    }

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
