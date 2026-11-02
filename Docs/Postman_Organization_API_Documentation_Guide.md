# Organization API: Postman Guide

Use this guide to create and maintain the Postman collection for organization administration and employee onboarding. Routes and validation details come from the Organization module.

## Setup

1. Create a **Pivot API - Organization** collection with folders for organizations/contracts, domains/codes, members/invitations/roles, audit/controls, and onboarding.
2. Create Local and Staging environments with `baseUrl` (including `/api/v1`), `accessToken`, and the IDs needed for path variables.
3. Set bearer authorization to `{{accessToken}}` and `Accept: application/json`. All routes require `auth:sanctum`; confirm whether the environment uses bearer tokens or Sanctum cookies.
4. Use test accounts and organization-scoped IDs. Never save production credentials, invitation tokens, or one-time codes in shared examples.
5. Add sanitized, real response examples and document permissions, parameters, validation, and errors. Do not assume a common response format or invent errors.

Check routes before updating the collection:

```powershell
php artisan route:list --path=api/v1
```

All paths below are relative to `{{baseUrl}}`. Organization-scoped routes require IDs belonging to the selected organization.

## Endpoint inventory

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

These are privileged actions. Confirm required staff permissions; do not present them as available to ordinary organization users.

### Employee onboarding

| Request name | Method and path | Request details to document |
|---|---|---|
| Join with work email | `POST /corporate/join/work-email` | No body. Uses the signed-in user's existing account and email. |
| Join with redemption code | `POST /corporate/join/code` | JSON: required `code` (string, maximum 64 characters). |
| Accept invitation | `POST /corporate/invitations/accept` | JSON: required `token` (string, maximum 128 characters). |

Join endpoints return `join_status`, `message`, `organization`, and `membership`. They return HTTP 201 when joining takes a seat and HTTP 200 when the user is already a member. Verify other outcomes from actual responses.

## Maintain and verify

For each request, use **Params** for query values and **Body > raw > JSON** for JSON bodies. Use only supported fields and valid enum values. Test with the right persona, then save sanitized success and relevant error examples. Never assume every endpoint has the same response shape.

Use these files as the source of truth:

- Routes: `app/Organization/Routes/api.php`
- Route prefix and middleware registration: `app/Organization/OrganizationServiceProvider.php`
- Request validation: `app/Organization/Http/Requests/`
- Response shapes: `app/Organization/Http/Resources/`
- Authorization behavior: the controllers and policies under `app/Organization/Http/`

When routes or validation change, rerun route discovery and update and retest affected requests. Do not document these endpoints as public: all require `auth:sanctum`.
