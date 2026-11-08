<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TenantSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $tenant = $this->currentTenant($request);
        Gate::authorize('view', $tenant);

        return Inertia::render('tenant/settings', [
            'canUpdate' => Gate::allows('update', $tenant),
            'canLeave' => Gate::allows('leave', $tenant),
            'canDelete' => Gate::allows('delete', $tenant),
            'canTransferOwnership' => Gate::allows('transferOwnership', $tenant),
        ]);
    }

    public function update(UpdateTenantRequest $request): RedirectResponse
    {
        $tenant = $this->currentTenant($request);

        DB::transaction(function () use ($request, $tenant): void {
            $tenant->refreshForUpdate();
            Gate::authorize('update', $tenant);

            $tenant->update(['name' => $request->validated('name')]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace updated.')]);

        return to_route('tenant.settings.edit', ['tenant' => $tenant->getKey()]);
    }

    private function currentTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('currentTenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
