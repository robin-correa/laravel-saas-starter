# 0002: Workspace membership and scoped role assignments

- Status: Accepted
- Recorded: 2026-11-09

## Context

A global user can own one workspace, administer another, and be a member of a
third. Identifying the tenant does not establish membership or authorize an
action. Role state must not leak across workspace switches or requests.

## Decision

Store membership in `tenant_users` with a unique tenant/user pair and an active
flag. Tenant routes require active membership and a verified email.

Use Spatie Permission 8's teams support with `tenant_id` as the team key. Seed
shared owner, admin, and member definitions with `roles.tenant_id = null`, while
every role or direct-permission assignment carries the workspace's ID. Define
default permissions in application enums and seed them during installation.

[SetTenantPermissions](../../app/Http/Middleware/SetTenantPermissions.php) sets
the permission team for each tenant request, clears cached user relations, and
restores the previous context in `finally`. Actions outside that middleware must
manage their own permission context.

[TenantPolicy](../../app/Policies/TenantPolicy.php) combines active membership,
permissions, and role hierarchy rules. Sensitive lifecycle operations explicitly
require the owner role. See the [permission matrix](../architecture.md#authorization).

## Consequences

- Three shared role definitions are expected even before anyone registers.
  Users receive assignments only when creating or joining a workspace.
- A grant in one workspace conveys no authority in another. Membership without
  the required permission also conveys no authority for that action.
- Nonmembers receive 404 on tenant routes; authorized members attempting a
  forbidden action receive 403. Browser verification redirects apply after
  membership checks.
- Policies enforce authorization even if a client bypasses hidden UI controls.
  Owner transfer cannot be performed through ordinary role editing.
- Jobs and other non-HTTP workflows need explicit team context when checking
  permissions. Shared role definitions do not create a platform superadmin.

We chose this over per-user or per-workspace copies of default roles to reuse
definitions while keeping grants scoped. A global role on the user would not
represent different authority across workspaces.

## References

- [Permission configuration](../../config/permission.php), [role enum](../../app/TenantRole.php), and [role seeder](../../database/seeders/TenantRolesSeeder.php).
- [Role tests](../../tests/Feature/TenantRolesTest.php), [policy tests](../../tests/Feature/TenantPolicyTest.php), and [email verification tests](../../tests/Feature/TenantEmailVerificationTest.php).
