# 0003: Explicit invitations and workspace lifecycle

- Status: Accepted
- Recorded: 2026-11-09

## Context

Accounts and workspaces have separate lifetimes. Emailing an invitation should
not silently create an account or grant access. Removing a member or deleting
their account should not unintentionally delete another person's workspace.
Owners need explicit ways to hand over or close a workspace.

## Decision

Onboarding and additional workspace creation atomically create an active
membership and owner assignment for the creator.

Invite users by normalized email with admin/member roles. Store a SHA-256 hash of
a random token and a seven-day expiry. Use a central acceptance route requiring
authentication and email verification. GET previews the invitation; POST checks
the recipient and consumes it while atomically creating membership and grants.
Resending rotates the token; cancellation removes it. Existing memberships,
including inactive ones, prevent rejoining through an invitation.

Keep ownership changes separate from invitations and ordinary role editing.
Transfer requires the current owner's password and another active member. It
promotes the recipient to owner and makes the former owner an admin in that
workspace. Owners must transfer before leaving.

Workspace deletion requires an owner, their password, and a `DELETE`
confirmation. Delete workspace database records through their foreign-key
relationships while preserving users, other workspaces, and shared roles.
Account deletion is blocked while the account owns any workspace, including
ownership outside the current request's tenant context.

## Consequences

- Unregistered invitees can register, verify their email, and then accept. The
  authenticated email must match the invitation; possessing a link is
  insufficient. Opening a link alone cannot grant membership.
- Expired invitations remain pending until resent or cancelled. There is one
  pending invitation per workspace/email, with no scheduled expiry cleanup.
- Leaving removes only that workspace's membership and grants. A new invitation
  is required to rejoin after leaving.
- Transactions and row locks protect multi-step membership and ownership changes.
  Invitation email is sent after the transaction; a failed delivery can be
  retried through resend.
- Database cleanup does not explicitly remove tenant files or external resources.
  Features adding those resources must define cleanup separately.

We chose explicit transfer and deletion flows over automatic workspace deletion
when an owner leaves or deletes their account. Invitation acceptance gives the
recipient an explicit choice before membership is created.

## References

- [Invitation creation](../../app/Actions/Tenancy/SendTenantInvitation.php) and [acceptance](../../app/Actions/Tenancy/AcceptTenantInvitation.php).
- [Ownership transfer](../../app/Actions/Tenancy/TransferTenantOwnership.php), [workspace deletion](../../app/Actions/Tenancy/DeleteTenant.php), and [account deletion](../../app/Actions/Fortify/DeleteUserAccount.php).
- [Invitation tests](../../tests/Feature/TenantInvitationTest.php), [lifecycle tests](../../tests/Feature/TenantLifecycleTest.php), and [account deletion tests](../../tests/Feature/Settings/AccountDeletionTest.php).
