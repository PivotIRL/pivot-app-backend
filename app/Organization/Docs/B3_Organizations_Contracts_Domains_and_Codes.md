# B3 · Organizations, Contracts, Domains and Codes — Implementation Workflow

**Phase goal:** Pivot staff can create an organization with a contract, seats, domains and join codes.

**Start after:** B2 (Roles, Tenant Isolation and Audit)
**Status:** Implemented. 66 Organization feature tests pass (22 of them B3).

**Done when:** In Staging you can create the test organization, add a domain and a code, and expire it with the job.
Verified locally end to end (real login, HTTP endpoints, scheduler → queue → worker). Run it on Staging with [section 11](#11-staging-done-when-check).

---

## 1. Summary

| # | Workflow step | How it is implemented |
|---|---|---|
| 1 | OrganizationService: create, edit, suspend, renew. Type, package, contract dates, seat_limit | `OrganizationService` (create, update, renew), `OrganizationControlService` (suspend / reactivate, from B2) |
| 2 | Contract lifecycle: activate → active → suspended / expired → renewed | `organization_contracts` history table; `pending` → `active` by the daily activation job; suspend / reactivate; daily expiry job; renewal |
| 3 | Async job: contract expiry | `ExpireOrganizationContracts` (daily) expires organizations, their contract and their active memberships |
| 4 | DomainService: add, normalize, verify, set primary, include_subdomains | `OrganizationDomainService` + `OrganizationDomainVerifier` (DNS TXT) |
| 5 | RedemptionCodeService: company / pilot / department codes, hash + prefix only | `RedemptionCodeService`; SHA-256 hash and a 16-character prefix are stored; plaintext is returned once |
| 6 | Custom package entitlements | `organization_entitlements` saved only when `package_code = CUSTOM` |
| 7 | Prices, discounts, minimum seats, platform fees, volume bands in `pricing_config` | Validated `pricing_config` JSON on the organization and on each contract record. Nothing commercial is hard-coded. |
| 8 | Audit every change | Every create, edit, renewal, activation, expiry, membership restore, domain and code change writes an audit row through `AuditLogger` |

---

## 2. Folder structure

Files added or changed in B3, inside `app/Organization/`:

```
app/Organization/
├── Database/
│   ├── Migrations/
│   │   ├── 2026_10_29_000001_create_organization_contracts_table.php
│   │   ├── 2026_10_29_000002_add_type_to_organization_redemption_codes_table.php
│   │   ├── 2026_10_29_000003_add_verification_token_to_organization_domains_table.php
│   │   └── 2026_10_29_000004_scope_organization_domain_uniqueness_to_verified_domains.php
│   └── Seeders/
│       └── PivotTestOrganizationSeeder.php       (B3: also creates the contract record)
├── Docs/
│   └── B3_Organizations_Contracts_Domains_and_Codes.md   (this file)
├── Enums/
│   ├── AuditAction.php                           (B3 actions added)
│   ├── OrganizationDomainStatus.php              pending / verified
│   └── RedemptionCodeType.php                    company / pilot / department
├── Exceptions/
│   └── OrganizationActionException.php           (domain and code errors added)
├── Http/
│   ├── Controllers/
│   │   ├── OrganizationController.php            index, show, store, update, renew, contracts
│   │   ├── OrganizationDomainController.php      index, store, verify, primary, destroy
│   │   └── RedemptionCodeController.php          index, store, destroy (deactivate)
│   ├── Requests/
│   │   ├── ListOrganizationsRequest.php
│   │   ├── ListRedemptionCodesRequest.php
│   │   ├── RenewOrganizationContractRequest.php
│   │   ├── StoreOrganizationDomainRequest.php
│   │   ├── StoreOrganizationRequest.php
│   │   ├── StoreRedemptionCodeRequest.php
│   │   └── UpdateOrganizationRequest.php
│   └── Resources/
│       ├── OrganizationContractResource.php
│       ├── OrganizationDomainResource.php
│       ├── OrganizationRedemptionCodeResource.php
│       └── OrganizationResource.php              (B3: current_contract)
├── Jobs/
│   ├── ActivateOrganizationContracts.php         daily
│   └── ExpireOrganizationContracts.php           daily
├── Models/
│   ├── Organization.php                          (B3: contracts(), currentContract())
│   ├── OrganizationContract.php
│   ├── OrganizationDomain.php                    (B3: status enum, verification_token)
│   ├── OrganizationMembership.php                (B3: REASON_CONTRACT_EXPIRED)
│   └── OrganizationRedemptionCode.php            (B3: code_type)
├── Repositories/
│   ├── Contracts/
│   │   ├── OrganizationDomainRepositoryInterface.php
│   │   ├── OrganizationMembershipRepositoryInterface.php      (B3: expire / restore queries)
│   │   ├── OrganizationRedemptionCodeRepositoryInterface.php
│   │   └── OrganizationRepositoryInterface.php                (B3: create, update, paginate, lifecycle queries)
│   ├── OrganizationDomainRepository.php
│   ├── OrganizationMembershipRepository.php
│   ├── OrganizationRedemptionCodeRepository.php
│   └── OrganizationRepository.php
├── Routes/api.php                                (B3 routes added)
├── Services/
│   ├── AuditLogger.php                           (B3: explicit SYSTEM actions never use the logged-in user)
│   ├── ContractMembershipService.php             expire / restore memberships with the contract
│   ├── OrganizationControlService.php            (B3: reactivation respects contract dates, restores members)
│   ├── OrganizationDomainService.php
│   ├── OrganizationDomainVerifier.php
│   ├── OrganizationService.php
│   └── RedemptionCodeService.php
└── OrganizationServiceProvider.php               (B3: repository bindings, contract morph alias)

routes/console.php                                (B3: both daily jobs scheduled)

tests/Feature/Organization/
├── OrganizationB3Test.php                        11 tests
└── OrganizationLifecycleTest.php                 11 tests
```

The layering is the same as B2: Request validates → Controller authorizes with `Gate::authorize()` and calls one service method → Service applies the business rules inside `DB::transaction` and writes the audit row → Repository runs the queries → Resource shapes the JSON.

---

## 3. Who can do what

B3 adds no new permissions. It uses the B2 permission matrix through `OrganizationPolicy`:

| Action | Policy method | Permission | Allowed |
|---|---|---|---|
| List all organizations | `viewAny` | `organizations.view_any` | Super Admin, Support |
| Create organization | `create` | `organization.create` | Super Admin |
| View one organization | `view` | `organization.view` | Super Admin, Support, Owner, Admin, Analyst |
| Edit organization / contract, renew, view contract history | `manageContract` | `organization.contract.manage` | Super Admin |
| Domains (list, add, verify, set primary, remove) | `manageDomains` | `domains.manage` | Super Admin, Owner, Admin |
| Redemption codes (list, create, deactivate) | `manageCodes` | `codes.manage` | Super Admin, Owner, Admin |
| Suspend / reactivate / remove (B2) | `suspend` / `reactivate` / `delete` | `organization.suspend` / `organization.remove` | Super Admin |

Organization-scoped routes (`/organizations/{organization}/...`) still pass the B2 tenant middleware: a corporate admin can only reach their own organization. Pricing (`pricing_config`) is only returned by the contract endpoints, which are Super Admin only.

---

## 4. Organizations and contracts

### Statuses

| Status | Meaning |
|---|---|
| `pending` | Contract starts in the future. No delegated access yet. |
| `active` | Contract is current. |
| `suspended` | Suspended by Super Admin (B2). Delegated access frozen. |
| `expired` | Contract end date passed. Must be renewed. |

### Lifecycle

```
              create (start in future)          activation job (start date reached)
   create ──────────────────────────► pending ─────────────────────────────────► active
     │ (start now or past)                                                         │  ▲
     └────────────────────────────────────────────────────────────────────────────►│  │
                                                                                   │  │ reactivate
                                                           suspend (Super Admin)   ▼  │ (contract current)
                                                                               suspended
   expiry job (end date passed): pending / active / suspended ──► expired
   renew: expired / active / pending ──► active (start now/past) or pending (start in future)
          suspended ──► stays suspended (only reactivate lifts a suspension)
```

### Contract history — `organization_contracts`

Every contract period is a row: `status`, `package_code`, `seat_limit`, `starts_at`, `ends_at`, `pricing_config`. The organization keeps a copy of the current values (`package_code`, `seat_limit`, `contract_start_at`, `contract_end_at`, `pricing_config`) for fast reads. `Organization::currentContract()` is the latest row.

| Operation | Effect on contracts |
|---|---|
| Create | First contract row |
| Update contract fields | Updates the current contract row |
| Renew | Current row marked `expired`, new row created |
| Suspend / reactivate / activate / expire | Current row gets the same status as the organization |

### Create — `OrganizationService::create()`

- Status is `pending` if `contract_start_at` is in the future, else `active`.
- `created_by` = actor. A contract row is created. Entitlements are saved for CUSTOM.
- Audit: `organization.created` (+ `organization.entitlements_updated` for CUSTOM).

### Update — `OrganizationService::update()`

- An `expired` organization cannot be edited (409); renew it instead.
- If contract fields change, the status is recalculated (`pending` / `active`). A `suspended` organization stays suspended.
- Audit: `organization.updated` lists **only the fields whose values actually changed** (`changed_fields`). If `status` changed, `previous_status` and `new_status` are added. An update that changes nothing writes no audit row.

### Renew — `OrganizationService::renew()`

- New package, seat limit, dates, pricing and entitlements.
- Status: `suspended` stays `suspended`; otherwise `pending` (future start) or `active`.
- If the result is `active`, eligible members are restored ([section 5](#5-memberships-and-the-contract)).
- Audit: `organization.contract_renewed` (+ `organization.entitlements_updated` for CUSTOM, + `organization.memberships_restored` if any were eligible).

### Reactivate — `OrganizationControlService::reactivate()` (B2, updated in B3)

| Contract dates | Result |
|---|---|
| Already ended | 409 — renew the contract instead. Stays `suspended`. |
| Starts in the future | Suspension lifted to `pending`; the activation job makes it `active` on the start date |
| Current | `active`; eligible members restored |

### Daily jobs — `routes/console.php`

| Job | Runs | Does | Audit (`actor_type = system`) |
|---|---|---|---|
| `ExpireOrganizationContracts` | daily 00:00 | `pending` / `active` / `suspended` organizations with `contract_end_at` passed → `expired`; current contract → `expired`; all active memberships → `expired` | `organization.contract_expired` (with `expired_memberships` count) |
| `ActivateOrganizationContracts` | daily 00:00 | `pending` organizations whose contract has started and not ended → `active`; eligible members restored | `organization.contract_activated` (+ `organization.memberships_restored`) |

Both are queued jobs (`QUEUE_CONNECTION=database`). Every environment needs **both** the scheduler cron (`php artisan schedule:run` every minute) **and** a queue worker (`php artisan queue:work`). Without the worker, the jobs sit in the `jobs` table and nothing expires.

---

## 5. Memberships and the contract

Handled by `ContractMembershipService`.

**On expiry:** every `active` membership becomes `expired` with `deactivation_reason = organization_contract_expired` and `deactivated_at = now`.

**When the organization becomes `active` again** (renewal, activation job, or reactivation), a membership is restored only if **all** of these are true:

1. It was expired by the contract (`deactivation_reason = organization_contract_expired`). Offboarded, deactivated or suspended memberships are never restored.
2. The user is not active in another organization (one active organization per user, also enforced by a database index).
3. The membership's own `expires_at` has not passed.
4. There is a free seat under the current `seat_limit`. Longest-standing members (`joined_at`) are restored first.

Restored memberships get `status = active`, `activated_at = now`, and the deactivation fields cleared. Members who did not fit stay `expired` and are restored by a later renewal with more seats.

Audit: `organization.memberships_restored` with `restored`, `not_restored_no_free_seat` and `seat_limit`. The actor is the Super Admin for renewal and reactivation, and `system` for the activation job.

---

## 6. Domains

Handled by `OrganizationDomainService`.

| Step | Rule |
|---|---|
| Add | Domain is trimmed and lowercased, then validated as a hostname. Starts `pending` with a random `verification_token`. `include_subdomains` can be set. |
| Verify | Looks up the DNS TXT record `_pivot-verification.<domain>`; its value must equal `verification_token`. Success → `verified`, `verification_method = dns_txt`. |
| Set primary | Only a `verified` domain. Exactly one primary per organization. |
| Remove | Deletes the domain. |

### Uniqueness (protection against domain squatting)

- Each organization can claim a domain once.
- Several organizations may hold a **pending** claim for the same domain, so a pending claim cannot block the real owner.
- Only one organization can hold the **verified** domain (partial unique index `organization_domains_verified_domain_unique`).
- When one organization verifies, every other organization's pending claim for that domain is removed, with an `organization_domain.removed` audit row (`reason: verified_by_another_organization`).
- Adding or verifying a domain already verified by another organization fails (422 on add, 409 on verify).

> **For B4:** work-email onboarding must match **verified** domains only.

### DNS record the customer adds

```
Name:  _pivot-verification.acme.com
Type:  TXT
Value: pivot-domain-verification=<verification_token from the API>
```

---

## 7. Redemption codes

Handled by `RedemptionCodeService`.

- Types: `company`, `pilot`, `department`.
- Plaintext format: `PIVOT-` + 32 random uppercase characters. Returned **once** in `one_time_code` on create and never again.
- Stored: `code_hash` (SHA-256 of the plaintext, unique) and `code_prefix` (first 16 characters, to recognise a code in lists).
- Settings: `name`, `max_uses` (null = unlimited), `starts_at`, `expires_at` (after `starts_at`), `is_active`.
- Deactivate (`DELETE`): sets `is_active = false`. Deactivating twice → 409.

> **For B4:** redemption must hash the submitted code the same way and increment `uses_count` atomically.

---

## 8. Custom package entitlements and pricing

**Entitlements** (`organization_entitlements`): a list of `{ feature_key, value_json }`.
- Required when `package_code = CUSTOM`; saved only for CUSTOM organizations.
- Create, update (when sent) and renew replace the whole list. Moving away from CUSTOM removes them.
- `feature_key` values must be distinct in one request (422 otherwise).

**Pricing** (`pricing_config` JSON, validated, never in code):

| Key | Rule |
|---|---|
| `currency` | 3 letters |
| `unit_price` | number ≥ 0 |
| `discount_percent` | 0–100 |
| `minimum_seats` | integer ≥ 1 |
| `platform_fee` | number ≥ 0 |
| `volume_bands[]` | `minimum_seats` (required), `unit_price` (required), `discount_percent` |

---

## 9. API reference

Base path `/api/v1`. All routes need a Sanctum token.

**Pivot staff routes** (no tenant middleware; policies allow Pivot roles only):

| Method | Path | Policy | Success |
|---|---|---|---|
| `GET` | `/organizations` | `viewAny` | 200 paginated (`per_page` 1–100) |
| `POST` | `/organizations` | `create` | 201 organization with `current_contract` |
| `PUT` | `/organizations/{organization}` | `manageContract` | 200 organization |
| `POST` | `/organizations/{organization}/contracts/renew` | `manageContract` | 200 organization |

**Organization-scoped routes** (B2 tenant middleware + policy):

| Method | Path | Policy | Success |
|---|---|---|---|
| `GET` | `/organizations/{organization}` | `view` | 200 organization |
| `GET` | `/organizations/{organization}/contracts` | `manageContract` | 200 contract history |
| `GET` | `/organizations/{organization}/domains` | `manageDomains` | 200 list |
| `POST` | `/organizations/{organization}/domains` | `manageDomains` | 201 domain (with `verification_token`) |
| `POST` | `/organizations/{organization}/domains/{domain}/verify` | `manageDomains` | 200 domain |
| `POST` | `/organizations/{organization}/domains/{domain}/primary` | `manageDomains` | 200 domain |
| `DELETE` | `/organizations/{organization}/domains/{domain}` | `manageDomains` | 200 message |
| `GET` | `/organizations/{organization}/redemption-codes` | `manageCodes` | 200 paginated |
| `POST` | `/organizations/{organization}/redemption-codes` | `manageCodes` | 201 `{ data, one_time_code }` |
| `DELETE` | `/organizations/{organization}/redemption-codes/{redemptionCode}` | `manageCodes` | 200 code (`is_active: false`) |

### Examples

**Create an organization**

```http
POST /api/v1/organizations
{
  "name": "Northwind Research",
  "slug": "northwind-research",
  "organization_type": "CORPORATE",
  "package_code": "CUSTOM",
  "seat_limit": 80,
  "contract_start_at": "2026-11-01T00:00:00Z",
  "contract_end_at": "2027-10-31T23:59:59Z",
  "timezone": "Europe/London",
  "country_code": "GB",
  "pricing_config": { "currency": "GBP", "unit_price": 18.5, "minimum_seats": 20 },
  "entitlements": [ { "feature_key": "advanced_reports", "value_json": { "enabled": true } } ]
}
```

**Renew a contract**

```http
POST /api/v1/organizations/{id}/contracts/renew
{
  "package_code": "PREMIUM",
  "seat_limit": 120,
  "contract_start_at": "2027-11-01T00:00:00Z",
  "contract_end_at": "2028-10-31T23:59:59Z",
  "pricing_config": { "currency": "USD", "unit_price": 25 }
}
```

**Add a domain** — `POST /api/v1/organizations/{id}/domains` with `{ "domain": "acme.com", "include_subdomains": true }`

**Create a code** — `POST /api/v1/organizations/{id}/redemption-codes` with `{ "name": "Pilot team", "code_type": "pilot", "max_uses": 25, "expires_at": "2026-12-31" }`

### Errors

Same format as B2 (`{ "message": ... }`):

| Status | When |
|---|---|
| 403 | Policy or tenant check refused |
| 404 | Organization not found, or domain / code belongs to another organization |
| 409 | Editing an expired organization; reactivating after the contract ended; domain already verified (here or by another organization); setting an unverified domain as primary; deactivating an inactive code |
| 422 | Validation failed; DNS verification record not found |

---

## 10. Audit actions added in B3

| Action | Written by |
|---|---|
| `organization.created` | `OrganizationService::create()` |
| `organization.updated` | `OrganizationService::update()` (only real changes) |
| `organization.contract_renewed` | `OrganizationService::renew()` |
| `organization.contract_activated` | `ActivateOrganizationContracts` job (system) |
| `organization.contract_expired` | `ExpireOrganizationContracts` job (system) |
| `organization.memberships_restored` | Renewal, reactivation, activation job |
| `organization.entitlements_updated` | Create / update / renew for CUSTOM |
| `organization_domain.added` / `.verified` / `.set_primary` / `.removed` | `OrganizationDomainService` |
| `organization_code.created` / `.deactivated` | `RedemptionCodeService` (only the prefix is logged, never the code) |

---

## 11. Staging "Done when" check

Run once after deploying B3 to Staging.

```bash
# 1. Database and test organization
php artisan migrate
php artisan db:seed --class="App\Organization\Database\Seeders\PivotTestOrganizationSeeder"

# 2. A Super Admin (an existing staff account)
php artisan pivot:role <staff-email> PIVOT_SUPER_ADMIN
```

3. Log in with that account (`POST /api/v1/auth/login`) and use the token.
4. Find the test organization: `GET /api/v1/organizations` (slug `pivot-test-organization`).
5. Add a domain: `POST /api/v1/organizations/{id}/domains` → 201, `status: pending`.
6. Add a code: `POST /api/v1/organizations/{id}/redemption-codes` → 201 with `one_time_code`.
7. Expire it with the job. To avoid waiting a year, move the end date into the past on Staging only, then run the scheduler and one worker pass:

```bash
php artisan tinker --execute="App\Organization\Models\Organization::where('slug','pivot-test-organization')->update(['contract_end_at' => now()->subMinute()]);"
php artisan schedule:test --name="App\Organization\Jobs\ExpireOrganizationContracts"
php artisan queue:work --once --stop-when-empty
```

8. Check: `GET /api/v1/organizations/{id}` → `status: expired`; `GET /api/v1/organizations/{id}/audit-logs` contains `organization_domain.added`, `organization_code.created` and `organization.contract_expired`.
9. Restore the test organization for later phases: renew it (`POST /api/v1/organizations/{id}/contracts/renew`) or run the seeder again.

**Local result of this check** (temporary database, real HTTP, scheduler → queue → worker): seeder run twice → 1 organization, 1 contract, 1 domain; domain added (201, normalized, pending); code added (201, plaintext returned once, hash matches); after the job: organization, contract and membership all `expired`; audit shows all three actions.

---

## 12. Database changes

| Migration | Change |
|---|---|
| `2026_10_29_000001_create_organization_contracts_table` | Contract history table; existing organizations with contract dates get their first row |
| `2026_10_29_000002_add_type_to_organization_redemption_codes_table` | `code_type` (default `company`) |
| `2026_10_29_000003_add_verification_token_to_organization_domains_table` | `verification_token` |
| `2026_10_29_000004_scope_organization_domain_uniqueness_to_verified_domains` | Global unique on `domain` replaced by unique `(organization_id, domain)` + partial unique `domain WHERE status = 'verified'` |

All four roll back and re-apply cleanly (`php artisan migrate:rollback --step=4`). Rolling back `000004` fails if several organizations hold pending claims for the same domain; remove those claims first.

---

## 13. Tests

```bash
php artisan test tests/Feature/Organization
```

| Test class | What it proves |
|---|---|
| `OrganizationB3Test` | Create CUSTOM organization with contract, pricing and entitlements; renewal keeps history; domain normalized and verified only with a matching DNS record; code stored as hash, plaintext only once; expiry job expires organization, contract and memberships; renewal keeps a suspension; pending organization activated on its start date; pending organization past its end date expires; a pending domain claim does not block the real owner; a domain verified elsewhere cannot be verified again; duplicate entitlement keys rejected |
| `OrganizationLifecycleTest` | Renewal restores only eligible members (not offboarded, not past own expiry, not active elsewhere); seat limit respected, oldest first, the rest restored by a later renewal; future-start renewal restores members at activation (system actor); reactivation with current / future / ended contract; only Super Admin can reactivate; name-only update records only `name`; contract change without status change does not record `status`; unchanged update writes no audit row; real status change recorded with previous/new status |

Result: **66 passed** (all Organization tests, B2 + B3). Full suite: 92 passed, 2 failed in `GroupChallengeControllerTest`; those 2 also fail on commit `1aaad14`, before any corporate work, because the tests are out of date with the current leaderboard and emotion-count API.

---

## 14. Decisions to confirm

These choices were made during implementation. Each can be changed in one place.

| Decision | Where to change |
|---|---|
| When seats are short on restore, longest-standing members come back first; the rest wait for a renewal with more seats | `ContractMembershipService::restoreAfterContractExpiry()` |
| A suspension does not survive contract expiry: a suspended organization whose contract ends becomes `expired`, and renewing it makes it `active` | `OrganizationService::expirePastContracts()` / `renew()` |
| Reactivating before the contract start lifts the suspension to `pending` (instead of refusing) | `OrganizationControlService::reactivate()` |
| Users who joined another organization while expired stay `expired` in the old one | `OrganizationMembershipRepository::restorableAfterContractExpiry()` |
| Corporate admins (Owner, Admin) can add and verify domains; DNS proof decides who gets a domain | `OrganizationRole::permissions()` (`domains.manage`) |
| Redemption codes are 38 characters (`PIVOT-` + 32). Long but very hard to guess; shorten if employees type them by hand | `RedemptionCodeService::create()` |

---

## 15. What later phases use from B3

| Phase | Uses |
|---|---|
| B4 Membership, seats, onboarding | Verified domains for work-email join; code hash lookup + `uses_count`; `seat_limit`; organization status (`active` only) |
| B5 Access resolver | `package_code`, `organization_entitlements`, organization status |
| B7 Admin APIs | Organization, contract, domain and code endpoints |
