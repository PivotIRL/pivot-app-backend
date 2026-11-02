# B2 · Roles, Tenant Isolation and Audit — Implementation Workflow

**Phase goal:** Build the security base first. Every later feature needs to answer "who can do this?" and must "write it to the audit log".

**Start after:** B1 (Database Schema)
**Status:** Implemented. 43 feature tests pass.

**Done when:** An admin of organization A gets an error when trying to read organization B, and audit rows are written. ✅

---

## 1. Summary

| # | Workflow step | How it is implemented |
|---|---|---|
| 1 | Add global Pivot roles (`PIVOT_SUPER_ADMIN`, `PIVOT_SUPPORT`) to staff users | `users.pivot_role` column, `PivotRole` enum, `HasOrganizationRoles` trait on `User`, `pivot:role` artisan command |
| 2 | Use `organization_role_assignments` for `ORG_OWNER`, `ORG_ADMIN`, `ORG_ANALYST`, `ORG_MEMBER` | `OrganizationRoleAssignmentRepository`, `RoleAssignmentService`, role endpoints, unique index on active roles |
| 3 | Write Laravel Policies / Gates for each action | `OrganizationPermission` enum, permission list on each role enum, `OrganizationPolicy`, `OrganizationRoleAssignmentPolicy` |
| 4 | Tenant-scope middleware | `EnsureOrganizationTenant` (alias `organization.tenant`) and scoped route bindings |
| 5 | Pivot Super Admin can always suspend, override or remove any organization | `OrganizationControlService` and `OrganizationControlController`; Super Admin holds every permission |
| 6 | One `AuditLogger` service | `AuditLogger::record()`, used for every change in this phase |
| 7 | Tests for the permission matrix and cross-tenant access | `tests/Feature/Organization/*` |

---

## 2. Folder structure

Everything for the Corporate module lives in `app/Organization/`. Files added or changed in B2 are marked.

```
app/Organization/
├── Concerns/
│   └── HasOrganizationRoles.php                  (B2) Trait used by App\Models\User
├── Console/Commands/
│   └── AssignPivotRoleCommand.php                (B2) php artisan pivot:role
├── Database/Migrations/
│   ├── 2026_10_27_000001_add_pivot_role_to_users_table.php                               (B2)
│   └── 2026_10_27_000002_add_active_role_unique_index_to_organization_role_assignments_table.php (B2)
├── Docs/
│   └── B2_Roles_Tenant_Isolation_and_Audit.md    (this file)
├── Enums/
│   ├── AuditAction.php                           (B2)
│   ├── AuditActorType.php                        (B2)
│   ├── OrganizationPermission.php                (B2)
│   ├── OrganizationRole.php                      (B2: permissions, rank, outranks, isAdministrative)
│   └── PivotRole.php                             (B2)
├── Exceptions/
│   └── OrganizationActionException.php           (B2)
├── Http/
│   ├── Controllers/
│   │   ├── OrganizationAuditLogController.php
│   │   ├── OrganizationControlController.php
│   │   └── OrganizationRoleController.php
│   ├── Middleware/
│   │   ├── EnsureOrganizationTenant.php
│   │   └── UseCorporateUserModel.php
│   ├── Requests/
│   │   ├── AssignOrganizationRoleRequest.php
│   │   ├── ListOrganizationAuditLogsRequest.php
│   │   ├── ListOrganizationRolesRequest.php
│   │   ├── OrganizationControlRequest.php
│   │   └── RevokeOrganizationRoleRequest.php
│   └── Resources/
│       ├── OrganizationAuditLogResource.php
│       ├── OrganizationResource.php
│       └── OrganizationRoleAssignmentResource.php
├── Models/
│   ├── OrganizationAuditLog.php                  (B2: enum casts for action / actor_type)
│   └── OrganizationRoleAssignment.php            (B2: isActive())
├── Policies/
│   ├── OrganizationPolicy.php
│   └── OrganizationRoleAssignmentPolicy.php
├── Repositories/
│   ├── Contracts/
│   │   ├── OrganizationAuditLogRepositoryInterface.php
│   │   ├── OrganizationMembershipRepositoryInterface.php
│   │   ├── OrganizationRepositoryInterface.php
│   │   └── OrganizationRoleAssignmentRepositoryInterface.php
│   ├── OrganizationAuditLogRepository.php
│   ├── OrganizationMembershipRepository.php
│   ├── OrganizationRepository.php
│   └── OrganizationRoleAssignmentRepository.php
├── Routes/
│   └── api.php                                   Loaded under /api/v1
├── Services/
│   ├── AuditLogger.php
│   ├── OrganizationAccessService.php
│   ├── OrganizationControlService.php
│   ├── PivotRoleService.php
│   └── RoleAssignmentService.php
└── OrganizationServiceProvider.php               (B2: bindings, policies, morph map, routes, middleware alias, command)

app/Models/User.php                               (B2: uses HasOrganizationRoles)

tests/Feature/Organization/
├── Concerns/BuildsOrganizationActors.php
├── AuditLoggerTest.php
├── OrganizationControlTest.php
├── PermissionMatrixTest.php
├── RoleAssignmentTest.php
└── TenantIsolationTest.php
```

### Layer responsibilities

| Layer | Responsibility | Must not |
|---|---|---|
| **Route + middleware** | Authenticate (Sanctum) and enforce tenant isolation | Contain business rules |
| **Form Request** | Validate input and expose typed helpers (`role()`, `perPage()`, `reason()`) | Authorize (policies do that in the controller) |
| **Controller** | Call `Gate::authorize(...)`, call one service method, return a Resource | Query the database directly or write audit rows |
| **Policy** | Decide "may this user do this action?" using `OrganizationAccessService` | Change data |
| **Service** | Business rules, DB transaction, audit entry | Know about HTTP |
| **Repository** | Eloquent queries only | Contain business rules |
| **Resource** | Shape the JSON response | Hide authorization decisions |

---

## 3. Roles

### Global Pivot roles (stored on `users.pivot_role`)

| Role | Scope |
|---|---|
| `PIVOT_SUPER_ADMIN` | Global authority over organizations, contracts, packages, seats, admins, authentication settings, reporting permissions and suspensions. Holds **every** permission, including permissions added in later phases (`OrganizationPermission::cases()`). |
| `PIVOT_SUPPORT` | Limited support access. No commercial, suspension, removal, role or authentication changes. |

`pivot_role` is **not** mass-assignable. It can only be set through `PivotRoleService` (used by the `pivot:role` command). There is no API endpoint for it.

### Organization roles (stored in `organization_role_assignments`)

| Role | Scope | Rank |
|---|---|---|
| `ORG_OWNER` | Highest role for one organization | 40 |
| `ORG_ADMIN` | Members, seats, invitations, approved domains/codes and organization settings | 30 |
| `ORG_ANALYST` | Read-only access to approved aggregate reports | 20 |
| `ORG_MEMBER` | Employee/mobile access only. No admin portal access. | 10 |

A role is **active** while `revoked_at IS NULL`. Organization roles only count inside their own organization.

> **Control boundary**
> Corporate organizations do not get their own independent Pivot product instance. They get delegated administration inside one centrally controlled Pivot platform. Pivot Super Admin always keeps the ability to suspend, override or remove organization access.

---

## 4. Permission matrix

Permissions are defined in `Enums/OrganizationPermission.php`. Which role grants which permission is defined in `PivotRole::permissions()` and `OrganizationRole::permissions()`. This is the **single source of truth**. Policies only read from it.

| Permission | Super Admin | Support | Owner | Admin | Analyst | Member |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| **Pivot-controlled (never delegated)** | | | | | | |
| `organizations.view_any` | ✅ | ✅ | | | | |
| `organization.create` | ✅ | | | | | |
| `organization.contract.manage` | ✅ | | | | | |
| `organization.auth_settings.manage` | ✅ | | | | | |
| `organization.reporting_settings.manage` | ✅ | | | | | |
| `organization.suspend` | ✅ | | | | | |
| `organization.override` | ✅ | | | | | |
| `organization.remove` | ✅ | | | | | |
| **Delegated (inside own organization)** | | | | | | |
| `organization.view` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `organization.settings.update` | ✅ | | ✅ | ✅ | | |
| `members.view` | ✅ | ✅ | ✅ | ✅ | | |
| `members.manage` | ✅ | ✅ | ✅ | ✅ | | |
| `invitations.manage` | ✅ | ✅ | ✅ | ✅ | | |
| `domains.manage` | ✅ | | ✅ | ✅ | | |
| `codes.manage` | ✅ | | ✅ | ✅ | | |
| `roles.view` | ✅ | ✅ | ✅ | ✅ | | |
| `roles.manage` | ✅ | | ✅ | ✅ | | |
| `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `audit_logs.view` | ✅ | ✅ | ✅ | ✅ | | |

### How a permission is resolved — `OrganizationAccessService::hasPermission()`

```
hasPermission(user, permission, organization?)
 1. If the user's pivot_role grants the permission          → ALLOW
 2. If no organization was given                            → DENY
 3. If the organization is SUSPENDED or soft-deleted        → DENY  (delegated rights are frozen)
 4. If any ACTIVE role of the user in THIS organization
    grants the permission                                   → ALLOW
 5. Otherwise                                               → DENY
```

Other helpers on the same service:

| Method | Purpose |
|---|---|
| `rolesIn(user, org)` | Active `OrganizationRole`s of the user in that organization. Empty while the user's membership there is `suspended` (B4) |
| `highestRoleIn(user, org)` | Highest-ranked active role (used for the rank rule) |
| `canEnterTenant(user, org)` | Pivot staff, or an active `ORG_OWNER` / `ORG_ADMIN` / `ORG_ANALYST` role |
| `allowsDelegatedAdministration(org)` | `false` when the organization is suspended or removed |

### Policies

`OrganizationPolicy` has one method per action. Each method calls `hasPermission()` with the matching permission:

| Policy method | Permission |
|---|---|
| `viewAny` | `organizations.view_any` |
| `create` | `organization.create` |
| `view` | `organization.view` |
| `updateSettings` | `organization.settings.update` |
| `manageContract` | `organization.contract.manage` |
| `manageAuthSettings` | `organization.auth_settings.manage` |
| `manageReportingSettings` | `organization.reporting_settings.manage` |
| `suspend`, `reactivate` | `organization.suspend` |
| `override` | `organization.override` |
| `delete` | `organization.remove` |
| `viewMembers` / `manageMembers` | `members.view` / `members.manage` |
| `manageInvitations` | `invitations.manage` |
| `manageDomains` / `manageCodes` | `domains.manage` / `codes.manage` |
| `viewReports` | `reports.view` |
| `viewAuditLogs` | `audit_logs.view` |

`OrganizationRoleAssignmentPolicy`:

| Policy method | Rule |
|---|---|
| `viewAny(user, organization)` | `roles.view` |
| `assign(user, organization, role)` | `organization.override` → always allowed. Otherwise needs `roles.manage` **and** the user's highest role must **outrank** the role being assigned. |
| `revoke(user, assignment)` | Same rule as `assign`, using the assignment's role. |

Result of the rank rule:

| Actor | Can assign / revoke |
|---|---|
| Pivot Super Admin | Any role, including `ORG_OWNER` |
| `ORG_OWNER` | `ORG_ADMIN`, `ORG_ANALYST`, `ORG_MEMBER` |
| `ORG_ADMIN` | `ORG_ANALYST`, `ORG_MEMBER` |
| `ORG_ANALYST`, `ORG_MEMBER`, `PIVOT_SUPPORT` | Nothing |

Because a role cannot outrank itself, an admin cannot revoke their own `ORG_ADMIN` role and an owner cannot revoke an `ORG_OWNER` role.

Using a policy in a controller:

```php
Gate::authorize('viewAuditLogs', $organization);
Gate::authorize('assign', [OrganizationRoleAssignment::class, $organization, $role]);
Gate::authorize('revoke', $roleAssignment);
```

---

## 5. Tenant isolation

### Middleware — `EnsureOrganizationTenant` (alias `organization.tenant`)

Applied to every route under `/api/v1/organizations/{organization}`. It runs after Sanctum authentication and route model binding.

```
Request ──► auth:sanctum ──► bind {organization} ──► organization.user ──► organization.tenant ──► controller (Gate::authorize) ──► service
```

`organization.user` (`UseCorporateUserModel`) runs first. The existing `/auth/login` issues Sanctum tokens on `App\Models\Users`, while corporate policies and services use `App\Models\User`. This middleware replaces the authenticated `Users` object with the matching `User` (same `users` row) for the rest of the request. Without it, every real mobile/admin token would get 403.

Checks, in order:

| # | Check | Result on failure |
|---|---|---|
| 1 | `{organization}` resolved and user is a `User` | 403 `You do not have access to this organization.` |
| 2 | Any `organization_id` in query/body equals the route organization | 403 `Cross-organization requests are not allowed.` |
| 3 | Pivot staff | Allowed through (policies still apply) |
| 4 | User has an active administrative role (`OWNER`/`ADMIN`/`ANALYST`) in **this** organization | 403 `You do not have access to this organization.` **and** a `tenant.access_denied` audit row |
| 5 | Organization is not `SUSPENDED` | 403 `This organization is suspended.` |

The `tenant.access_denied` audit row is written with `organization_id = NULL` and the targeted organization as `target`. This way the targeted organization's admins cannot see users from other organizations, and Pivot staff can still investigate.

### Scoped route bindings

Routes use `->scopeBindings()`. A child model such as `{roleAssignment}` is resolved through `$organization->roleAssignments()`. A role assignment ID from organization B used in an organization A URL returns **404**, never B's data.

### Other layers of defence

- `{organization}` must be a UUID (`whereUuid`), so a malformed ID never reaches the database.
- `OrganizationAccessService` itself refuses delegated permissions for suspended or removed organizations. Code outside HTTP (jobs, commands) gets the same protection.
- Repositories always filter by `organization_id` (for example, audit logs are listed per organization only).

---

## 6. Role assignment workflow

### Assign — `POST /organizations/{organization}/roles`

```
AssignOrganizationRoleRequest   validate user_id (exists) and role (OrganizationRole enum)
        │
OrganizationRoleController@store
        │  Gate::authorize('assign', [OrganizationRoleAssignment::class, $organization, $role])
        ▼
RoleAssignmentService::assign()
   1. Actor without organization.override → target user must be an ACTIVE member      (else 422)
   2. Same role already active for this user in this organization                      (else 409)
   3. DB::transaction:
        - create organization_role_assignments row (assigned_by, assigned_at)
        - unique index violation (race) → 409
        - AuditLogger::record(ORGANIZATION_ROLE_ASSIGNED, organization, assignment,
                              ['user_id', 'role'], actor)
        │
OrganizationRoleAssignmentResource   201 Created
```

### Revoke — `DELETE /organizations/{organization}/roles/{roleAssignment}`

```
RevokeOrganizationRoleRequest   validate optional reason (max 500)
        │
OrganizationRoleController@destroy
        │  scoped binding: assignment must belong to {organization} (else 404)
        │  Gate::authorize('revoke', $roleAssignment)
        ▼
RoleAssignmentService::revoke()
   1. Already revoked                                                                   (else 409)
   2. DB::transaction:
        - set revoked_at = now()   (rows are never deleted — history is kept)
        - AuditLogger::record(ORGANIZATION_ROLE_REVOKED, organization, assignment,
                              ['user_id', 'role', 'reason'], actor)
        │
OrganizationRoleAssignmentResource   200 OK
```

### List — `GET /organizations/{organization}/roles`

Optional filters: `role` (enum), `per_page` (1–100, default 25). Returns active assignments only, newest first, with the user's `id`, `name` and `email`.

---

## 7. Pivot control boundary (suspend / reactivate / remove)

Handled by `OrganizationControlService`. Only users with `organization.suspend` / `organization.remove` may call it. Today that is only `PIVOT_SUPER_ADMIN`.

| Action | Endpoint | Allowed from | Result | Audit action |
|---|---|---|---|---|
| Suspend | `POST /organizations/{organization}/suspend` | Any status except `suspended` | `status = suspended` | `organization.suspended` |
| Reactivate | `POST /organizations/{organization}/reactivate` | `suspended` only | `status = active` | `organization.reactivated` |
| Remove | `DELETE /organizations/{organization}` | Any | Soft delete (`deleted_at`) | `organization.removed` |

- A `reason` is **required** for all three (`OrganizationControlRequest`). It is stored in the audit metadata together with `previous_status` and `new_status`.
- An invalid transition returns **409**, for example `An organization with status 'active' cannot be reactivated.`
- Status change and audit row are written in **one DB transaction**.
- Effects of a suspension: the organization's own admins get 403 on every corporate admin route, and all their delegated permissions return `false`. Pivot staff keep full access.
- Normal organization lifecycle (create, edit, renew, contract expiry) is **B3** (`OrganizationService`), not part of this service.

---

## 8. Audit logging

### `AuditLogger::record()`

The **only** way to write `organization_audit_logs`. Every admin, SSO and SCIM change must use it.

```php
$this->audit->record(
    action: AuditAction::ORGANIZATION_ROLE_ASSIGNED,   // required, AuditAction enum
    organization: $organization,                       // Organization | uuid string | null (platform-level)
    target: $assignment,                               // any Eloquent model | null
    metadata: ['user_id' => $user->id, 'role' => $role->value],
    actor: $actor,                                     // defaults to the authenticated user
    actorType: null,                                   // set explicitly for SSO / SCIM in B9
);
```

| Column | Filled from |
|---|---|
| `organization_id` | `organization` argument |
| `actor_user_id` | `actor`, else the authenticated `User`, else `NULL`. With `actorType: SYSTEM` (scheduled jobs, B3) the authenticated user is never used. |
| `actor_type` | Explicit `actorType`, else `system` (no actor), `pivot_staff` (actor has a Pivot role) or `user` |
| `action` | `AuditAction` enum value |
| `target_type` / `target_id` | Model morph alias and key (see morph map below) |
| `ip_address` / `user_agent` | Current request |
| `metadata` | Given array with secrets redacted; `NULL` if empty |
| `created_at` | Database default |

### Rules

- **Insert only.** `OrganizationAuditLog` throws `LogicException` on update or delete.
- **Same transaction.** Services write the change and its audit row inside one `DB::transaction`, so a change never exists without its audit row.
- **Secrets are redacted.** Metadata keys `password`, `token`, `token_hash`, `secret`, `client_secret`, `code`, `code_hash` (any nesting level, case-insensitive) are stored as `[REDACTED]`.
- **Stable target names.** The service provider registers a morph map so `target_type` stores `organization`, `organization_role_assignment`, `organization_membership`, `organization_domain`, `organization_invitation`, `organization_redemption_code`, `organization_identity_provider`, `organization_scim_credential` instead of PHP class names. `User` targets keep the class name (`App\Models\User`) so existing Sanctum token lookups are not affected.

### Audit actions added in B2

| `AuditAction` | Value | Written by |
|---|---|---|
| `PIVOT_ROLE_GRANTED` | `pivot_role.granted` | `PivotRoleService::grant()` |
| `PIVOT_ROLE_REVOKED` | `pivot_role.revoked` | `PivotRoleService::revoke()` |
| `ORGANIZATION_ROLE_ASSIGNED` | `organization_role.assigned` | `RoleAssignmentService::assign()` |
| `ORGANIZATION_ROLE_REVOKED` | `organization_role.revoked` | `RoleAssignmentService::revoke()` |
| `ORGANIZATION_SUSPENDED` | `organization.suspended` | `OrganizationControlService::suspend()` |
| `ORGANIZATION_REACTIVATED` | `organization.reactivated` | `OrganizationControlService::reactivate()` |
| `ORGANIZATION_REMOVED` | `organization.removed` | `OrganizationControlService::remove()` |
| `TENANT_ACCESS_DENIED` | `tenant.access_denied` | `EnsureOrganizationTenant` |

Later phases add their own cases to `AuditAction` (for example `domain.added`, `scim.user_provisioned`).

### Actor types (`AuditActorType`)

`user`, `pivot_staff`, `system`, `sso`, `scim`.

### Reading audit history — `GET /organizations/{organization}/audit-logs`

- Needs `audit_logs.view` (Super Admin, Support, Owner, Admin).
- Only rows with this `organization_id` are returned.
- Filters: `action` (AuditAction value), `actor_user_id`, `from`, `to` (dates, `to >= from`), `per_page` (1–100, default 25). Newest first.
- `ip_address` and `user_agent` are included **only for Pivot staff**. Corporate admins do not see them.

---

## 9. Global Pivot staff roles — `pivot:role` command

```bash
# Grant
php artisan pivot:role staff@pivot.app PIVOT_SUPER_ADMIN
php artisan pivot:role support@pivot.app PIVOT_SUPPORT

# Revoke
php artisan pivot:role support@pivot.app --revoke
```

- Fails if the email does not exist or the role is not a `PivotRole` value.
- Each grant/revoke writes a `pivot_role.granted` / `pivot_role.revoked` audit row with `actor_type = system` and `organization_id = NULL`.

`User` helpers (from `HasOrganizationRoles`):

```php
$user->pivot_role;                                // PivotRole|null (enum cast)
$user->isPivotStaff();                            // true for any Pivot role
$user->hasPivotRole(PivotRole::PIVOT_SUPER_ADMIN);
$user->organizationRoleAssignments();             // HasMany
$user->organizationMemberships();                 // HasMany
```

---

## 10. API reference

Base path: `/api/v1/organizations/{organization}` (UUID). All routes need `Authorization: Bearer <sanctum token>` and pass `organization.tenant`.

| Method | Path | Policy check | Success |
|---|---|---|---|
| `GET` | `/roles` | `OrganizationRoleAssignmentPolicy@viewAny` | 200 paginated list |
| `POST` | `/roles` | `OrganizationRoleAssignmentPolicy@assign` | 201 assignment |
| `DELETE` | `/roles/{roleAssignment}` | `OrganizationRoleAssignmentPolicy@revoke` | 200 assignment (`is_active: false`) |
| `GET` | `/audit-logs` | `OrganizationPolicy@viewAuditLogs` | 200 paginated list |
| `POST` | `/suspend` | `OrganizationPolicy@suspend` | 200 organization |
| `POST` | `/reactivate` | `OrganizationPolicy@reactivate` | 200 organization |
| `DELETE` | `/` | `OrganizationPolicy@delete` | 200 `{ "message": "Organization removed." }` |

### Examples

**Assign a role**

```http
POST /api/v1/organizations/9d3f.../roles
Content-Type: application/json

{ "user_id": 42, "role": "ORG_ANALYST" }
```

```json
{
  "id": 17,
  "organization_id": "9d3f...",
  "role": "ORG_ANALYST",
  "user": { "id": 42, "name": "Jane Doe", "email": "jane@acme.com" },
  "assigned_by": 7,
  "assigned_at": "2026-10-27T10:15:00.000000Z",
  "revoked_at": null,
  "is_active": true
}
```

**Revoke a role**

```http
DELETE /api/v1/organizations/9d3f.../roles/17
Content-Type: application/json

{ "reason": "Left the HR team" }
```

**Suspend an organization**

```http
POST /api/v1/organizations/9d3f.../suspend
Content-Type: application/json

{ "reason": "Unpaid invoice" }
```

**Audit log entry**

```json
{
  "id": 301,
  "organization_id": "9d3f...",
  "action": "organization_role.assigned",
  "actor_type": "user",
  "actor": { "id": 7, "name": "Admin User", "email": "admin@acme.com" },
  "target_type": "organization_role_assignment",
  "target_id": "17",
  "metadata": { "user_id": 42, "role": "ORG_ANALYST" },
  "created_at": "2026-10-27T10:15:00.000000Z"
}
```

(`ip_address` and `user_agent` are added when the viewer is Pivot staff.)

### Error responses

All errors use the existing API format `{ "message": "..." }` (validation adds `errors`).

| Status | When |
|---|---|
| 401 | Not authenticated |
| 403 | No access to this organization, cross-organization `organization_id`, organization suspended, or policy denied |
| 404 | Organization not found, or child resource belongs to another organization |
| 409 | Role already assigned, role already revoked, invalid status transition |
| 422 | Validation failed, or target user is not an active member |

---

## 11. Database changes

| Migration | Change |
|---|---|
| `2026_10_27_000001_add_pivot_role_to_users_table` | `users.pivot_role` (nullable string, indexed) |
| `2026_10_27_000002_add_active_role_unique_index_to_organization_role_assignments_table` | PostgreSQL partial unique index `organization_role_assignments_active_unique` on `(organization_id, user_id, role) WHERE revoked_at IS NULL` |

Both migrations roll back cleanly (`php artisan migrate:rollback --step=2`).

---

## 12. Service provider wiring — `OrganizationServiceProvider`

| Where | What |
|---|---|
| `register()` | Binds the four repository interfaces to their implementations |
| `boot()` → migrations | `loadMigrationsFrom(Database/Migrations)` |
| `boot()` → policies | `Gate::policy(Organization::class, OrganizationPolicy::class)` and `Gate::policy(OrganizationRoleAssignment::class, OrganizationRoleAssignmentPolicy::class)` |
| `boot()` → morph map | Stable `target_type` aliases for organization models |
| `boot()` → routes | Middleware aliases `organization.user` and `organization.tenant`; loads `Routes/api.php` with `api` middleware and `api/v1` prefix |
| `boot()` → console | Registers `pivot:role` |

---

## 13. Tests

Location: `tests/Feature/Organization/`. They use `RefreshDatabase` and PostgreSQL (the partial unique index needs PostgreSQL).

| Test class | What it proves |
|---|---|
| `PermissionMatrixTest` | Full matrix for every organization role and Pivot role (written out independently from the enums); org roles grant nothing in another organization; revoked roles grant nothing; suspended organization freezes delegated rights while Super Admin keeps all |
| `TenantIsolationTest` | A token from the real `/auth/login` endpoint is accepted; admin of A gets 403 on B's roles and audit logs, and a `tenant.access_denied` row is written; A cannot change B; B's child resource via A's URL is 404; `organization_id` mismatch is 403; `ORG_MEMBER` cannot enter admin scope; analyst cannot see roles or audit logs; suspended organization blocks its admins but not staff; staff can enter any organization; guests get 401; audit list contains only the requested organization |
| `RoleAssignmentTest` | Admin assigns analyst with audit row (actor, IP, user agent); admin cannot assign admin/owner; owner can assign admin but not owner; only active members can get roles from corporate admins; duplicate is 409; Super Admin can assign the first owner to a non-member; Support can view but not assign; revoke writes audit and a second revoke is 409; admin cannot revoke owner or their own role; invalid role is 422 |
| `OrganizationControlTest` | Super Admin suspends, reactivates and removes, with three audit rows; invalid transitions are 409; owner and Support cannot suspend or remove; reason is required |
| `AuditLoggerTest` | Actor/target recorded and secrets redacted (nested too); no user → `system`; audit rows are immutable; `pivot:role` grants/revokes with audit and rejects unknown roles |

Run:

```bash
php artisan test tests/Feature/Organization
```

Result at implementation time: **44 passed (354 assertions)**.

---

## 14. Decisions to confirm

These choices were made during implementation. Change them in one place if the business decides differently.

| Decision | Where to change |
|---|---|
| `PIVOT_SUPPORT` may manage members and invitations and view reports and audit logs | `PivotRole::permissions()` |
| `ORG_ADMIN` and `ORG_OWNER` have the same delegated permissions; the difference is only the rank (who they can assign) | `OrganizationRole::permissions()` / `rank()` |
| Corporate admins can only give roles to **active members** of their organization | `RoleAssignmentService::assign()` |
| Suspended organizations block their own admins completely (also read access) | `OrganizationAccessService::allowsDelegatedAdministration()` and `EnsureOrganizationTenant` |
| Expired organizations keep admin access (to see renewal information) | Same as above |
| Denied cross-tenant attempts are logged with `organization_id = NULL` | `EnsureOrganizationTenant` |
| IP and user agent in audit history are shown only to Pivot staff | `OrganizationAuditLogResource` |

---

## 15. How later phases use B2

Every new corporate endpoint follows the same pattern ("Policy check + tenant scope + audit log entry for every change"):

1. Add the route inside the `organizations/{organization}` group in `Routes/api.php` (tenant isolation and scoped bindings come for free).
2. Call `Gate::authorize('<policyMethod>', $organization)` in the controller. Add a new `OrganizationPermission` case and policy method if needed, and add it to the role enums.
3. In the service, wrap the change in `DB::transaction` and call `AuditLogger::record()` with a new `AuditAction` case.
4. Add the new permission to the expected matrix in `PermissionMatrixTest`, and add a cross-tenant test.

| Phase | Uses from B2 |
|---|---|
| B3 Organizations, contracts, domains, codes | `manageContract`, `manageDomains`, `manageCodes`, `AuditLogger` |
| B4 Membership, seats, onboarding | `manageMembers`, `manageInvitations`, `ORG_MEMBER` role, `AuditLogger` |
| B7 Admin APIs | All policies, tenant middleware, audit history endpoint |
| B8 Reporting | `viewReports`, `manageReportingSettings` |
| B9 SSO and SCIM | `manageAuthSettings`, `AuditActorType::SSO` / `SCIM` |

---

## 16. Setup checklist

- [ ] `php artisan migrate`
- [ ] `php artisan pivot:role <your-email> PIVOT_SUPER_ADMIN`
- [ ] Assign the first `ORG_OWNER` of the test organization through `POST /api/v1/organizations/{id}/roles` as Super Admin
- [ ] `php artisan test tests/Feature/Organization`
