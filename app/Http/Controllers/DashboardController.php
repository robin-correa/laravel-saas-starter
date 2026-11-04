<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if ($tenant = $request->user()->firstActiveTenant()) {
            return Inertia::location(route('tenant.dashboard', ['tenant' => $tenant->getKey()]));
        }

        return to_route('onboarding.create');
    }
}
