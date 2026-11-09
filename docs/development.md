# Development guide

Start with the [README](../README.md) for installation. Run application commands
through `vendor/bin/sail` so PHP, Node, Composer, and extensions match the Docker
environment.

## Everyday commands

| Task                                         | Command                                                               |
| -------------------------------------------- | --------------------------------------------------------------------- |
| Start services and wait for health checks    | `vendor/bin/sail up -d --wait`                                        |
| Check containers                             | `vendor/bin/sail ps`                                                  |
| Stop services, preserving database volumes   | `vendor/bin/sail down`                                                |
| Run frontend development server              | `vendor/bin/sail npm run dev`                                         |
| Build frontend assets                        | `vendor/bin/sail npm run build`                                       |
| Inspect application routes                   | `vendor/bin/sail artisan route:list --except-vendor --no-interaction` |
| Apply migrations and seed required roles     | `vendor/bin/sail artisan migrate --seed --no-interaction`             |
| Clear cached application configuration       | `vendor/bin/sail artisan optimize:clear --no-interaction`             |
| Watch Laravel logs                           | `vendor/bin/sail artisan pail --no-interaction`                       |
| Run a worker when developing queued features | `vendor/bin/sail artisan queue:work --no-interaction`                 |

Use ordinary `migrate` for this shared database. There is no separate tenant
database to provision or migrate. `migrate:fresh` and `sail down -v` remove data;
they are unnecessary for routine updates.

Wayfinder generates typed frontend route helpers during the Vite workflow. To
regenerate them explicitly:

```sh
vendor/bin/sail artisan wayfinder:generate --with-form --no-interaction
```

Generated files under `resources/js/actions`, `resources/js/routes`, and
`resources/js/wayfinder` are ignored by Git. Use these helpers in frontend links
and forms rather than writing route URLs by hand.

## Local mail

[.env.example](../.env.example) uses `MAIL_MAILER=log`. There is no Mailpit service
in this repository's Compose configuration. Watch Laravel logs, trigger a
verification, reset, or invitation email, and copy the complete link from the
message into your browser. Use the browser session for the intended recipient.

Invitation notifications are currently sent synchronously, after their database
transaction. A queue worker is unnecessary for the current invitation flow.
If you configure a real mail transport, set its environment values and clear
cached configuration before testing.

## Tests and checks

### Test database

[phpunit.xml](../phpunit.xml) selects a separate MySQL database named `testing`.
Connection credentials come from the application environment. Feature tests use
`RefreshDatabase`, which rebuilds the test database's schema. Keep this database
separate from the one containing your manual testing data.

Sail's MySQL initialization script creates and grants access to the `testing`
database when its volume is first initialized. If an existing volume predates
that setup, create a separate `testing` database and grant the Sail database user
access through your database administration tool. Initialization scripts do not
rerun for an existing volume; deleting the volume would also delete local data.

Check any `.env.testing` or shell overrides before running tests. They must point
to a disposable test database, never an application database.

### Commands

```sh
# One affected feature file
vendor/bin/sail artisan test --compact tests/Feature/TenantInvitationTest.php --no-interaction

# All PHP tests
vendor/bin/sail artisan test --compact --no-interaction

# Frontend formatting/linting and TypeScript
vendor/bin/sail npm run check
vendor/bin/sail npm run types:check

# PHP static analysis
vendor/bin/sail composer types:check

# Format changed PHP files
vendor/bin/sail bin pint --dirty --format agent

# Complete CI checks
vendor/bin/sail composer ci:check
```

`composer ci:check` runs frontend checks, TypeScript, PHP formatting checks,
PHPStan, and PHP tests. The [GitHub Actions workflow](../.github/workflows/tests.yml)
runs it on pull requests and pushes to `main`. It uses PHP 8.5, Node 22, and MySQL
8.4. A frontend production build is a separate command.

## Browser verification

Use local test accounts and disposable workspaces. Keep two browser profiles or
a normal and private window open to test different users independently.

1. **Onboarding:** Register a fresh account, open its verification link from the
   mail logs, and create Workspace A. Confirm the dashboard path contains its
   UUID. The optional demo login skips email verification because it is already
   verified.
2. **Switching:** Use the workspace menu to create Workspace B. Switch between A
   and B and confirm both the path and workspace name change. Open A and B in
   separate tabs; each tab keeps the workspace identified by its URL.
3. **Invitations:** In A's Members page, invite a different email as a member.
   Open its invitation link in the second browser, sign in or register with that
   email, verify if necessary, and accept. Confirm A appears in that user's
   switcher. Opening the link alone must not create membership.
4. **Isolation:** As the invited user, open B's dashboard path copied from the
   owner browser. Expect 404 because that account has no membership in B.
5. **Roles:** As a member, view A's members and workspace settings; editing the
   workspace and inviting users should be unavailable. As the owner, promote the
   member to admin. Refresh their browser: they can rename A and invite members,
   but cannot promote users or transfer ownership. See the
   [complete role rules](architecture.md#authorization).
6. **Invitation lifecycle:** Resend a pending invitation and verify its old link
   no longer works. Cancel it and verify the replacement link no longer works.
   Try a valid invitation while signed in as a different verified email; it must
   not allow acceptance. Acceptance is a single-use action.
7. **Ownership and leaving:** In A's Members page, transfer ownership to the
   other active user, confirming your current password. The recipient becomes
   owner and you become admin in A; B is unchanged. You can now leave A from
   Workspace settings. A's owner must transfer ownership before leaving.
8. **Workspace deletion:** Create a disposable workspace, then delete it from
   Workspace settings with your password and the `DELETE` confirmation. Its URL
   should stop working. Your account and other workspaces should remain.
9. **Email and account safeguards:** Changing your email in profile settings
   requires verification before returning to tenant pages. While you own B,
   account settings must prevent deleting your account. Transfer ownership or
   delete all owned workspaces before testing account deletion.

The automated tests also cover expired tokens, inactive memberships, forbidden
requests sent directly to the server, and transaction failure cases that are
harder to exercise through the UI.

## Extending the starter

Choose the [business architecture model](business-models.md) before adding domain
features: define what a tenant represents, who may create one, and how members
and subscriptions relate to it. Keep these choices specific to the product.

For a new tenant-owned resource:

1. Add a string `tenant_id` foreign key matching `tenants.id`, with appropriate
   indexes and tenant-scoped unique constraints.
2. Use tenant relationships or an explicit tenant scope for reads, writes, route
   binding, and validation. Initializing tenancy does not automatically filter
   every Eloquent model in a shared database.
3. Add routes inside [routes/tenant.php](../routes/tenant.php) and enforce policies
   on the server. Add permissions to [TenantPermission](../app/TenantPermission.php)
   and map them to [TenantRole](../app/TenantRole.php) where appropriate; rerun the
   default seeder when changing those definitions.
4. Test allowed access, denied actions, cross-tenant IDs, inactive memberships,
   and any tenant data exposed in Inertia props.

Cache and Redis tenancy bootstrappers are disabled. Include the tenant ID in keys
for tenant-specific cached data. For queued jobs, Stancl restores tenant context,
but the HTTP permission middleware does not run. Jobs that check roles or
permissions must also establish and restore Spatie's team ID and clear loaded
role/permission relations. See [CreateTenant](../app/Actions/Tenancy/CreateTenant.php)
for the existing permission-context pattern.

When adding uploads or external resources, define how workspace deletion cleans
them up. Current deletion cleans up database records; it does not explicitly
purge tenant storage or external resources.

## Troubleshooting

| Symptom                                          | Check                                                                                                                                                          |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Database connection refused on first boot        | Run `sail ps` and wait for MySQL to become healthy; `sail up -d --wait` handles this during startup.                                                           |
| A host port is already in use                    | Adjust `APP_PORT`, `FORWARD_DB_PORT`, or `FORWARD_REDIS_PORT` in `.env`; align `APP_URL` with the browser address and recreate services.                       |
| Missing Vite manifest or frontend changes        | Run `sail npm run build`, or keep `sail npm run dev` running. If the dev server was interrupted, remove its stale `public/hot` file before using built assets. |
| Redis connection fails after enabling Redis      | Use `REDIS_HOST=redis` inside Sail, rather than the example's loopback address.                                                                                |
| Tenant route returns 404                         | Check the UUID and an active `tenant_users` membership for the signed-in account.                                                                              |
| Tenant action returns 403                        | Check the workspace role, permission, and policy; membership alone does not grant every action.                                                                |
| Browser redirects to email verification          | Verify the current email, including after a profile email change.                                                                                              |
| Passkey origin or generated mail links are wrong | Align `APP_URL` with the browser's scheme, host, and port, then clear cached configuration.                                                                    |
