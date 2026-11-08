<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\LeaveTenant;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TenantMembershipController extends Controller
{
    public function destroy(Request $request, LeaveTenant $leaveTenant): RedirectResponse
    {
        $tenant = $request->attributes->get('currentTenant');
        abort_unless($tenant instanceof Tenant, 404);
        $leaveTenant->handle($request->user(), $tenant);
        tenancy()->end();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the workspace.')]);

        return to_route('dashboard');
    }
}
