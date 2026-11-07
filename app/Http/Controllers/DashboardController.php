<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($tenant = $request->user()->firstActiveTenant()) {
            return to_route('tenant.dashboard', ['tenant' => $tenant->getKey()]);
        }

        return to_route('onboarding.create');
    }
}
