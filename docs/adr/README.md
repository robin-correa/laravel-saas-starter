# Architecture decision records

ADRs explain significant choices and their tradeoffs. These records document the
existing starter retrospectively; the recorded date is the documentation date,
not the original implementation date. They describe accepted behavior rather
than proposals for future features.

| Record                                                                                     | Status   |
| ------------------------------------------------------------------------------------------ | -------- |
| [0001: Shared database and path identification](0001-shared-database-path-tenancy.md)      | Accepted |
| [0002: Workspace membership and scoped role assignments](0002-workspace-authorization.md)  | Accepted |
| [0003: Explicit invitations and workspace lifecycle](0003-explicit-workspace-lifecycle.md) | Accepted |

Create a new numbered record when changing a significant decision. Link to any
superseded record and update its status, keeping its reasoning available. Routine
bug fixes and UI adjustments do not need an ADR.

See [architecture](../architecture.md) for current behavior and code links, and
[development](../development.md) for commands and verification.
