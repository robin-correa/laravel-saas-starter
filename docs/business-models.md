# Business architecture models

This guide explains how the starter can support different SaaS products. The
examples are product design options; they do not add subscriptions, clinical
features, job management, creation limits, or new UI behavior to the application.
All business examples are hypothetical.
See [architecture](architecture.md) for the current implementation and
[development](development.md) for extension guidance.

## Shared vocabulary

| Concept          | Meaning                                                                        |
| ---------------- | ------------------------------------------------------------------------------ |
| User             | An individual's login identity, stored in `users`.                             |
| Tenant/workspace | An independently managed account or business, stored in `tenants`.             |
| Membership       | A user's access to a tenant, stored in `tenant_users`, with an active flag.    |
| Role assignment  | What a user can do within a particular tenant.                                 |
| Location         | A branch or operating site within a business, if the product needs it.         |
| Subscription     | A commercial agreement defining paid access and capacity; not implemented yet. |

One business account can have many user logins. A person can belong to several
businesses, with a different role in each. Inviting someone to an existing tenant
adds membership after acceptance; it does not create another tenant.

Choose the tenant boundary around independently managed data, membership, and
settings. Location, service category, practitioner, project, and tenant are
separate concepts. For example, adding a doctor to a clinic or adding an aircon
service category to a company does not by itself require another tenant.

## What the starter does today

| Area                  | Implemented behavior                                                                                                        |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Initial onboarding    | A verified user with no active workspace is prompted to create one. Registration alone creates no tenant.                   |
| Additional workspaces | Verified users can create additional workspaces and become their owners, regardless of their roles in other workspaces.     |
| Invitations           | Recipients register or sign in, verify their email, and accept membership in the inviting workspace.                        |
| Roles                 | Owner, admin, and member, scoped to each workspace.                                                                         |
| Switcher              | The sidebar renders the switcher, lists workspaces with active membership and `tenant.view`, and includes Create workspace. |
| Billing               | No subscription, payment-provider integration, paid-seat limit, or billing enforcement.                                     |
| Domain features       | No practitioner profiles, clinic locations, service jobs, or customer portal.                                               |

The current creation routes are in [routes/web.php](../routes/web.php); creation
uses [CreateTenant](../app/Actions/Tenancy/CreateTenant.php). Workspace discovery
is handled by [GetAccessibleTenants](../app/Actions/Tenancy/GetAccessibleTenants.php),
and presentation by [TenantSwitcher](../resources/js/components/tenant-switcher.tsx).

## Model A: One doctor's practice per subscription

In this product model, a tenant represents a doctor's independently managed
practice/account. The doctor owns that tenant and invites assistants, nurses,
or covering doctors into it. Those people each have their own login.

```text
Dr. Santos's practice — proposed subscription A
  Dr. Santos: owner
  Maria: assistant

Dr. Cruz's practice — proposed subscription B
  Dr. Cruz: owner
  Maria: assistant
```

Maria has one user account and memberships in both tenants. She can switch
between the practices without mixing their records. Each doctor normally sees
only their own practice, unless they also have access to another practice.

Possible product rules:

- An eligible doctor creates their first practice during onboarding.
- A doctor may own at most one practice. Joining other practices remains a
  separate membership decision.
- Staff invitations lead to the existing practice; they do not start a paid
  practice or require the recipient to create one.
- Additional workspace creation is unavailable to users who are ineligible or
  already own a practice. Initial creation still exists.
- The UI calls the workspace My Practice and shows a switcher when there are
  multiple accessible practices.
- Billing is attached to the practice, with the owner managing its subscription.
  Included staff access and limits are explicit plan decisions.

Nurse, assistant, and clinical permissions would need to be added. The existing
generic owner/admin/member roles do not define who can read or write medical
records. Product eligibility must also be explicit: a user being a member in one
practice does not by itself determine whether they may own another.

## Model B: One clinic organization with several practitioners

Here a tenant represents a practice organization. It can contain several doctors,
support staff, and locations, with one proposed subscription for the organization.

```text
Clinic organization — one tenant
  Practitioners: Dr. Santos and Dr. Cruz
  Support staff: Maria and reception
  Locations: Downtown and Uptown
```

The workspace switcher has one clinic entry. A doctor/calendar filter selects
whose appointments to view; a location filter selects the branch. Neither filter
changes the tenant.

An owner can also be a practitioner, but ownership and clinical work are separate
attributes. A nonclinical owner need not consume a practitioner seat; an owner
who treats patients would consume one under a practitioner-based plan.

A proposed plan could cover two enabled practitioners and a defined allowance
of support users. Purchased practitioner capacity, its assignment rules, and
upgrades would be implemented separately from role permissions. Inviting someone
alone should not silently increase the bill.

Use locations within the tenant when the product intends shared practice data
and administration, with permissions controlling access. Separate practices
with independent records and management can use separate tenants, even if they
have the same owner.

## Model C: Service companies and multiple business ownership

For home-services management, a tenant can represent a service company. Its
owner invites dispatchers, office staff, and technicians. Customers, properties,
quotations, jobs, schedules, and invoices belong to that company.

```text
Owner's login
  Aircon service company — tenant A
    Its team, customers, quotations, and jobs
  Construction company — tenant B
    Its team, customers, quotations, and jobs
```

Additional workspace creation is useful when the same person operates several
independent businesses. The UI can call it Add business. Each business can have
different members and grants; access to one grants no access to the other.

If one company offers aircon servicing, aluminum installation, and construction,
those can be service categories within its tenant. Teams, branches, jobs, and
projects should have their own domain records when they belong to the same
business. They do not automatically become workspaces.

Other possible products where additional workspaces can be useful:

| Product               | One workspace could represent  | Reason to add another                                                             |
| --------------------- | ------------------------------ | --------------------------------------------------------------------------------- |
| E-commerce management | An independent store or brand  | Separate branding, staff, products, and operations.                               |
| Invoicing/accounting  | A business                     | Separate books and customers for another company.                                 |
| Agency/client portals | An isolated client environment | Separate client-facing access and confidential work; define who owns it and pays. |
| CRM                   | An independent company         | Separate contacts, sales teams, and pipelines.                                    |

The industry alone does not determine whether to allow more workspaces. The
question is whether customers need another independently managed environment.

## Service-company software versus a marketplace

The service-company example above is software businesses use to run their own
operations. A marketplace where homeowners discover and book providers adds
different relationships:

- Provider companies can remain tenants with internal staff memberships.
- Homeowners are customers, with a separate customer-access model. Booking a
  service should not make someone an internal member of the provider's workspace.
- Provider listings, discovery, booking permissions, commissions, and provider
  payouts need separate implementation.

The tenant foundation can support provider workspaces, but it does not supply
the marketplace workflow or its customer authorization.

## Creation and switching rules

Keep the reusable starter flexible; choose restrictions in the specific product.
Removing the Create workspace menu item only changes presentation. A product
that restricts creation must enforce eligibility and limits on the server for
both onboarding and additional-workspace routes, including concurrent requests.

A one-owned-business limit is different from a one-membership limit. An owner
could own one business and assist another, while a staff user could belong to
several businesses without owning any. Do not treat owner or staff as a single
global role; existing roles belong to individual tenants.

For a product that hides unnecessary switching controls, use the number of
accessible workspaces as the rule:

| Accessible workspaces | Suggested product UI                                                                                                 |
| --------------------- | -------------------------------------------------------------------------------------------------------------------- |
| None                  | Eligible creators see onboarding; invitation-only users continue their invitation or see an appropriate empty state. |
| One                   | Display the business/practice name; optionally hide the switching dropdown.                                          |
| More than one         | Show the switcher with only accessible workspaces.                                                                   |

Creation can be a separate entry point when eligible users need it. A doctor's
owner role does not inherently prohibit switching, and a staff role does not
inherently permit creating a business. The current starter does not yet implement
these presentation or eligibility rules.

Switching continues to use the tenant UUID in the URL. Membership and permission
checks remain on the server, and every new tenant-owned resource needs scoped
queries and authorization. Hiding controls does not establish tenant isolation.

## Subscription boundaries

The tenancy structure supports several future commercial models:

| Option                                | Example                                                                       |
| ------------------------------------- | ----------------------------------------------------------------------------- |
| One subscription per tenant           | Each doctor's practice or service company pays separately.                    |
| Tenant subscription with capacity     | A clinic buys practitioner seats, or a service company buys technician seats. |
| Subscription covering several tenants | A customer buys an explicitly modeled bundle or organization plan.            |

The first option is a straightforward starting point for the discussed products,
but the number of tenants does not automatically determine the number of bills.
A bundle would require a billing entity and entitlements covering its tenants;
the current tenant/member schema alone does not implement consolidated billing.

In a per-tenant model, the subscription and payment-provider customer belong to
the business, with an authorized owner or billing contact managing them. Ownership
transfer should preserve that relationship and explicitly update billing access
and contact details.

Define the billable unit independently of roles: a practitioner profile or
technician seat is not simply every user with member permissions. Specify when
capacity is reserved or activated, how upgrades are confirmed, and what happens
when capacity is reduced. Changing a subscription or deactivating a provider
should not erase their historical business records.

For home services, the company's SaaS subscription and a homeowner's payment for
a service job are separate payment flows. Do not assume that adding SaaS billing
also supplies customer invoicing or marketplace payouts.

## Choosing a model for a new product

Before changing the starter, record the chosen tenant boundary, creation
eligibility, ownership limit, invitation flow, domain permissions, switcher
behavior, and subscription boundary. Decide whether branches and projects belong
inside a tenant, and whether customer users need a separate portal.

Create a product-specific ADR once those choices are accepted. The existing
[ADRs](adr/README.md) describe the starter's accepted technical decisions; the
alternatives in this guide remain design options.

When implementing a choice, cover direct creation requests, creation limits,
invitation acceptance without unwanted tenant creation, users with several
memberships, and access to records in another tenant. Add billing entitlement
coverage when paid access is implemented. Domain-specific data and permissions
need their own tests beyond the existing tenant foundation.
