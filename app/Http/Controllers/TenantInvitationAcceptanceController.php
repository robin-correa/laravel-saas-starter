<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\AcceptTenantInvitation;
use App\Models\TenantInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TenantInvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = TenantInvitation::query()->with('tenant')->where('token', hash('sha256', $token))->firstOrFail();

        return Inertia::render('invitations/show', [
            'invitation' => [
                'workspace_name' => $invitation->tenant->name,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expired' => $invitation->isExpired(),
                'email_matches' => Str::lower($request->user()->email) === $invitation->email,
            ],
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token, AcceptTenantInvitation $acceptInvitation): RedirectResponse
    {
        $tenant = $acceptInvitation->handle($request->user(), $token);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You joined the workspace.')]);

        return to_route('tenant.dashboard', ['tenant' => $tenant->getKey()]);
    }
}
