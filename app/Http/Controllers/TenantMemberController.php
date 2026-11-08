<?php

namespace App\Http\Controllers;

use App\Actions\Tenancy\RemoveTenantMember;
use App\Actions\Tenancy\UpdateTenantMemberRole;
use App\Http\Requests\UpdateTenantMemberRequest;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\TenantPermission;
use App\TenantRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TenantMemberController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $this->currentTenant($request);
        Gate::authorize('viewMembers', $tenant);

        $user = $request->user();
        $isOwner = $user->hasRole(TenantRole::Owner);
        $canUpdateRoles = $isOwner && $user->can(TenantPermission::UpdateMemberRole->value);
        $canRemoveMembers = $user->can(TenantPermission::RemoveMembers->value);

        $members = $tenant->users()
            ->select(['users.id', 'users.name', 'users.email', 'tenant_users.is_active as membership_is_active'])
            ->with('roles:id,name')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate(20)
            ->through(function (User $member) use ($isOwner, $canUpdateRoles, $canRemoveMembers): array {
                $roles = $member->roles->pluck('name')->all();
                $isProtected = in_array(TenantRole::Owner->value, $roles, true);

                return [
                    'id' => $member->getKey(),
                    'name' => $member->name,
                    'email' => $member->email,
                    'is_active' => (bool) $member->getAttribute('membership_is_active'),
                    'roles' => $roles,
                    'can_update_role' => $canUpdateRoles && ! $isProtected,
                    'can_remove' => $canRemoveMembers && ! $isProtected
                        && ($isOwner || ! in_array(TenantRole::Admin->value, $roles, true)),
                ];
            });

        $inviteRoles = [];
        foreach ([TenantRole::Member, TenantRole::Admin] as $role) {
            if (Gate::allows('inviteMember', [$tenant, $role])) {
                $inviteRoles[] = $role->value;
            }
        }

        $invitations = $inviteRoles === [] ? null : $tenant->invitations()
            ->orderByDesc('id')
            ->paginate(10, ['id', 'email', 'role', 'expires_at'], 'invitations_page')
            ->through(fn (TenantInvitation $invitation): array => [
                'id' => $invitation->getKey(),
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toISOString(),
                'expired' => $invitation->isExpired(),
                'can_manage' => in_array($invitation->role->value, $inviteRoles, true),
            ]);

        return Inertia::render('tenant/members', [
            'members' => $members,
            'inviteRoles' => $inviteRoles,
            'invitations' => $invitations,
        ]);
    }

    public function update(UpdateTenantMemberRequest $request, string $member, UpdateTenantMemberRole $updateRole): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $updateRole->handle($request->user(), $tenant, $member, TenantRole::from($request->validated('role')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('tenant.members.index', ['tenant' => $tenant->getKey()]);
    }

    public function destroy(Request $request, string $member, RemoveTenantMember $removeMember): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $removeMember->handle($request->user(), $tenant, $member);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        if ($member === (string) $request->user()->getKey()) {
            return to_route('dashboard');
        }

        return to_route('tenant.members.index', ['tenant' => $tenant->getKey()]);
    }

    private function currentTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('currentTenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
