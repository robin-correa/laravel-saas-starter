<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\CancelTenantInvitation;
use App\Actions\Tenancy\SendTenantInvitation;
use App\Http\Requests\StoreTenantInvitationRequest;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TenantInvitationController extends Controller
{
    public function store(StoreTenantInvitationRequest $request, SendTenantInvitation $sendInvitation): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $sendInvitation->handle($request->user(), $tenant, new TenantInvitation($request->safe()->only(['email', 'role'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('tenant.members.index', ['tenant' => $tenant->getKey()]);
    }

    public function resend(Request $request, string $invitation, SendTenantInvitation $sendInvitation): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $invitation = $tenant->invitations()->whereKey($invitation)->firstOrFail();
        $sendInvitation->handle($request->user(), $tenant, $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent. The previous link is no longer valid.')]);

        return to_route('tenant.members.index', ['tenant' => $tenant->getKey()]);
    }

    public function destroy(Request $request, string $invitation, CancelTenantInvitation $cancelInvitation): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $cancelInvitation->handle($request->user(), $tenant, $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('tenant.members.index', ['tenant' => $tenant->getKey()]);
    }

    private function currentTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('currentTenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
