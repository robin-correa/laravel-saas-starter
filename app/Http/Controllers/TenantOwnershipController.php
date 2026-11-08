<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\TransferTenantOwnership;
use App\Http\Requests\TransferTenantOwnershipRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TenantOwnershipController extends Controller
{
    public function update(TransferTenantOwnershipRequest $request, string $member, TransferTenantOwnership $transferOwnership): RedirectResponse
    {
        $tenant = $request->attributes->get('currentTenant');
        abort_unless($tenant instanceof Tenant, 404);
        $transferOwnership->handle($request->user(), $tenant, $member);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ownership transferred. You are now an admin.')]);

        return to_route('tenant.members.index', ['tenant' => $tenant->getKey()]);
    }
}
