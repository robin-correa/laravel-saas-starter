# 0001: Shared database and path identification

- Status: Accepted
- Recorded: 2026-11-09

## Context

The starter needs branded workspaces on one application domain. One account can
belong to several workspaces and switch between them. Initial setup should not
require customer domains, DNS changes, or provisioning a database per workspace.

## Decision

Use Stancl Tenancy 3 with one shared database. A workspace is the application's
`Tenant` model, identified by a generated UUID stored in the existing VARCHAR
primary key. Keep the `name` column separate from Stancl's JSON `data` column.

Group tenant routes under `/{tenant}` with `InitializeTenancyByPath` in
[routes/tenant.php](../../routes/tenant.php). The path determines the current
workspace on every request. Retain central routes for account-level workflows.
The switcher changes the URL instead of saving a session-wide tenant selection.

Disable database provisioning and the database, cache, and Redis tenancy
bootstrappers. Retain filesystem and queue bootstrapping. Keep
`asset_helper_tenancy = false` for shared application assets.

## Consequences

- Local setup and migrations use one database, without tenant-specific migration
  commands. Different browser tabs can address different workspaces.
- UUIDs appear in workspace URLs; workspace names can change without changing
  identity. UUIDs are identifiers, not access credentials.
- Shared-database isolation must be enforced through tenant-scoped queries,
  membership checks, policies, and regression tests. Path identification alone
  does not filter arbitrary application models.
- Domain identification and separate databases remain possible future changes,
  but would require revisiting routing, URL generation, and data isolation.

We chose this over domain/subdomain identification to avoid DNS and whitelabel
setup, and over database-per-tenant provisioning to keep the foundation small.

## References

- [Tenancy configuration](../../config/tenancy.php) and [service provider](../../app/Providers/TenancyServiceProvider.php).
- [Identification tests](../../tests/Feature/TenancyTest.php) and [membership tests](../../tests/Feature/TenantMembershipTest.php).
- [Stancl: single-database tenancy](https://tenancyforlaravel.com/docs/v3/single-database-tenancy/).
- [Stancl: tenant identification](https://tenancyforlaravel.com/docs/v3/tenant-identification/).
