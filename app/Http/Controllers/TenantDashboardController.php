<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TenantDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $tenant = $request->attributes->get('currentTenant');

        abort_unless($tenant instanceof Tenant, 404);
        Gate::authorize('view', $tenant);

        return Inertia::render('dashboard');
    }
}
