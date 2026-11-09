# Architecture

This document describes the merged starter. The [ADRs](adr/README.md) record the
decisions behind it; the [development guide](development.md) covers setup and
verification.

## Accounts and workspaces

A `User` is a global account. A `Tenant` is a workspace. Creating an account does
not create a workspace automatically; after email verification, onboarding
collects a workspace name and makes its creator the owner. The same account can
create or join other workspaces with different roles in each.

The meaning of a workspace is chosen by the product. For example, one tenant can
represent a doctor's practice or a home-services company. A business account
can contain many individual user logins; it is not the same as a `User` record.
See [business architecture models](business-models.md) for product options and
the distinction between implemented behavior and future restrictions or billing.

All application data is stored in one MySQL database:

| Table                                          | Responsibility                                                                                                          |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `users`                                        | Global identity, credentials, and email verification state.                                                             |
| `tenants`                                      | Workspace identity and name. `id` is a VARCHAR holding a generated UUID; Stancl's JSON `data` column remains available. |
| `tenant_users`                                 | Membership, unique by tenant/user, with an `is_active` flag and timestamps.                                             |
| `roles`, `permissions`, `role_has_permissions` | Reusable role definitions and their permission sets.                                                                    |
| `model_has_roles`, `model_has_permissions`     | Grants scoped to a non-null `tenant_id`.                                                                                |
| `tenant_invitations`                           | Pending email invitations, role, hashed token, inviter, and expiry.                                                     |

The app extends Stancl's base tenant in [app/Models/Tenant.php](../app/Models/Tenant.php)
to add relationships, factories, and the explicit `name` column. It does not
implement per-tenant databases. User relationships live in
[app/Models/User.php](../app/Models/User.php).

## Request and navigation flow

Central routes handle authentication, profile/security settings, onboarding,
workspace creation, and invitation acceptance. The central `/dashboard` sends a
verified user to their first active workspace, or to onboarding if none exists.

[routes/tenant.php](../routes/tenant.php) groups tenant routes under `/{tenant}`
with the name prefix `tenant.`. A tenant request passes these boundaries:

1. `InitializeTenancyByPath` resolves the UUID from the path and initializes
   Stancl's tenant context. An unknown tenant returns 404.
2. Authentication and `EnsureActiveTenantMembership` require a signed-in account
   with an active membership. Nonmembers and inactive memberships receive 404.
3. `SetTenantPermissions` selects the tenant ID as Spatie's current team and
   clears loaded role/permission relations. It restores the prior team context
   and clears those relations in `finally` after the request.
4. `verified` requires the account's current email to be verified. Browser
   requests redirect to verification; JSON requests receive 403.
5. Controllers, Form Requests, and `TenantPolicy` authorize the specific action.
   An active member without permission receives 403.

These are the logical checks; Laravel's middleware priority also controls their
execution. Membership is checked before email verification on tenant routes.
Changing the URL identifies a workspace but does not grant access to it.

The URL is the current workspace selection. There is no session-wide selected
tenant: separate tabs can use different workspaces. The switcher navigates to the
selected tenant's dashboard using generated Wayfinder routes.

[HandleInertiaRequests](../app/Http/Middleware/HandleInertiaRequests.php) shares:

- `tenancy.current`: the authorized request's workspace ID and name, or null on
  central pages.
- `tenancy.available`: workspaces with active membership and `tenant.view`
  access, returned by
  [GetAccessibleTenants](../app/Actions/Tenancy/GetAccessibleTenants.php).

Workspace discovery checks grants in SQL rather than changing permission context
for each workspace. Inertia props and hidden controls support the UI; server
policies remain the authorization boundary.

## Authorization

[TenantRole](../app/TenantRole.php) and [TenantPermission](../app/TenantPermission.php)
define the defaults. `TenantRolesSeeder` creates three shared role rows with
`roles.tenant_id = null`. This means the definitions can be reused, not that
every user receives them. Assignments in `model_has_roles` carry the workspace
ID. The schema also allows tenant-specific role definitions, but there is no
custom-role management UI.

| Permission            | Owner | Admin | Member |
| --------------------- | ----- | ----- | ------ |
| `tenant.view`         | Yes   | Yes   | Yes    |
| `tenant.update`       | Yes   | Yes   | No     |
| `members.view`        | Yes   | Yes   | Yes    |
| `members.invite`      | Yes   | Yes   | No     |
| `members.update-role` | Yes   | No    | No     |
| `members.remove`      | Yes   | Yes   | No     |

[TenantPolicy](../app/Policies/TenantPolicy.php) adds role hierarchy rules:

- Owners can invite admins or members; admins can invite members.
- Only owners can change member/admin roles. Normal role changes and invitations
  cannot assign owner.
- Owners can remove admins or members. Admins can remove members. Owners cannot
  be removed or demoted through ordinary member management.
- Ownership transfer and workspace deletion require the owner role. An owner
  cannot leave a workspace before transferring ownership.

There is no platform-wide superadmin or authorization bypass.

## Invitations and lifecycle

Invitation email is normalized and tied to one workspace. Sending an invitation
does not create a user or membership. The mail link carries a random token;
only its SHA-256 hash is stored. Invitations expire after seven days. Resending
replaces the token; cancellation and acceptance delete the pending invitation.

The acceptance route is central so a recipient can reach it before joining a
workspace. It requires authentication and email verification. GET displays a
preview; POST verifies the token, expiry, email match, and absence of an existing
membership, then atomically attaches an active membership, assigns the invited
role, clears old grants for that workspace, and consumes the invitation. Both
active and inactive existing memberships prevent acceptance.

Workspace creation, invitation acceptance, member updates, and lifecycle actions
use database transactions; actions that can race lock the relevant records.
Ownership transfer promotes another active member to owner and makes the
previous owner an admin in that workspace only. The UI requires the current
owner's password. Invite someone as admin/member and let them accept before
transferring ownership.

Leaving removes the selected membership and its grants, preserving the account
and other workspaces. Workspace deletion requires a password and `DELETE`
confirmation. Foreign keys remove its memberships, invitations, grants, and
tenant-specific role records while shared role definitions and users remain.
There is currently no explicit tenant filesystem or external-resource cleanup.

Account deletion checks ownership across all workspaces, independently of the
current permission team, and refuses deletion while any owned workspace remains.
See [DeleteUserAccount](../app/Actions/Fortify/DeleteUserAccount.php) and
[ADR 0003](adr/0003-explicit-workspace-lifecycle.md).

## Tenancy beyond SQL

In [config/tenancy.php](../config/tenancy.php), database, cache, and Redis tenancy
bootstrappers are disabled. Initializing tenancy does not automatically add a
tenant filter to arbitrary models or cache keys.

Filesystem and queue tenancy bootstrappers remain enabled. The local/public
disks and storage path are tenant-scoped while tenancy is initialized.
`asset_helper_tenancy = false` keeps shared application assets, including Vite
CSS and JavaScript, on the normal asset URLs.

Queue tenancy restores Stancl's tenant context for tenant-dispatched jobs. It
does not run `SetTenantPermissions`; jobs that authorize users need an explicit
Spatie team context as well. See the [extension guidance](development.md#extending-the-starter).

## Code and regression coverage

| Concern                       | Implementation                                                                                                                                       | Feature tests                                                                                                                                                                                            |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Setup and role seeding        | [Seeders](../database/seeders/DatabaseSeeder.php), [Composer scripts](../composer.json)                                                              | [StarterSetupTest](../tests/Feature/StarterSetupTest.php)                                                                                                                                                |
| Identification and membership | [Tenant routes](../routes/tenant.php), [membership middleware](../app/Http/Middleware/EnsureActiveTenantMembership.php)                              | [TenancyTest](../tests/Feature/TenancyTest.php), [TenantMembershipTest](../tests/Feature/TenantMembershipTest.php)                                                                                       |
| Role and permission context   | [SetTenantPermissions](../app/Http/Middleware/SetTenantPermissions.php), [TenantPolicy](../app/Policies/TenantPolicy.php)                            | [TenantRolesTest](../tests/Feature/TenantRolesTest.php), [TenantPolicyTest](../tests/Feature/TenantPolicyTest.php)                                                                                       |
| Onboarding and switching      | [CreateTenant](../app/Actions/Tenancy/CreateTenant.php), [GetAccessibleTenants](../app/Actions/Tenancy/GetAccessibleTenants.php)                     | [TenantOnboardingTest](../tests/Feature/TenantOnboardingTest.php), [TenantSwitcherTest](../tests/Feature/TenantSwitcherTest.php)                                                                         |
| Invitations and members       | [SendTenantInvitation](../app/Actions/Tenancy/SendTenantInvitation.php), [AcceptTenantInvitation](../app/Actions/Tenancy/AcceptTenantInvitation.php) | [TenantInvitationTest](../tests/Feature/TenantInvitationTest.php), [TenantMemberControllerTest](../tests/Feature/TenantMemberControllerTest.php)                                                         |
| Settings and lifecycle        | [Tenancy actions](../app/Actions/Tenancy), [account deletion](../app/Actions/Fortify/DeleteUserAccount.php)                                          | [TenantSettingsTest](../tests/Feature/TenantSettingsTest.php), [TenantLifecycleTest](../tests/Feature/TenantLifecycleTest.php), [AccountDeletionTest](../tests/Feature/Settings/AccountDeletionTest.php) |
| Email verification boundary   | [Tenant routes](../routes/tenant.php), [profile settings](../routes/settings.php)                                                                    | [TenantEmailVerificationTest](../tests/Feature/TenantEmailVerificationTest.php)                                                                                                                          |
