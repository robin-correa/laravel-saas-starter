# Laravel SaaS Starter

A workspace-based SaaS foundation built with Laravel 13, Inertia 3, React 19,
Tailwind CSS 4, Stancl Tenancy 3, and Spatie Permission 8. Local development runs
on PHP 8.5 with Laravel Sail, MySQL 8.4, and Redis.

Each user has one account and can belong to multiple workspaces. Workspaces share
one database and are identified by UUIDs in paths such as
`/{tenant}/dashboard`. Custom domains and DNS configuration are unnecessary.

## Included

- Registration, email verification, password reset, two-factor authentication,
  and passkeys through Laravel Fortify.
- First-workspace onboarding, additional workspaces, and a workspace switcher.
- Active membership checks and workspace-scoped owner, admin, and member roles.
- Member management and email invitations with expiring, single-use tokens.
- Workspace settings, ownership transfer, leaving, and workspace deletion.
- Account deletion safeguards for workspace owners.
- Pest feature tests, PHPStan, Pint, frontend checks, and GitHub Actions CI.

Billing, subscriptions, a platform superadmin, and application-specific business
features are outside this foundation.

## Adapting the starter to a business

A workspace is a generic tenant. Your product can present it as a doctor's
practice, a clinic organization, a service company, or another independently
managed business. The `tenants` table already stores these workspaces.

The starter allows verified users to create additional workspaces. A specific
product can restrict creation to one owned business, customize the switcher,
and add its own subscription model. See [business architecture models](docs/business-models.md)
for examples, the distinction between practices and locations, and the changes
needed for each model. These product restrictions and billing are not implemented
by this guide.

## Fresh installation with Sail

Use these steps for a new checkout and database. You need Git and Docker with
Docker Compose v2, on Linux, macOS, or Windows through WSL2. Run commands from the
repository root.

1. Copy the example environment:

    ```sh
    cp .env.example .env
    ```

2. Install Composer dependencies to obtain Sail. This bootstrap container avoids
   requiring PHP or Composer on your host:

    ```sh
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        --env COMPOSER_HOME=/tmp/composer \
        --volume "$PWD":/app \
        --workdir /app \
        composer:2 install --ignore-platform-reqs --no-scripts --no-plugins
    ```

    Platform checks, plugins, and scripts are skipped only for this bootstrap
    step. The normal install runs inside Sail's PHP 8.5 container in step 4.
    See the [official Composer image documentation](https://hub.docker.com/_/composer)
    for the container options.

3. Start the containers and wait for the database to be healthy:

    ```sh
    vendor/bin/sail up -d --wait
    ```

4. Set up the application:

    ```sh
    vendor/bin/sail composer setup
    ```

    This installs dependencies, generates the application key, migrates and seeds
    the database, installs frontend dependencies, and builds the frontend.
    **Use `composer setup` only for a fresh installation: it generates a new
    `APP_KEY` every time.** Keep the existing key when updating an application.

5. Open [http://localhost:8000](http://localhost:8000). For frontend development,
   keep this command running in another terminal:

    ```sh
    vendor/bin/sail npm run dev
    ```

The defaults in [.env.example](.env.example) use `APP_PORT=8000`, MySQL at
`mysql:3306`, and database-backed sessions, cache, and queues. Redis is available
but unused by these defaults. If you enable it, use `REDIS_HOST=redis` inside Sail.
Keep `APP_URL` consistent with the address you open in the browser.

## First account and workspace

Register an account, verify its email, and create your first workspace. Local
email is written to Laravel logs because `MAIL_MAILER=log`; the development guide
explains how to read verification and invitation links.

For a quick local login, explicitly seed the optional demo account:

```sh
vendor/bin/sail artisan db:seed --class=DemoUserSeeder --no-interaction
```

Sign in with **`owner@example.com` / `password`**. This creates a verified account
in `local` or `testing` environments only. It creates no workspace or role
assignment; onboarding makes the creator an owner of their new workspace.
Rerunning it preserves an existing account's password and profile.

Default seeding creates the three shared role definitions and six permissions.
It creates no users or workspaces and can be rerun without removing memberships.

## Updating an existing checkout

Preserve your `.env` and `APP_KEY`. After pulling merged changes:

```sh
git pull --ff-only
vendor/bin/sail up -d --wait
vendor/bin/sail composer install
vendor/bin/sail npm ci
vendor/bin/sail artisan migrate --seed --no-interaction
vendor/bin/sail npm run build
```

Use `npm run dev` during development when you want live frontend updates.

## Verification and documentation

Run the complete project checks against the separate test database:

```sh
vendor/bin/sail composer ci:check
```

Read [the test database setup](docs/development.md#test-database) before your first
test run. Feature tests rebuild its schema.

- [Development guide](docs/development.md): Sail commands, mail, tests,
  troubleshooting, and browser verification steps.
- [Architecture](docs/architecture.md): request flow, data model, roles, and where
  to extend the starter.
- [Business architecture models](docs/business-models.md): doctor accounts,
  clinic organizations, service businesses, workspace creation, and billing options.
- [Architecture decision records](docs/adr/README.md): why tenancy, authorization,
  and workspace lifecycle work this way.
