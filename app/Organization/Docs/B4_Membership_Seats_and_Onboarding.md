# B4 · Membership, Seats and Onboarding — Implementation Workflow

**Phase goal:** Employees can join safely. One active employee = one seat. The seat limit can never be passed.

**Start after:** B3 (Organizations, Contracts, Domains and Codes)
**Status:** Implemented. 98 Organization feature tests pass (32 of them B4, including 3 real multi-process concurrency tests).

**Done when:** Joining works by email, code and invitation, and the seat count is always correct, even under parallel load.
Verified locally end to end through the real HTTP API (`/auth/register`, the join endpoints, admin code and invitation endpoints), and under parallel load with 8 separate PHP processes per test. Run it on Staging with [section 11](#11-staging-done-when-check).

---

## 1. Summary

| # | Workflow step | How it is implemented |
|---|---|---|
| 1 | `SeatService.allocate()` | Transaction → `SELECT … FOR UPDATE` on the organization row → status / contract check → count active memberships → compare with `seat_limit` → create or reactivate the membership → audit → commit |
| 2 | `SeatService.release()` | Membership → `deactivated` with `deactivated_at` and reason. The user's organization roles are revoked. The Pivot account and personal history are not touched |
| 3 | Idempotency | An already active member gets the same membership back (`ALREADY_MEMBER`, HTTP 200). Backed by the `organization_memberships_one_active_per_user` unique index |
| 4 | Lifecycle: invited → active → suspended → deactivated / expired, plus restore | `MembershipService` (suspend, restore, deactivate) with transition rules on `MembershipStatus`. Restore takes a seat through `SeatService` |
| 5 | Work-email flow | `CorporateOnboardingService::joinByWorkEmail()` + `POST /corporate/join/work-email` |
| 6 | Organization-code flow | `CorporateOnboardingService::redeemCode()` + `POST /corporate/join/code` |
| 7 | Existing user flow | Every join links the signed-in account. No flow creates a user |
| 8 | Invitation flow | `OrganizationInvitationService` (create with `token_hash` + expiry, revoke) and `CorporateOnboardingService::acceptInvitation()` |
| 9 | Domain known but no seat | `POST /auth/register` always creates the account and returns `corporate_join.join_status` (e.g. `SEAT_LIMIT_REACHED`) |
| 10 | Seat reconciliation job | `ReconcileOrganizationSeats` (daily) → `SeatReconciliationService` fixes stale seats and reports over-allocation |
| 11 | Tests | Parallel joins, parallel retries and parallel code redemptions (real processes); code use limit; expired code; suspended organization; and more (section 13) |

---

## 2. Folder structure

Files added or changed in B4:

```
app/Organization/
├── Data/
│   └── CorporateJoinResult.php                 (new) status + membership of a join attempt
├── Database/Migrations/
│   └── 2026_10_29_000005_add_one_pending_invitation_per_email_to_organization_invitations.php (new)
├── Docs/
│   └── B4_Membership_Seats_and_Onboarding.md   (new) this file
├── Enums/
│   ├── CorporateJoinStatus.php                 (new) machine-readable join results + HTTP status + message
│   ├── InvitationStatus.php                    (new) pending / accepted / revoked
│   ├── MembershipJoinMethod.php                (new) work_email / code / invitation / admin / sso / scim
│   ├── MembershipStatus.php                    (changed) transition rules
│   └── AuditAction.php                         (changed) membership, invitation and reconciliation actions
├── Exceptions/
│   ├── CorporateJoinException.php              (new) rendered as { message, join_status }
│   └── OrganizationActionException.php         (changed) membership / invitation errors
├── Http/
│   ├── Controllers/
│   │   ├── CorporateJoinController.php         (new) employee join endpoints
│   │   ├── OrganizationMemberController.php    (new) admin member list and lifecycle
│   │   └── OrganizationInvitationController.php (new) admin invitations
│   ├── Requests/
│   │   ├── AcceptOrganizationInvitationRequest.php
│   │   ├── ListOrganizationInvitationsRequest.php
│   │   ├── ListOrganizationMembersRequest.php
│   │   ├── MembershipActionRequest.php
│   │   ├── RedeemOrganizationCodeRequest.php
│   │   └── StoreOrganizationInvitationRequest.php
│   └── Resources/
│       ├── CorporateJoinResource.php
│       ├── OrganizationInvitationResource.php
│       └── OrganizationMembershipResource.php
├── Jobs/
│   └── ReconcileOrganizationSeats.php          (new, daily)
├── Models/
│   ├── OrganizationInvitation.php              (changed) status cast, isExpired()
│   └── OrganizationMembership.php              (changed) joined_via cast, reason constants
├── Policies/
│   └── OrganizationMembershipPolicy.php        (new) who may suspend / restore / deactivate whom
├── Repositories/
│   ├── Contracts/OrganizationInvitationRepositoryInterface.php (new)
│   ├── OrganizationInvitationRepository.php    (new)
│   └── (changed) Membership, RedemptionCode, Domain, Organization and RoleAssignment repositories + interfaces
├── Routes/api.php                              (changed) member, invitation and /corporate routes
├── Services/
│   ├── SeatService.php                         (new) allocate / release / releaseAll
│   ├── MembershipService.php                   (new) admin lifecycle
│   ├── CorporateOnboardingService.php          (new) work email, code, invitation, registration hook
│   ├── OrganizationInvitationService.php       (new)
│   ├── SeatReconciliationService.php           (new)
│   ├── OrganizationAccessService.php           (changed) suspended members cannot use their roles
│   └── OrganizationControlService.php          (changed) removing an organization releases its seats
└── OrganizationServiceProvider.php             (changed) invitation repository binding, membership policy

app/Http/Controllers/Auth/AuthController.php    (changed) register response includes corporate_join
routes/console.php                              (changed) daily seat reconciliation
tests/Feature/Organization/
├── CorporateOnboardingTest.php                 (new, 19 tests)
├── MembershipLifecycleTest.php                 (new, 10 tests)
├── SeatConcurrencyTest.php                     (new, 3 tests)
└── Support/join_worker.php                     (new) one join attempt per PHP process
```

**Layering:** Controller (authorization + HTTP) → Service (business rules, transaction, audit) → Repository (queries) → Model. Only `SeatService` changes a membership into or out of `active`, apart from the B3 contract expiry / restore paths in `ContractMembershipService`.

---

## 3. Who can do what

| Action | Who | Check |
|---|---|---|
| Join by work email, code or invitation | Any signed-in user, for their own account only | `auth:sanctum` + the code / token / verified domain |
| List members | `members.view`: ORG_OWNER, ORG_ADMIN, Super Admin, Support | `OrganizationPolicy::viewMembers` + tenant middleware |
| Suspend / restore / deactivate a member | `members.manage` **and** rank rule | `OrganizationMembershipPolicy::manage` |
| List / create / revoke invitations | `invitations.manage`: ORG_OWNER, ORG_ADMIN, Super Admin, Support | `OrganizationPolicy::manageInvitations` |
| Invite someone as ORG_ADMIN / ORG_ANALYST / ORG_OWNER | Additionally the B2 role rank rule | `OrganizationRoleAssignmentPolicy::assign` |

**Rank rule for members.** Deactivating a member revokes their roles, so it follows the B2 rule:
- you can only act on members whose highest role ranks **below** yours;
- you can never act on yourself;
- Pivot override (Super Admin) can act on anyone;
- the target's rank uses their assigned roles even while they are suspended, so an admin cannot offboard a suspended owner.

**Suspended members.** A member whose membership is `suspended` keeps their role rows, but `OrganizationAccessService::rolesIn()` returns no roles for them. They lose admin portal access until restored.

---

## 4. Seats

- **Source of truth:** active `organization_memberships`. There is no separate seat counter.
- **One seat = one `active` membership.** `invited`, `suspended`, `deactivated` and `expired` memberships hold no seat.
- **One active organization per user:** enforced by the unique index `organization_memberships_one_active_per_user`.

### `SeatService::allocate(organization, user, via, actor?, membership?, auditContext)`

```
DB::transaction
  1. lock the organization row (SELECT … FOR UPDATE, removed organizations included)
  2. organization accepts members?
       removed            → ORGANIZATION_NOT_ACTIVE
       suspended          → ORGANIZATION_SUSPENDED
       expired / end ≤ now → CONTRACT_EXPIRED
       pending / start > now → ORGANIZATION_NOT_ACTIVE
  3. user already active here?          → return ALREADY_MEMBER (no second seat)
     user active in another organization → ALREADY_MEMBER_OF_OTHER_ORGANIZATION
  4. self-service join (work email, code) and the user was suspended / deactivated here by an admin
                                         → MEMBERSHIP_SUSPENDED / MEMBERSHIP_DEACTIVATED
  5. active count ≥ seat_limit          → SEAT_LIMIT_REACHED
  6. reactivate the user's existing row, or create one (one row per organization and user)
  7. audit organization_membership.activated (seats_used, seat_limit, joined_via, previous_status, code / invitation / domain)
commit
```

Why parallel requests cannot pass the limit:
- Joins to the **same organization** wait for each other at step 1, so each one counts the seats the previous one took.
- A **retry by the same user** waits too, and then sees step 3.
- Two joins by one user to **different organizations** are stopped by the unique index. That race is mapped to `ALREADY_MEMBER_OF_OTHER_ORGANIZATION`.

**Join dates.** Members coming back from `suspended` or `expired` keep their original `joined_at`, which the B3 "longest-standing first" restore order uses. A new join or a re-join after deactivation starts a new `joined_at`.

An admin restore keeps the original `joined_via`. The audit row records `joined_via: admin`.

### `SeatService::release(membership, reason, actor)`

- Moves `active`, `suspended` or `invited` to `deactivated`, setting `deactivated_at` and `deactivation_reason`.
- Revokes all of the user's active roles in that organization.
- Writes the audit row `organization_membership.deactivated`, which includes `roles_revoked`.
- Does not touch the `users` row, subscriptions or any personal history.

### `SeatService::releaseAll(organization, reason)`

Used when an organization is removed (`OrganizationControlService::remove`).
- Every `active`, `suspended` and `invited` membership becomes `deactivated` with reason `organization_removed`.
- This lets those users join another organization.
- Writes one audit row: `organization_membership.released`.

---

## 5. Membership lifecycle

```
            invitation created (existing user)
                     │
                 [invited] ──accept──────────────┐
                     │ revoke                    ▼
                     ▼                       [active] ◄──── join (work email / code / invitation)
              [deactivated]◄──deactivate──      │  ▲
                     │                          │  │ restore (admin, needs a free seat)
                     │                  suspend │  │
                     │                          ▼  │
                     │                      [suspended]
                     │                          │
                     └──── restore (admin) ─────┘──► deactivate
   contract expires (B3 job): active → [expired] → restored automatically on renewal (B3), or by an admin
```

| From → To | Method | Seat |
|---|---|---|
| none / invited → active | `SeatService::allocate` via a join flow | takes one |
| active → suspended | `MembershipService::suspend` (reason required) | frees one |
| active / suspended / invited → deactivated | `MembershipService::deactivate` → `SeatService::release` (reason required) | frees one if it was active |
| suspended / deactivated / expired → active | `MembershipService::restore` → `SeatService::allocate` (via `admin`) | takes one, subject to the limit |
| active → expired | B3 contract expiry job | frees one |

Invalid transitions return **409**, e.g. restoring an active member or suspending a deactivated one.

---

## 6. Onboarding flows

All flows run for the **signed-in user**. They link the existing Pivot account and never create a second one.

### Work email — `POST /api/v1/corporate/join/work-email`

1. Normalize the email (trim, lowercase) and take the part after `@`.
2. Find the **verified** domain:
   - an exact match wins;
   - otherwise a parent domain with `include_subdomains = true` (e.g. `eng.acme.com` → `acme.com`);
   - when several match, the most specific one wins;
   - pending domains are never used.
3. If none is found, return `DOMAIN_NOT_RECOGNIZED`.
4. Otherwise `SeatService::allocate(..., via: work_email)`.

### Organization code — `POST /api/v1/corporate/join/code` `{ "code": "PIVOT-…" }`

One transaction:
1. Hash the code (trimmed, uppercased, SHA-256) and lock the code row.
2. Check the code:
   - missing or `is_active = false` → `INVALID_CODE`;
   - `starts_at` in the future → `CODE_NOT_STARTED`;
   - `expires_at` in the past → `CODE_EXPIRED`.
3. If the user is already active in the code's organization, return `ALREADY_MEMBER`. The use is not counted again, and the retry works even when the code is used up.
4. If `uses_count ≥ max_uses`, return `CODE_LIMIT_REACHED`.
5. Run `SeatService::allocate(..., via: code)`. That locks the organization row and checks status, contract and seat.
6. Only when a seat was really taken: `uses_count + 1` and `last_used_at = now()`.

A failed seat allocation (full, suspended, expired) does **not** use the code.

Lock order is always code → organization (and invitation → organization), so no deadlock cycle is possible.

### Invitation

**Admin:** `POST /organizations/{organization}/invitations` `{ email, role?, expires_in_days? }`
- The email is lowercased. `role` defaults to `ORG_MEMBER`. Expiry defaults to 7 days, maximum 30.
- Only the SHA-256 `token_hash` is stored. The plaintext `one_time_token` is returned once.
- Re-inviting the same email revokes the previous pending invitation, so the old token stops working. A partial unique index allows one pending invitation per email.
- If a Pivot account with that email exists, it gets an `invited` membership (no seat).
- Inviting someone who is already an active member returns **409**.
- Revoking (`DELETE …/invitations/{invitation}`) works only for pending invitations. An `invited` membership becomes `deactivated` with reason `invitation_revoked`; that reason does not block a later self-service join.

**Employee:** `POST /api/v1/corporate/invitations/accept` `{ "token": "…" }`

One transaction:
1. Lock the invitation.
2. Check it:
   - unknown, revoked, or accepted by someone else → `INVALID_INVITATION`;
   - expired → `INVITATION_EXPIRED`;
   - the account's email is not the invited email → `INVITATION_EMAIL_MISMATCH`.
3. Run `SeatService::allocate(..., via: invitation)`. An invitation may bring back a member an admin had deactivated, because the admin chose to invite them.
4. Mark the invitation accepted.
5. Grant the invited role if it is administrative. The role assignment's `assigned_by` is the inviter.
6. Accepting the same invitation again returns `ALREADY_MEMBER`.

### Registration — `POST /api/v1/auth/register` (step 9)

After the account is created, `CorporateOnboardingService::joinOnRegistration()` runs the work-email flow.
- It **never** fails the registration. Join errors become a status; unexpected errors are reported and become `UNAVAILABLE`.
- The response gains one field; everything else in the response is unchanged:

```json
{
  "token": "…",
  "user": { … },
  "corporate_join": {
    "join_status": "SEAT_LIMIT_REACHED",
    "message": "This organization has no free seats.",
    "organization": null,
    "membership": null
  }
}
```

For a personal email the status is `DOMAIN_NOT_RECOGNIZED`, which simply means "no corporate organization". Existing users who registered before their company was set up use `POST /corporate/join/work-email` or a code.

---

## 7. Join statuses (`CorporateJoinStatus`)

Successful joins return `CorporateJoinResource` (201 `JOINED`, 200 `ALREADY_MEMBER`). Failures return `{ "message": "…", "join_status": "…" }`.

| join_status | HTTP | Meaning |
|---|---|---|
| `JOINED` | 201 | A seat was taken now |
| `ALREADY_MEMBER` | 200 | Already active here; same membership returned |
| `DOMAIN_NOT_RECOGNIZED` | 404 | No verified domain covers the email |
| `INVALID_CODE` | 404 | Unknown or inactive code |
| `CODE_NOT_STARTED` / `CODE_EXPIRED` / `CODE_LIMIT_REACHED` | 409 | Code dates or use limit |
| `INVALID_INVITATION` | 404 | Unknown, revoked or someone else's accepted invitation |
| `INVITATION_EXPIRED` | 409 | Past `expires_at` |
| `INVITATION_EMAIL_MISMATCH` | 403 | Signed-in account is not the invited email |
| `ORGANIZATION_NOT_ACTIVE` | 409 | Pending, not started yet, or removed |
| `ORGANIZATION_SUSPENDED` | 409 | Suspended by Pivot |
| `CONTRACT_EXPIRED` | 409 | Contract ended |
| `SEAT_LIMIT_REACHED` | 409 | No free seat |
| `ALREADY_MEMBER_OF_OTHER_ORGANIZATION` | 409 | One active organization per user |
| `MEMBERSHIP_SUSPENDED` / `MEMBERSHIP_DEACTIVATED` | 403 | An admin removed this member; self-service cannot undo it |
| `UNAVAILABLE` | — | Registration only: the join could not be attempted |

The names follow the B6 suggested error-code list. B6 may still rename them in the API contract.

---

## 8. Seat reconciliation — `ReconcileOrganizationSeats` (daily)

`SeatReconciliationService::reconcile()` walks every organization, including removed ones, with its active membership count:

| Finding | Action |
|---|---|
| Active memberships in a **removed** organization | Fixed: deactivated with reason `organization_removed` |
| Active memberships in an **expired** organization | Fixed: expired with reason `organization_contract_expired` (as the B3 job does) |
| More active members than `seat_limit` (e.g. the limit was lowered in B3 edit/renew) | **Reported only.** Nobody is removed automatically |

Each organization with a finding gets one `organization.seats_reconciled` audit row (SYSTEM actor, metadata: `issue`, `active_memberships`, `seat_limit`, counts) and a `warning` log line. Healthy organizations write nothing.

---

## 9. API reference

Employee (any signed-in user; middleware `auth:sanctum`, `organization.user`):

| Method | Path | Body | Success |
|---|---|---|---|
| POST | `/api/v1/corporate/join/work-email` | — | 201 / 200 `CorporateJoinResource` |
| POST | `/api/v1/corporate/join/code` | `code` | 201 / 200 |
| POST | `/api/v1/corporate/invitations/accept` | `token` | 201 / 200 |
| POST | `/api/v1/auth/register` | (unchanged) | 201 + `corporate_join` |

Admin (tenant-scoped; middleware `auth:sanctum`, `organization.user`, `organization.tenant`; `{membership}` and `{invitation}` resolve only inside `{organization}`):

| Method | Path | Body | Permission |
|---|---|---|---|
| GET | `/api/v1/organizations/{organization}/members` | `status?`, `per_page?` | `members.view` |
| POST | `…/members/{membership}/suspend` | `reason` | `members.manage` + rank |
| POST | `…/members/{membership}/restore` | — | `members.manage` + rank |
| DELETE | `…/members/{membership}` | `reason` | `members.manage` + rank |
| GET | `…/invitations` | `status?`, `per_page?` | `invitations.manage` |
| POST | `…/invitations` | `email`, `role?`, `expires_in_days?` (1–30) | `invitations.manage` (+ role rank for admin roles) |
| DELETE | `…/invitations/{invitation}` | — | `invitations.manage` |

### Examples

```http
POST /api/v1/corporate/join/code
Authorization: Bearer <token>
{ "code": "PIVOT-7Q2…" }

201 Created
{
  "join_status": "JOINED",
  "message": "You have joined the organization.",
  "organization": { "id": "01a1…", "name": "Pivot Test Organization", "package_code": "PREMIUM", "contract_end_at": "2027-10-29T23:59:59.000000Z" },
  "membership": { "id": 12, "organization_id": "01a1…", "status": "active", "joined_via": "code", "joined_at": "…", "activated_at": "…", "deactivated_at": null, "deactivation_reason": null }
}
```

```http
POST /api/v1/organizations/{organization}/invitations
{ "email": "new.admin@acme.com", "role": "ORG_ADMIN" }

201 Created
{ "data": { "id": 3, "email": "new.admin@acme.com", "role": "ORG_ADMIN", "status": "pending", "expires_at": "…", "is_expired": false, … },
  "one_time_token": "k2J…64 characters" }
```

```http
409 Conflict
{ "message": "This organization has no free seats.", "join_status": "SEAT_LIMIT_REACHED" }
```

---

## 10. Audit actions added in B4

| Action | When | Actor |
|---|---|---|
| `organization_membership.activated` | Any seat allocation (join, invitation, restore) | The joining user, or the admin who restored |
| `organization_membership.suspended` | Admin suspends a member | Admin |
| `organization_membership.deactivated` | Admin deactivates / offboards a member | Admin |
| `organization_membership.released` | All seats released when an organization is removed | Super Admin |
| `organization_invitation.created` / `.accepted` / `.revoked` | Invitation lifecycle | Admin / invited user / admin |
| `organization_role.assigned` | Administrative role granted by accepting an invitation | Invited user (metadata includes `invited_by`) |
| `organization.seats_reconciled` | Reconciliation found something | SYSTEM |

Tokens and codes are never written to the audit log (only ids and prefixes).

---

## 11. Staging "Done when" check

Run on Staging after deploying and migrating. You need a Super Admin token (see the B3 doc, section 11) and the test organization from `PivotTestOrganizationSeeder`.

1. **Work email.** Add and verify a domain the test accounts use, and set `seat_limit` to 2. Register three accounts on that domain through the app or `POST /auth/register`:
   - the first two get `corporate_join.join_status = JOINED`;
   - the third still gets **201** and `SEAT_LIMIT_REACHED`.
2. **Code.**
   - Raise the seat limit.
   - Create a code (`POST /organizations/{id}/redemption-codes`).
   - With a personal-email account, call `POST /corporate/join/code` twice. Expect `JOINED`, then `ALREADY_MEMBER`, and `uses_count` stays at 1.
3. **Invitation.**
   - Create an invitation (`POST /organizations/{id}/invitations`).
   - Accept it with a different account: expect `INVITATION_EMAIL_MISMATCH`.
   - Accept it with the invited account: expect `JOINED`.
4. **Seat count.** `GET /organizations/{id}/members?status=active` must show exactly as many members as joined, never more than `seat_limit`.
5. **Parallel load.** Set `seat_limit` to *current active + 3*. Then send about 10 simultaneous `POST /corporate/join/code` requests from 10 different accounts. Staging must run several PHP-FPM workers. Exactly 3 must get `JOINED` and the rest `SEAT_LIMIT_REACHED`.
6. **Jobs.** `php artisan schedule:list` shows `ReconcileOrganizationSeats` daily. A queue worker must be running (as for B3).

Locally, steps 1–4 were run over HTTP and passed. Step 5 is covered by `SeatConcurrencyTest`, which uses real separate processes. The local `php artisan serve` on Windows handles one request at a time, so it cannot show this over HTTP.

---

## 12. Database changes

| Migration | Change |
|---|---|
| `2026_10_29_000005_add_one_pending_invitation_per_email_to_organization_invitations` | Partial unique index `organization_invitations_one_pending_per_email` on `(organization_id, email) WHERE status = 'pending'` |

No new tables. B4 uses the B1 columns: `organization_memberships.joined_via / activated_at / deactivated_at / deactivation_reason`, `organization_redemption_codes.uses_count / last_used_at`, `organization_invitations.token_hash / status / expires_at / accepted_*`. The migration rolls back and re-applies cleanly.

---

## 13. Tests

Run with `php artisan test tests/Feature/Organization`.

| File | Covers |
|---|---|
| `CorporateOnboardingTest` (19) | Work email: domain match, normalization, subdomains, pending domains, idempotency, seat limit, suspended / expired / not-started organization, other active organization, no second account. Codes: use counting, retries, use limit, expired / not started / inactive / unknown, suspended organization, failed seat does not use the code. Invitations: accept with role, email binding, expiry, re-invite replaces token, revoke, rank rule on invited roles, already-active member. Registration status |
| `MembershipLifecycleTest` (10) | Suspend frees the seat; restore needs a seat; deactivation revokes roles and keeps the account; offboarded / suspended members cannot self-rejoin; suspended admin loses rights; rank rule incl. suspended owner; tenant isolation; invalid transitions; organization removal releases seats; reconciliation; member list filter |
| `SeatConcurrencyTest` (3) | 8 parallel processes: seat limit 3 → exactly 3 joined; 8 parallel retries by one user → one membership; code with `max_uses` 2 → exactly 2 uses |

The concurrency tests were checked against a version without the row locks. All three failed: 8 of 8 joined against a limit of 3. With the locks they pass.

`SeatConcurrencyTest` commits real rows, so it does not use `RefreshDatabase`, and it removes its own rows in `tearDown()`. Its workers use the same database connection settings as the test run.

---

## 14. Decisions to confirm

1. **Email ownership is not verified.** The `users` table has no `email_verified_at` and registration does not confirm the address. Anyone who registers `name@customer.com` gets a seat in that customer's organization.
   - Recommendation: add email verification (an OTP or link) before the work-email join. Until then, prefer codes or invitations for customers who care.
   - Where to change: `CorporateOnboardingService::joinByWorkEmail()` and `joinOnRegistration()`.
2. **Self-service rejoin is blocked after an admin action.** After an admin suspends or deactivates someone, that person cannot come back by work email or code; an admin restore or a new invitation can bring them back. See `SeatService::ensureNotRemovedByAdmin()`.
3. **Deactivation revokes all organization roles.** Suspension keeps the role rows but blocks their use.
4. **Lowering `seat_limit` below the active count is allowed** (B3) and is only reported by reconciliation. Nobody is removed automatically. The alternative is to reject such a change in `OrganizationService::update()` / `renew()`.
5. **Invitation emails are not sent.** The admin receives the one-time token, like codes in B3. Sending the email (with a deep link) fits B7 "Employee invitations".
6. **Invitation expiry:** 7 days by default, at most 30.
7. **Organization removal releases all seats** (a small change to the B3 remove action). Without it, those users could never join another organization.
8. **Rate limiting** on code redemption and invitation acceptance is left to B6 step 7. Codes are 32 random characters and tokens 64, so guessing is not practical meanwhile.

---

## 15. What later phases use from B4

- **B5:** the access resolver reads the active membership (`findActiveForUser`). `CorporateJoinResource` gains the access object.
- **B6:** the three `/corporate` endpoints and `corporate_join` on register are the mobile join APIs. B6 adds rate limits, the eligibility endpoint, the current-membership endpoint and the written API contract.
- **B7:** the member and invitation endpoints are the base of the admin APIs. B7 adds overview / utilization and sends invitation emails.
- **B9:** SSO and SCIM call `SeatService::allocate()` with `via: sso` / `scim` and `SeatService::release()` for deprovisioning.
