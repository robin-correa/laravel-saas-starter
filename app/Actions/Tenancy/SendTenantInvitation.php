<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendTenantInvitation
{
    public function handle(User $user, Tenant $tenant, TenantInvitation $invitation): void
    {
        [$invitation, $token] = DB::transaction(function () use ($user, $tenant, $invitation): array {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            if ($invitation->exists) {
                $invitation = $tenant->invitations()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            }

            $user->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($user)->authorize('inviteMember', [$tenant, $invitation->role]);

            if ($tenant->users()->whereRaw('LOWER(users.email) = ?', [$invitation->email])->exists()) {
                throw ValidationException::withMessages(['email' => __('That email already belongs to this workspace.')]);
            }

            if (! $invitation->exists && $tenant->invitations()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => __('An invitation already exists for this email. Resend or cancel it first.')]);
            }

            $token = Str::random(64);
            $invitation->forceFill([
                'tenant_id' => $tenant->getKey(),
                'invited_by' => $user->getKey(),
                'token' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ])->save();

            return [$invitation, $token];
        });

        Notification::route('mail', $invitation->email)->notify(new TenantInvitationNotification(
            $tenant->name ?: __('Untitled workspace'),
            $invitation->role,
            route('invitations.show', ['token' => $token]),
        ));
    }
}
