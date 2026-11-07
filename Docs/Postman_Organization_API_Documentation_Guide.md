# Organization API: Postman Guide

Use this guide to integrate with the Organization APIs and to create and maintain their Postman collections: authentication, organization administration and employee onboarding. Routes and validation details come from the Organization module.

## Setup

1. Import the authentication collection and environments from `Docs/postman/` (see [Authentication](#authentication)). For the other endpoints, create a **Pivot API - Organization** collection with folders for organizations/contracts, domains/codes, members/invitations/roles, audit/controls, and onboarding.
2. Use the Local and Staging environments for `baseUrl` (including `/api/v1`), `dashboardToken`, `mobileToken`, and add the IDs needed for path variables.
3. Send `Accept: application/json` on every request. Use bearer authorization with `{{dashboardToken}}` for dashboard and Pivot staff routes, and `{{mobileToken}}` for the employee onboarding routes.
4. Use test accounts and organization-scoped IDs. Never save production credentials, invitation tokens, or one-time codes in shared examples.
5. Add sanitized, real response examples and document permissions, parameters, validation, and errors. Do not assume a common response format or invent errors.

Check routes before updating the collection:

```powershell
php artisan route:list --path=api/v1
```

All paths below are relative to `{{baseUrl}}`. Organization-scoped routes require IDs belonging to the selected organization.

## Authentication

The Mobile App and the Organization Dashboard use separate logins, passwords and tokens.

| | Mobile App | Organization Dashboard |
|---|---|---|
| Login | `POST /auth/login` | `POST /organization/auth/login` |
| Password | Mobile password | Dashboard password, set through an emailed link |
| Profile | `GET /profile/me` | `GET /corporate/me` |
| Logout | `POST /auth/logout` | `POST /organization/auth/logout` |
| Token variable | `{{mobileToken}}` | `{{dashboardToken}}` |
| Token lifetime | No expiry | 12 hours (`ORGANIZATION_DASHBOARD_TOKEN_TTL_MINUTES`) |

A mobile token gets **401** on dashboard APIs, and a dashboard token gets **401** on mobile APIs, even for the same person. When a dashboard token expires, send the user back to login. There is no refresh endpoint.

### Who can use the dashboard

- Organization roles: `ORG_OWNER`, `ORG_ADMIN`, `ORG_ANALYST`. `ORG_MEMBER` cannot.
- Pivot staff: `PIVOT_SUPER_ADMIN`, `PIVOT_SUPPORT`. Each keeps its own permissions, so `PIVOT_SUPPORT` still cannot suspend organizations, manage contracts or assign roles.

An organization user needs, in that organization:

- an active role;
- a membership that is `active`, or no membership row at all (a first owner assigned by Pivot staff before joining);
- an organization that is `active` or `pending`.

This is checked at login and **on every dashboard request**. If access is lost, the existing token gets a 403 straight away, and it works again if access is restored and the token has not expired. Pivot staff are exempt from the organization and membership checks, so they can reactivate suspended organizations and renew expired ones.

### First-time password setup

1. Assigning a dashboard role creates the dashboard account and emails a setup link. Accepting an admin invitation or being granted a Pivot role does the same.
2. The link opens `{ORGANIZATION_DASHBOARD_URL}/set-password?token=...&email=...&type=setup`. The dashboard frontend must provide this page.
3. The page posts `email`, `token`, `password` and `password_confirmation` to `POST /organization/auth/reset-password`.
4. The user signs in with `POST /organization/auth/login`.

Setup links last 72 hours and reset links last 60 minutes. Reset links use `type=reset` and the same page. If a link expires, use forgot password, which sends a new setup or reset link as appropriate.

For people who already held a role before this feature, run `php artisan organization:dashboard-provision --dry-run`, then run it without `--dry-run`. Add `--resend` to send new links to accounts that have not finished setup.

### Authentication endpoints

**Dashboard login:** `POST /organization/auth/login` (public)

```json
{ "email": "admin@example.com", "password": "your-dashboard-password" }
```

Success (200):

```json
{
  "token_type": "Bearer",
  "access_token": "1|REDACTED_EXAMPLE_TOKEN",
  "expires_at": "2026-11-07T20:20:27.711991Z",
  "user": { "id": 1, "name": "Alex Admin", "email": "admin@example.com" },
  "dashboard_account": {
    "id": 1,
    "email": "admin@example.com",
    "status": "active",
    "password_set_at": "2026-11-07T08:20:27.000000Z",
    "last_login_at": "2026-11-07T08:20:27.000000Z"
  },
  "pivot_role": null,
  "pivot_permissions": [],
  "can_access_dashboard": true,
  "organizations": [
    {
      "organization": {
        "id": "01a1b518-3f45-7302-8394-ed4f9d6bd3a7",
        "name": "Acme Corporation",
        "slug": "acme-corporation",
        "status": "active",
        "package_code": "STANDARD",
        "contract_end_at": "2027-11-07T08:20:27.000000Z"
      },
      "membership": {
        "id": 1,
        "organization_id": "01a1b518-3f45-7302-8394-ed4f9d6bd3a7",
        "status": "active",
        "joined_via": "work_email",
        "joined_at": "2026-11-07T08:20:27.000000Z",
        "activated_at": "2026-11-07T08:20:27.000000Z",
        "deactivated_at": null,
        "deactivation_reason": null
      },
      "roles": ["ORG_ADMIN"],
      "permissions": ["organization.view", "members.view", "..."],
      "can_access_dashboard": true
    }
  ],
  "memberships": [
    {
      "id": 1,
      "organization_id": "01a1b518-3f45-7302-8394-ed4f9d6bd3a7",
      "status": "active",
      "joined_via": "work_email",
      "joined_at": "2026-11-07T08:20:27.000000Z",
      "activated_at": "2026-11-07T08:20:27.000000Z",
      "deactivated_at": null,
      "deactivation_reason": null,
      "organization_name": "Acme Corporation"
    }
  ]
}
```

- `organizations` lists only organizations the user can access now. `membership` is `null` for a first owner with no membership row.
- `memberships` lists the user's current memberships, including ones in organizations they cannot administer.
- `dashboard_account.status` is `pending_setup`, `active` or `disabled`.
- Pivot staff get `pivot_role` and `pivot_permissions` filled in. Use `GET /organizations` to list organizations for them; their `organizations` array here does not list every organization.

**Dashboard profile:** `GET /corporate/me` with `Authorization: Bearer {{dashboardToken}}`. Returns the same fields as login, without `token_type`, `access_token` and `expires_at`.

**Dashboard logout:** `POST /organization/auth/logout` with `Authorization: Bearer {{dashboardToken}}`. No body. Revokes only that token. Returns `{ "message": "Logged out successfully." }`.

**Forgot password:** `POST /organization/auth/forgot-password` (public)

```json
{ "email": "admin@example.com" }
```

Always returns 200 with `{ "message": "If a dashboard account exists for this email, a password link has been sent." }`.

**Reset password or first-time setup:** `POST /organization/auth/reset-password` (public)

```json
{
  "email": "admin@example.com",
  "token": "token-from-the-emailed-link",
  "password": "A-New-Password-123!",
  "password_confirmation": "A-New-Password-123!"
}
```

Returns 200 with `{ "message": "Your password has been set. Please sign in." }`. Passwords need at least 12 characters, with upper and lower case letters, a number and a symbol. A successful reset signs out every dashboard session. It does not change the mobile password or mobile sessions.

### Authentication errors

| Status | Body | Meaning |
|---|---|---|
| 401 | `{ "message": "Invalid email or password." }` | Login failed. The same response covers a wrong password, an unknown email and an account with no password yet. |
| 401 | `{ "message": "Unauthenticated." }` | Token missing, invalid, expired, revoked, or for the wrong API. Send the user to login. |
| 403 | `{ "message": "You do not have access to the Organization Dashboard." }` | Correct login or valid token, but no eligible role, membership or organization. |
| 403 | `{ "message": "This dashboard account has been disabled." }` | The dashboard account is disabled. |
| 403 | `{ "message": "This organization is suspended." }` | The user has dashboard access, but this organization is suspended. |
| 403 | `{ "message": "This organization's contract has expired." }` | The user has dashboard access, but this organization's contract has expired. |
| 422 | `{ "message": "This password link is invalid or has expired." }` | Bad, used or expired link, or an unknown or disabled account. |
| 422 | `{ "message": "Invalid data provided.", "errors": { "password": ["..."] } }` | Validation failed. |
| 429 | `{ "message": "Too Many Attempts." }` | Rate limited. Check the `Retry-After` header for the seconds to wait. |

Rate limits: login 5 per minute per email and IP, and 20 per minute per IP. Forgot password 5 per hour per email and IP, and 20 per hour per IP. Reset password 10 per minute per IP.

### Test the flow in Postman

1. Import `Docs/postman/Pivot_Organization_Auth.postman_collection.json`, `Pivot_Local.postman_environment.json` and `Pivot_Staging.postman_environment.json`.
2. Select **Pivot Local** or **Pivot Staging**. In Pivot Staging, replace the placeholder host in `baseUrl`.
3. Set `mobileEmail`, `mobilePassword`, `dashboardEmail` and `newDashboardPassword`.
4. If the account has no dashboard password yet, run **Forgot Password**, copy `token` from the emailed link into `passwordToken`, and run **Reset Password or First-Time Setup**. Then set `dashboardPassword` to the new password.
5. Run **Mobile App Login** and **Organization Dashboard Login**. They save `mobileToken`, `dashboardToken` and `organizationId`.
6. Run **Organization Dashboard Profile**.
7. Run the **Token Isolation Checks** folder. Every request must return 401.
8. Run **Organization Dashboard Logout**, then the profile request again. It must return 401, while **Mobile Profile** keeps working.
## Endpoint inventory

Unless a section says otherwise, these endpoints require a dashboard token.

### Organizations and contracts

| Request name | Method and path | Request details to document |
|---|---|---|
| List organizations | `GET /organizations` | Optional query: `per_page` (integer, 1-100; default 25). |
| Create organization | `POST /organizations` | JSON: required `name`, `slug`, `organization_type`, `package_code`, `seat_limit`, `contract_start_at`, `contract_end_at`; optional `account_type`, `timezone`, `country_code`, `pricing_config`, `entitlements`. `slug` is alpha-dash and unique; end date must be after start and in the future. `entitlements` is required for `CUSTOM`. |
| Update organization | `PUT /organizations/{{organizationId}}` | Partial JSON update. Document fields from create that are allowed to change; supplied dates must remain valid and end after start. |
| Renew organization contract | `POST /organizations/{{organizationId}}/contracts/renew` | JSON: required `package_code`, `seat_limit`, `contract_start_at`, `contract_end_at`; optional `pricing_config`, `entitlements`. End date must be after start and in the future; `entitlements` is required for `CUSTOM`. |
| Show organization | `GET /organizations/{{organizationId}}` | No body. Document the returned organization fields and any conditionally included `current_contract`. |
| List organization contracts | `GET /organizations/{{organizationId}}/contracts` | No body. Document the contract collection and the permission required to view it. |

Confirm enum values and `pricing_config` fields against the current API before adding runnable examples.

### Domains and redemption codes

| Request name | Method and path | Request details to document |
|---|---|---|
| List domains | `GET /organizations/{{organizationId}}/domains` | No body. |
| Add domain | `POST /organizations/{{organizationId}}/domains` | JSON: required `domain`; optional boolean `include_subdomains`. The API trims and lowercases the domain. |
| Verify domain | `POST /organizations/{{organizationId}}/domains/{{domainId}}/verify` | No body. Document the verification behavior and response from a real test. |
| Set primary domain | `POST /organizations/{{organizationId}}/domains/{{domainId}}/primary` | No body. |
| Remove domain | `DELETE /organizations/{{organizationId}}/domains/{{domainId}}` | No body. Capture the returned message. |
| List redemption codes | `GET /organizations/{{organizationId}}/redemption-codes` | Optional query: `per_page` (integer, 1-100; default 25). |
| Create redemption code | `POST /organizations/{{organizationId}}/redemption-codes` | JSON: required `name` and `code_type`; optional `max_uses`, `starts_at`, `expires_at`. Expiration must be after `starts_at` when provided. |
| Deactivate redemption code | `DELETE /organizations/{{organizationId}}/redemption-codes/{{redemptionCodeId}}` | No body. Describe this as deactivation, not permanent deletion, if confirmed by the response/state. |

Confirm valid `code_type` values. The create response includes a one-time code; use only a fake or redacted example.

### Members, invitations, and roles

| Request name | Method and path | Request details to document |
|---|---|---|
| List members | `GET /organizations/{{organizationId}}/members` | Optional query: `status` (valid membership status enum) and `per_page` (integer, 1-100; default 25). |
| Suspend member | `POST /organizations/{{organizationId}}/members/{{membershipId}}/suspend` | JSON: required `reason` (string, maximum 255 characters). |
| Restore member | `POST /organizations/{{organizationId}}/members/{{membershipId}}/restore` | No body. |
| Deactivate member | `DELETE /organizations/{{organizationId}}/members/{{membershipId}}` | JSON: required `reason` (string, maximum 255 characters). |
| List invitations | `GET /organizations/{{organizationId}}/invitations` | Optional query: `status` (valid invitation status enum) and `per_page` (integer, 1-100; default 25). |
| Create invitation | `POST /organizations/{{organizationId}}/invitations` | JSON: required `email`; optional `role` (defaults to `ORG_MEMBER`) and `expires_in_days` (integer, 1-30). |
| Revoke invitation | `DELETE /organizations/{{organizationId}}/invitations/{{invitationId}}` | No body. |
| List role assignments | `GET /organizations/{{organizationId}}/roles` | Optional query: `role` (valid organization role enum) and `per_page` (integer, 1-100; default 25). |
| Assign role | `POST /organizations/{{organizationId}}/roles` | JSON: required `user_id` (existing user ID) and `role` (valid organization role enum). |
| Revoke role assignment | `DELETE /organizations/{{organizationId}}/roles/{{roleAssignmentId}}` | Optional JSON body: `reason` (string, maximum 500 characters). |

Invitation creation returns a one-time token. Never publish or save a real token in shared examples.

### Audit and controls

| Request name | Method and path | Request details to document |
|---|---|---|
| List audit logs | `GET /organizations/{{organizationId}}/audit-logs` | Optional query: `action` (valid audit action enum), `actor_user_id` (integer), `from` and `to` (dates, `to` must be on or after `from`), and `per_page` (integer, 1-100; default 25). |
| Suspend organization | `POST /organizations/{{organizationId}}/suspend` | JSON: required `reason` (string, maximum 500 characters). |
| Reactivate organization | `POST /organizations/{{organizationId}}/reactivate` | JSON: required `reason` (string, maximum 500 characters). |
| Remove organization | `DELETE /organizations/{{organizationId}}` | JSON: required `reason` (string, maximum 500 characters). Capture the returned message and document the effect confirmed by the backend. |

These are privileged Pivot staff actions, using a dashboard token. Confirm required staff permissions; do not present them as available to ordinary organization users.

### Employee onboarding

These are Mobile App endpoints and require a mobile token (`{{mobileToken}}`). A dashboard token gets 401.

| Request name | Method and path | Request details to document |
|---|---|---|
| Join with work email | `POST /corporate/join/work-email` | No body. Uses the signed-in user's existing account and email. |
| Join with redemption code | `POST /corporate/join/code` | JSON: required `code` (string, maximum 64 characters). |
| Accept invitation | `POST /corporate/invitations/accept` | JSON: required `token` (string, maximum 128 characters). |

Join endpoints return `join_status`, `message`, `organization`, and `membership`. They return HTTP 201 when joining takes a seat and HTTP 200 when the user is already a member. Verify other outcomes from actual responses.

## Maintain and verify

For each request, use **Params** for query values and **Body > raw > JSON** for JSON bodies. Use only supported fields and valid enum values. Test with the right persona, then save sanitized success and relevant error examples. Never assume every endpoint has the same response shape.

Use these files as the source of truth:

- Authentication collection: `Docs/postman/`
- Routes: `app/Organization/Routes/api.php`
- Route prefix and middleware registration: `app/Organization/OrganizationServiceProvider.php`
- Request validation: `app/Organization/Http/Requests/`
- Response shapes: `app/Organization/Http/Resources/`
- Authorization behavior: the controllers and policies under `app/Organization/Http/`

When routes or validation change, rerun route discovery and update and retest affected requests. Only `organization/auth/login`, `forgot-password` and `reset-password` are public. Dashboard routes require `auth:organization-dashboard`, and employee onboarding routes require `auth:sanctum`.
