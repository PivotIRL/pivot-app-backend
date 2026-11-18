# Pivot Corporate Phase 2 – Backend Workflow (Phase by Phase)

Laravel / PostgreSQL. This file contains all the content of *Pivot Corporate Phase 2 – Backend Architecture (Updated with client review comments and Standard / Premium package model, October 2026)*. The content is organized into build phases, from setup to production release.

> Items marked **Suggestion** are not in the architecture document. They are extra advice for the developer.

---

## Scope

This is the backend target architecture for Pivot Corporate Phase 2. Corporate is added as an **organization and entitlement layer** on top of the existing Pivot consumer backend.

The design supports:

- Corporate and education organizations
- Seat licensing
- Work-email onboarding
- Organization codes
- Standard / Premium / Custom Corporate packages
- Administration
- Privacy-safe reporting
- SSO and automated provisioning (SCIM)

> **Updated package decision**
> Pivot no longer uses Tier 2 / Tier 3 as the product package model. The implementation must use **STANDARD** and **PREMIUM**.
> - Corporate Standard = Pivot Standard.
> - Corporate Premium = Pivot Premium.
> - Custom Corporate Package (CCP) adds organization-specific entitlements and enterprise services.
> - Legacy provider identifiers may stay for a short time for migration compatibility.

## Architecture principles (apply to every phase)

- One Pivot platform and one user identity model for consumer and corporate users.
- Corporate access is an **access source**, not a separate mobile product.
- Effective access priority: **Active Corporate Access → Active Personal Subscription → Free/Base access.**
- Pivot keeps global control. Corporate Admins manage only approved functions inside their own organization.
- The backend is the authority for organization membership, seat availability, package access and corporate status. Apps only show what the backend returns.
- Corporate reporting is aggregate and privacy-safe. Individual wellbeing and device behavior are never shown to employers.
- Phase 2 must stay compatible with a future Phase 3 Desktop client, **without adding Desktop scope now**.

## Why this order?

- **Tables** come first, because all code needs them.
- **Roles and audit** come early, because every admin, seat and SSO action must check permissions and write an audit row.
- **Organizations** must exist before people can **join** them.
- **Access** needs memberships, and the **mobile APIs** need access.
- **Analytics** needs real members and events. **SSO/SCIM** reuses seats and audit.
- **Test at the end of every phase.** B11 is the final full test.

## Overview

| # | Phase | Start after | Steps |
|---|---|---|---|
| B0 | Setup, Rules and Compatibility | Nothing. Start here. | 10 |
| B1 | Database Schema | B0 | 21 |
| B2 | Roles, Tenant Isolation and Audit | B1 | 7 |
| B3 | Organizations, Contracts, Domains and Codes | B2 | 8 |
| B4 | Membership, Seats and Onboarding | B3 | 11 |
| B5 | Effective Access Resolver and Packages | B4 | 9 |
| B6 | Mobile APIs | B5 | 8 |
| B7 | Admin APIs | B6 (can run in parallel with mobile work) | 14 |
| B8 | Analytics, Privacy and Reporting | B7 | 21 |
| B9 | SSO and SCIM | B8 (needs B4 seats and B2 audit) | 13 |
| B10 | Security and Production Readiness | Can run in parallel from B6. Must finish before B11. | 10 |
| B11 | Backend QA, Pilot and Release | B1–B10 | 20 |

> **When can the apps start?** iOS and Android can start after **B6** (API contract ready). Admin web can start after **B7**.

---

## B0 · Setup, Rules and Compatibility

**Goal:** Prepare environments, agree the architecture, and set the main rules in code before building features.

**Start after:** Nothing. Start here.

### Logical backend architecture

| Layer | Responsibility |
|---|---|
| API Layer | Mobile APIs, Pivot Super Admin APIs, Corporate Admin APIs, SSO callbacks, SCIM endpoints and reporting endpoints. |
| Application Services | Organization, membership, seat, contract, domain, redemption, package, entitlement, analytics and audit rules. |
| Authorization | Sanctum authentication, global Pivot roles, organization roles, tenant isolation and permission policies. |
| Persistence | PostgreSQL schema, constraints, atomic transactions, indexes and retention rules. |
| Integrations | RevenueCat, email/push, Microsoft Entra, Google Workspace, Okta/OIDC/SAML, SCIM and optional HRIS. |
| Async Jobs | Contract expiry, metric aggregation, notifications, seat reconciliation and data-retention processing. |

### Current-state compatibility

| Area | Phase 2 approach |
|---|---|
| Authentication | Keep existing Laravel authentication/Sanctum. Extend it with organization membership and enterprise SSO. Do not replace it only for Corporate. |
| Retail billing | Keep RevenueCat and server-side subscription validation for personal Standard/Premium subscriptions. |
| Legacy tier identifiers | If old RevenueCat/backend values such as tier2/tier3 still exist, map them internally to STANDARD/PREMIUM during migration. Do not show legacy tier language in new Corporate APIs. |
| Existing user data | Challenges, streaks, research, blocker settings and history stay attached to the same personal Pivot account. |
| Existing APIs | Extend login/register/profile responses with effective access and organization membership. Do not create duplicate corporate user accounts. |

### Organization types

The same organization architecture supports different customer sectors.

| organization_type | Examples | Notes |
|---|---|---|
| CORPORATE | Private companies, employers | Default B2B organization type. |
| EDUCATION | Schools, universities | Supports school deployments. Any age-specific onboarding/safeguarding policy must be **separately approved** before use with minors. |
| HEALTHCARE | Hospitals, health services | Same membership/role model. Stricter customer policies may be configured. |
| GOVERNMENT | Government departments/agencies | Same enterprise identity and reporting model. |
| OTHER | Other approved partners | Future-compatible. |

### Steps

- [ ] 1. Create separate Development, Staging and Production environments, each with its own PostgreSQL database.
- [ ] 2. Set up CI/CD for repeatable backend deployments: run tests on every push, deploy to Staging, and have a rollback process.
- [ ] 3. Add central error monitoring and health/uptime monitoring early (you will need them while building).
- [ ] 4. Create a **Package** enum: STANDARD, PREMIUM, CUSTOM. Do not use Tier 2 / Tier 3 in any new code.
- [ ] 5. Write a legacy mapping: old tier2 → STANDARD, old tier3 → PREMIUM (internal only, never in new Corporate APIs).
- [ ] 6. Create enums for organization_type (CORPORATE, EDUCATION, HEALTHCARE, GOVERNMENT, OTHER), organization status, membership status and roles.
- [ ] 7. Add config values (not hardcoded): privacy threshold = 20, reporting retention = 12–24 months.
- [ ] 8. Agree the folder structure for the Corporate module, following the layers above (models, services, policies, controllers, jobs).
- [ ] 9. Confirm that existing user data (challenges, streaks, research, blocker settings, history) stays on the personal account. No data is copied or moved.
- [ ] 10. Do not add Desktop-specific tables or sync features (see Phase 3 readiness at the end).

> **Rules to keep in mind for every phase**
> - One user account for consumer and corporate users. Never create a second account.
> - Keep Laravel Sanctum and RevenueCat. Extend them, do not replace them.
> - The backend decides membership, seats and access. Apps only show what the backend returns.
> - **Do not hardcode commercial logic.** Discount percentages, per-seat price, minimum seats, platform fees and volume bands belong in configurable contract/pricing records, never in mobile or backend feature logic.
> - No Desktop work in Phase 2.

> **Done when:** You can deploy to Staging with one command, and the enums/config exist.

---

## B1 · Database Schema

**Goal:** Create all Corporate tables. Create them in this order, because later tables point to earlier ones (foreign keys).

**Start after:** B0

### Steps – migrations in the correct order

Fields below are the recommended fields from the architecture document.

- [ ] 1. **organizations** – Corporate/education customer master record.
  `id UUID PK; name; slug; organization_type; account_type; status; package_code; seat_limit; contract_start_at; contract_end_at; timezone; country_code; pricing_config JSONB; reporting_config JSONB; created_by; timestamps; deleted_at`
- [ ] 2. **organization_domains** – Approved email domains used for automatic onboarding.
  `id; organization_id FK; domain UNIQUE; status; is_primary; verification_method; include_subdomains; verified_at; timestamps`
- [ ] 3. **organization_memberships** – Authoritative seat/member lifecycle record.
  `id; organization_id FK; user_id FK; status; joined_via; joined_at; activated_at; deactivated_at; expires_at; deactivation_reason; provisioning_source_id; timestamps`
- [ ] 4. **organization_entitlements** – Package overrides for Custom Corporate Packages.
  `id; organization_id FK; feature_key; value_json JSONB; timestamps`
- [ ] 5. **organization_redemption_codes** – Controlled company/pilot/department codes.
  `id; organization_id FK; name; code_hash; code_prefix; max_uses; uses_count; starts_at; expires_at; is_active; created_by; last_used_at; timestamps`
- [ ] 6. **organization_role_assignments** – Organization-specific RBAC.
  `id; organization_id FK; user_id FK; role; assigned_by; assigned_at; revoked_at`
- [ ] 7. **organization_invitations** – Employee/admin invitations.
  `id; organization_id FK; email; role; token_hash; status; expires_at; invited_by; accepted_by_user_id; accepted_at; timestamps`
- [ ] 8. **organization_audit_logs** – Immutable corporate administration audit history (insert only, no updates).
  `id; organization_id; actor_user_id; actor_type; action; target_type; target_id; ip_address; user_agent; metadata JSONB; created_at`
- [ ] 9. **organization_identity_providers** – Enterprise SSO configuration.
  `id; organization_id FK; provider_type; issuer_url; client_id; encrypted_client_secret; metadata_url; tenant_id; is_enforced; is_active; timestamps`
- [ ] 10. **user_identity_links** – Stable mapping between Pivot users and external IdP identities.
  `id; user_id; organization_id; provider_id; external_subject; external_email; last_login_at`
- [ ] 11. **organization_scim_credentials** – Provisioning credentials.
  `id; organization_id; token_hash; description; last_used_at; expires_at; is_active; created_by; revoked_at`
- [ ] 12. **organization_daily_metrics** – Privacy-safe aggregate Corporate reporting.
  `id; organization_id; metric_date; eligible_members; active_members; access_attempts; challenge_acceptances; focus_minutes; reclaimed_minutes; monotasking_blocks; context_switches_prevented; active_streak_users; mood_response_count; average_mood_score; onboarding_completed; timestamps`
- [ ] 13. **organization_baselines** – Pre-Pivot baseline used for comparative dashboard reporting.
  `id; organization_id; baseline_start; baseline_end; active_users; avg_focus_minutes; avg_access_attempts; avg_reclaimed_minutes; avg_mood_score nullable; source; locked_at`
- [ ] 14. **organization_pulse_metrics** – Aggregated micro-pulse / survey metrics.
  `id; organization_id; metric_date; question_key; response_count; average_score; cohort_key nullable; timestamps`
- [ ] 15. **organization_hr_metrics** (optional, can wait) – Optional imported/connected HRIS aggregate metrics.
  `id; organization_id; period_start; period_end; metric_key; metric_value; cohort_key nullable; source_system; imported_at`

### Steps – constraints, models and seed data

- [ ] 16. Add foreign keys and indexes on organization_id and user_id in every table.
- [ ] 17. **Suggestion:** Add a **partial unique index** on organization_memberships(user_id) WHERE status = 'active'. This makes "one active organization per user" safe in the database itself.
- [ ] 18. Add unique indexes: organization_domains(domain) (required). **Suggestion:** also organization_daily_metrics(organization_id, metric_date) and user_identity_links(provider_id, external_subject).
- [ ] 19. **Suggestion:** Add a unique index on organization_redemption_codes(code_hash).
- [ ] 20. Create Eloquent models, relationships, casts (JSONB, enums) and factories.
- [ ] 21. Create a seeder for an internal **Pivot test organization** (you will use it in every later phase).

> **Done when:** All migrations run up and down on Staging, and the test organization seeds correctly.

---

## B2 · Roles, Tenant Isolation and Audit

**Goal:** Build the security base first. Every later feature needs "who can do this?" and "write it to the audit log".

**Start after:** B1

### Roles

| Role | Scope |
|---|---|
| PIVOT_SUPER_ADMIN | Global authority over organizations, contracts, packages, seats, admins, authentication settings, reporting permissions and suspensions. |
| PIVOT_SUPPORT | Limited support access, without unrestricted commercial or global-security change rights. |
| ORG_OWNER | Highest role for one organization. |
| ORG_ADMIN | Members, seats, invitations, approved domains/codes and organization settings. |
| ORG_ANALYST | Read-only access to approved aggregate reports. |
| ORG_MEMBER | Employee/mobile access only. |

> **Control boundary**
> Corporate organizations do **not** get their own independent Pivot product instance. They get delegated administration inside one centrally controlled Pivot platform. Pivot Super Admin always keeps the ability to suspend, override or remove organization access.

### Steps

- [ ] 1. Add global Pivot roles (PIVOT_SUPER_ADMIN, PIVOT_SUPPORT) to staff users.
- [ ] 2. Use organization_role_assignments for ORG_OWNER, ORG_ADMIN, ORG_ANALYST, ORG_MEMBER.
- [ ] 3. Write Laravel Policies / Gates for each action, based on the role table above.
- [ ] 4. Add a tenant-scope middleware: a Corporate Admin request can only read or change data of their own organization_id.
- [ ] 5. Make sure Pivot Super Admin can always suspend, override or remove any organization.
- [ ] 6. Build one **AuditLogger** service: actor, action, target, IP, user agent, metadata. Use it for every admin, SSO and SCIM change.
- [ ] 7. Write tests for the RBAC permission matrix and for cross-tenant access (must be refused).

> **Done when:** An admin of organization A gets an error when trying to read organization B, and audit rows are written.

---

## B3 · Organizations, Contracts, Domains and Codes

**Goal:** Pivot staff can create an organization with a contract, seats, domains and join codes.

**Start after:** B2

### Steps

- [x] 1. OrganizationService: create, edit, suspend, renew. Set type, package, contract dates and seat_limit.
- [x] 2. Contract lifecycle: activate → active → suspended / expired → renewed.
- [x] 3. Async job: **contract expiry**. Each day, find contracts past contract_end_at, mark them expired, and expire their active memberships.
- [x] 4. DomainService: add domain, normalize (lowercase, trim), verify, set primary, include_subdomains option.
- [x] 5. RedemptionCodeService: generate company/pilot/department codes. Store only the **hash** + prefix. Set max_uses, starts_at, expires_at, is_active.
- [x] 6. Custom package: save organization_entitlements (feature_key + value_json) for CUSTOM organizations.
- [x] 7. Store prices, discounts, minimum seats, platform fees and volume bands in pricing_config (contract/pricing records), never in code.
- [x] 8. Write audit log entries for every change in this phase.

> **Done when:** In Staging you can create the test organization, add a domain and a code, and expire it with the job.

---

## B4 · Membership, Seats and Onboarding

**Goal:** Employees can join safely. One active employee = one seat. The seat limit can never be passed.

**Start after:** B3

### Membership and seat rules

- One active employee account = one seat.
- Phase 2 V1 allows **one active corporate organization per user**, unless a later requirement explicitly supports multiple memberships at the same time.
- Active organization_memberships are the source of truth for seat usage.
- Seat allocation must happen inside a DB transaction with the organization row locked (SELECT … FOR UPDATE).
- Join and redeem must be idempotent: a retry must not use a second seat.
- Offboarding releases the seat but keeps the personal Pivot account and personal history.

### Corporate onboarding flows

| Flow | Backend behavior |
|---|---|
| Work email | Normalize email → resolve verified domain → validate organization/contract → validate seat → create membership → return corporate access. |
| Organization code | Validate hashed code, date/use limits, organization and seat → create membership atomically → increment usage → return corporate access. |
| Existing user | Link the existing Pivot account to the organization. Do not create a second account. |
| Enterprise SSO | Built in **B9**. Resolve organization → redirect to provider → validate identity → link/create Pivot user → allocate membership/seat → issue Pivot session. |

If a corporate domain is recognized but a seat cannot be allocated, the user's personal Pivot registration should normally **still succeed**, with a clear corporate join status returned.

### Steps

- [x] 1. Build **SeatService.allocate()**: start transaction → lock organization row → check status/contract → count active memberships → compare with seat_limit → create/activate membership → commit.
- [x] 2. Build **SeatService.release()**: set membership deactivated, set deactivated_at and reason. Personal data stays.
- [x] 3. Add idempotency: if the user already has an active membership in this organization, return it (do not create a new one).
- [x] 4. Membership lifecycle: invited → active → suspended → deactivated / expired, plus restore.
- [x] 5. Work-email flow (see below).
- [x] 6. Organization-code flow (see below).
- [x] 7. Existing user flow: link the current account to the organization. Never create a second account.
- [x] 8. Invitation flow: create invitation with token_hash and expiry → accept → allocate seat.
- [x] 9. Domain known but no free seat: personal registration still succeeds. Return a clear corporate join status.
- [x] 10. Async job: **seat reconciliation**. Regularly compare the seat count with active memberships and report or fix differences.
- [x] 11. Write tests: parallel requests cannot pass the seat limit; code use limit; expired code; suspended organization.

**Flow – Work email signup**

1. Normalize the email.
2. Find the verified domain → organization.
3. Check organization status and contract.
4. Allocate a seat (SeatService).
5. Create the membership.
6. Return the corporate access object (B5).

**Flow – Organization code**

1. Hash the code and find it.
2. Check is_active, starts_at, expires_at and max_uses.
3. Check organization and contract.
4. In one transaction: allocate seat + create membership + increment uses_count + set last_used_at.
5. Return the corporate access object.

> **Done when:** Joining works by email, code and invitation, and the seat count is always correct, even under parallel load.

---

## B5 · Effective Access Resolver and Packages

**Goal:** One service gives one answer: what access does this user have right now?

**Start after:** B4

### Package and entitlement model

| Package | Corporate equivalent | Typical use |
|---|---|---|
| STANDARD | Corporate Standard | Core paid Pivot mobile functionality and standard Corporate reporting. |
| PREMIUM | Corporate Premium | Full premium mobile feature set, advanced focus/blocking and advanced Corporate reporting. |
| CUSTOM | Custom Corporate Package (CCP) | Standard or Premium baseline plus organization-specific feature limits, policies, integrations and enterprise services. |

### Access priority

**Active Corporate package → Active personal Standard/Premium subscription → Free/Base access.**

### Access object (returned by the backend)

| Field | Purpose |
|---|---|
| access_source | free / personal_subscription / corporate / admin_grant |
| package | standard / premium / custom |
| status | active / suspended / expired |
| organization_id / organization_name | Present for corporate access. |
| contract_expires_at | Corporate access expiry. |
| feature_entitlements | Resolved capabilities and limits used by all clients. |

### WORK challenge category

Client feedback asks for a dedicated Work challenge category for paid/corporate users. Use metadata for targeting, **not** hardcoded mobile filtering.

| Capability | Backend requirement |
|---|---|
| Category | WORK as an activity/challenge category. |
| Package eligibility | Configurable for Standard, Premium and Custom Corporate packages. |
| Organization targeting | Optional organization-specific include/exclude rules. |
| Reporting | Aggregate participation/completion can be reported to employers without showing individual challenge history (see B8). |

### Steps

- [ ] 1. Define the feature list for STANDARD and PREMIUM (same as Pivot Standard and Pivot Premium).
- [ ] 2. For CUSTOM: start from the Standard or Premium base, then apply organization_entitlements overrides.
- [ ] 3. Build **AccessResolver**: check active corporate membership → else check RevenueCat personal subscription → else Free/Base.
- [ ] 4. Apply the legacy mapping (tier2/tier3 → STANDARD/PREMIUM) when reading old RevenueCat values.
- [ ] 5. Corporate linkage must **never cancel** the user's Apple/Google subscription.
- [ ] 6. Add flags: (a) corporate access just became active, so iOS/Android can show a clear notice that access is now covered by the organization; (b) personal subscription is still active, so the app can send the user to Apple/Google subscription management to avoid an unnecessary renewal.
- [ ] 7. Fallback: when Corporate ends (expiry, offboarding, suspension), the resolver returns the personal package if still active, otherwise Free/Base.
- [ ] 8. Add WORK to the activity taxonomy, with package eligibility and optional organization include/exclude rules in metadata.
- [ ] 9. Write tests for every combination: corporate only, personal only, both, neither, and corporate ending.

**Flow – Corporate access ends**

1. Contract expires or member is offboarded.
2. Membership becomes expired/deactivated and the seat is released.
3. Next profile refresh calls AccessResolver.
4. Resolver returns personal subscription (if active) or Free/Base.

> **Done when:** For any user, the resolver returns one correct access object and all fallback tests pass.

---

## B6 · Mobile APIs

**Goal:** Give iOS and Android the endpoints they need. After this phase, mobile work can start.

**Start after:** B5

### Steps

- [ ] 1. Extend register / login / profile responses with the access object and an organization summary.
- [ ] 2. Endpoint: corporate eligibility by email domain.
- [ ] 3. Endpoint: redeem organization code.
- [ ] 4. Endpoint: current Corporate membership.
- [ ] 5. Endpoint: accept invitation.
- [ ] 6. Return stable machine-readable error codes (example list below).
- [ ] 7. Add rate limiting on eligibility, code redemption and login.
- [ ] 8. Write the API contract (requests, responses, error codes) and give it to the iOS and Android developers. Keep the APIs **client-neutral** so a future Desktop client can use them too.

| Error code (**Suggestion**) | Meaning |
|---|---|
| DOMAIN_NOT_RECOGNIZED | Email domain is not linked to any organization. |
| INVALID_CODE | Code does not exist or is disabled. |
| CODE_EXPIRED / CODE_LIMIT_REACHED | Code date or use limit is over. |
| SEAT_LIMIT_REACHED | No free seats in the organization. |
| ORGANIZATION_SUSPENDED / CONTRACT_EXPIRED | Organization cannot accept members now. |
| ALREADY_MEMBER_OF_OTHER_ORGANIZATION | User already has one active organization. |

> **Done when:** The API contract is shared and every endpoint works on Staging.

---

## B7 · Admin APIs

**Goal:** APIs for the Pivot Super Admin panel and the Corporate Admin portal.

**Start after:** B6 (can run in parallel with mobile work)

### Steps – Pivot Super Admin APIs

- [ ] 1. Organizations: create / edit / suspend / renew; type, package, contract, seat limit.
- [ ] 2. Domains and organization codes.
- [ ] 3. Corporate admins and roles.
- [ ] 4. Members: view / deactivate / reactivate.
- [ ] 5. Custom Corporate Package entitlements.
- [ ] 6. Reporting/privacy settings.
- [ ] 7. Audit history.

### Steps – Corporate Admin APIs (own organization only)

- [ ] 8. Overview: contract and seat utilization.
- [ ] 9. Member list and activation status.
- [ ] 10. Employee invitations.
- [ ] 11. Organization access codes.
- [ ] 12. Approved domains (only if the permission policy allows).
- [ ] 13. Admin/analyst role management.
- [ ] 14. Audit history within allowed scope.

> **Check in every endpoint**
> Policy check + tenant scope + audit log entry for every change.

> **Done when:** All admin endpoints pass the RBAC and tenant-isolation tests.

---

## B8 · Analytics, Privacy and Reporting

**Goal:** Make group reports for employers. Corporate reporting is a first-class Phase 2 requirement and must be based on **aggregate event processing**. Never show one person's private data.

**Start after:** B7

### Metric definitions

| Metric | Definition / source |
|---|---|
| Seat utilization | Active memberships / licensed seats. |
| WAU / activation | Aggregate active and activated employee counts. |
| Reclaimed Focus Time | Aggregate reclaimed/focus minutes recorded by Pivot. |
| Monotasking Blocks | Count and duration of qualifying uninterrupted focus sessions. |
| Context-Switch Prevention | Aggregate blocked-app access attempts during active focus/blocking windows. |
| Challenge Acceptance | Aggregate count/rate of users choosing a Pivot challenge/micro-break. |
| Habit Consistency | Aggregate streak retention/consistency over time. |
| Organizational Energy Index | Anonymous aggregate mood/energy score; never individual mood. |
| Baseline vs Live | Comparison between locked pre-Pivot baseline metrics and current rolling metrics. |
| Estimated Productive Value | Reclaimed focus hours × organization-configured blended hourly labour rate. |

> Financial output must be described as an **estimate of preserved/reclaimed productive capacity**, not guaranteed realized savings.

### Mood, micro-pulses and HRIS

Client feedback allows anonymous workforce mood trends but forbids individual mood visibility.

| Area | Rule |
|---|---|
| Mood | Employers may see aggregate daily/weekly mood/energy graphs when the privacy threshold is met. Individual mood records are never shown. |
| Micro-pulses | Optional one-click questions after focus sessions can be aggregated by organization/cohort. |
| Quarterly surveys | Store/import aggregate survey results or organization-approved question responses. |
| HRIS | Optional future integration with Workday / BambooHR / Rippling, or file import, for aggregate absence, retention and survey metrics. |
| Correlation | Show correlation/trend analysis only at permitted cohort level. Never generate employee-level "risk scores". |

### Privacy and k-anonymity

| Employers CAN see | Employers CANNOT see |
|---|---|
| Seat allocation/utilization | Specific apps, websites or URLs used by an individual employee |
| WAU / participation rates | Timestamped individual device activity or personal schedules |
| Aggregate reclaimed focus hours | Personal reflections/journals |
| Aggregate challenge completion/adoption | Raw individual screen-time/device telemetry |
| Aggregate feature adoption | Individual mood history |
| Aggregate mood/energy graph when threshold is met | Individual challenge history |

### Steps – data pipeline

- [ ] 1. Confirm which existing events feed each metric (focus sessions, blocked-access attempts, challenges, streaks, mood).
- [ ] 2. Async job: **daily metric aggregation** per organization into organization_daily_metrics.
- [ ] 3. Calculate all metrics in the definition table above.
- [ ] 4. Baseline: capture or import a defined pre-Pivot baseline period before/at pilot activation.
- [ ] 5. **Lock** the approved baseline (locked_at), so later dashboards compare against a stable reference.
- [ ] 6. Generate live weekly/monthly organization rollups with the same metric definitions. Return both absolute change and percentage change to the Corporate Dashboard.
- [ ] 7. Allow report periods without exposing individual employee records.
- [ ] 8. Estimated productive value = reclaimed focus hours × organization's blended hourly labour rate. Label it as an **estimate**.
- [ ] 9. Micro-pulse: save answers, then aggregate into organization_pulse_metrics by organization/cohort.
- [ ] 10. Quarterly surveys: store/import aggregate results or approved question responses.
- [ ] 11. (Optional, later) HRIS import into organization_hr_metrics (Workday / BambooHR / Rippling or file import).

### Steps – privacy (always server-side)

- [ ] 12. Minimum reportable cohort = **20 active users** by default. It can change only by approved Pivot policy (store as config).
- [ ] 13. If a team/department cohort is below the threshold, roll its data into the larger parent organization cohort.
- [ ] 14. Mood: only aggregate graphs when the threshold is met. No individual mood ever.
- [ ] 15. Never return anything from the "CANNOT see" column above.
- [ ] 16. No employee-level turnover/burnout risk scores.
- [ ] 17. Async job: **data retention**. Keep aggregate reporting data for a configurable 12–24 month rolling window, then delete it.
- [ ] 18. On offboarding: sever the corporate membership link; historic aggregate contribution stays anonymous.
- [ ] 19. Privacy Nutrition Label endpoint: tells employees exactly what employers can and cannot see (for the mobile apps).

### Steps – reporting endpoints

| Endpoint group | Purpose |
|---|---|
| /corporate-admin/analytics/overview | Seat, activation, WAU and program engagement. |
| /corporate-admin/analytics/productivity | Focus, reclaimed time, monotasking, context-switch prevention and ROI estimate. |
| /corporate-admin/analytics/baseline | Pre-Pivot baseline vs live progress. |
| /corporate-admin/analytics/mood | Anonymous mood/energy trend with privacy threshold enforcement. |
| /corporate-admin/analytics/challenges | Aggregate challenge/WORK-category participation and completion. |
| /corporate-admin/analytics/hr | Optional approved HRIS aggregate correlation. |

- [ ] 20. Build the endpoints above. All analytics endpoints must enforce tenant scope and the privacy threshold **server-side**. Never trust the frontend to hide restricted data.
- [ ] 21. Add organization analytics summaries for Pivot Super Admin.

> **Done when:** Reports show correct group numbers, small groups are rolled up, and no endpoint returns personal data.

---

## B9 · SSO and SCIM

**Goal:** Company login and automatic member add/remove. First SSO providers (client approved): **Microsoft Entra ID** and **Google Workspace**. Okta / generic OIDC / SAML can follow.

**Start after:** B8 (needs B4 seats and B2 audit)

### Requirements

| Capability | Requirement |
|---|---|
| SSO discovery | Resolve organization from verified domain and decide whether enterprise login is enforced. |
| Identity linking | Use stable external subject/NameID, not email alone, as the permanent identity key. |
| SCIM users | Provision, update, suspend/deactivate and query corporate members. |
| Offboarding | Deactivate corporate membership and release seat. Do not delete personal Pivot data automatically. |
| Audit | Record SSO settings, provisioning/deprovisioning and admin changes. |

### Steps – SSO

- [ ] 1. Store provider settings in organization_identity_providers; **encrypt** the client secret.
- [ ] 2. SSO discovery endpoint: email domain → organization → is enterprise login enforced?
- [ ] 3. Microsoft Entra ID login + callback.
- [ ] 4. Google Workspace login + callback.
- [ ] 5. Link identity by stable **external_subject** (not email) in user_identity_links.
- [ ] 6. Enforcement policy per organization (is_enforced).
- [ ] 7. Audit SSO settings changes.

**Flow – SSO login**

1. Resolve organization from domain.
2. Redirect to the configured provider.
3. Validate the identity response.
4. Find user by external_subject, or link/create the Pivot user.
5. Allocate membership/seat.
6. Issue the Pivot (Sanctum) session.

### Steps – SCIM

- [ ] 8. SCIM tokens: store only the hash; support expiry and revoke.
- [ ] 9. SCIM Users endpoints: provision (create), query, update, suspend, deactivate.
- [ ] 10. Deactivate = release the seat; never delete personal Pivot data automatically.
- [ ] 11. Audit all provisioning and deprovisioning.
- [ ] 12. Admin settings APIs for SSO/SCIM (Super Admin and approved Corporate Admin).
- [ ] 13. Later, if needed: Okta / generic OIDC / SAML.

> **Done when:** A test user logs in with Entra and Google, and SCIM deactivation frees the seat.

---

## B10 · Security and Production Readiness

**Goal:** Make the backend safe for real companies.

**Start after:** Can run in parallel from B6. Must finish before B11.

### Steps

- [ ] 1. Confirm separate Development, Staging and Production environments and databases (from B0).
- [ ] 2. Confirm CI/CD gives repeatable backend deployments (from B0).
- [ ] 3. HTTPS/WSS/TLS everywhere in production. Remove any cleartext realtime/storage endpoints and hardcoded production endpoints.
- [ ] 4. Encrypt the database and backups at rest.
- [ ] 5. MFA for Pivot staff and privileged administrators.
- [ ] 6. Rate limiting on code redemption, eligibility, login, SSO and admin APIs.
- [ ] 7. Check: redemption/SCIM tokens are hashed, SSO secrets are encrypted.
- [ ] 8. Automated backups + a tested restore process.
- [ ] 9. Central error monitoring and health/uptime monitoring are live.
- [ ] 10. Data deletion/export tooling, with auditable privacy operations.

> **Done when:** Security review passes and a restore test succeeded.

---

## B11 · Backend QA, Pilot and Release

**Goal:** Full backend test, then pilot organizations, then production.

**Start after:** B1–B10

### Steps – Backend QA

- [ ] 1. Domain normalization and organization resolution.
- [ ] 2. Contract active / suspended / expired states.
- [ ] 3. Concurrent seat allocation cannot pass the seat limit.
- [ ] 4. Code expiry / use limit / disable / idempotency.
- [ ] 5. Membership activation / suspension / offboarding / restore.
- [ ] 6. Corporate → Personal → Free/Base fallback.
- [ ] 7. Tenant isolation on all admin and reporting APIs.
- [ ] 8. RBAC permission matrix.
- [ ] 9. Privacy threshold enforcement.
- [ ] 10. Baseline vs live calculations.
- [ ] 11. Mood aggregation without individual exposure.
- [ ] 12. SSO identity linking.
- [ ] 13. SCIM deprovisioning and seat release.

### Pilot and rollout stages

| Stage | Target |
|---|---|
| Internal test | Pivot test organization with full seat/domain/code/expiry testing. |
| Initial Corporate pilot | 2–3 organizations recommended. |
| Minimum useful cohort | 20–30 active users per organization for functional/privacy testing. |
| Preferred analytics cohort | 50+ active users per organization for more useful trend analysis. |
| Enterprise release | After tenant isolation, privacy, SSO/SCIM, audit, monitoring and security QA pass. |

> **Timing note:** Sales discussions and pilot recruitment can start **now**. But employee production onboarding should start **only after** the Corporate licensing/mobile integration is built and validated.

### Steps – Pilot and release

- [ ] 14. Full end-to-end internal test on Staging with the Pivot test organization (seat, domain, code, expiry).
- [ ] 15. Configure 2–3 pilot organizations: domains, package, seats, admins, reporting permissions.
- [ ] 16. Collect and lock each pilot's baseline before live measurement.
- [ ] 17. Pilot size: at least 20–30 active users each; 50+ is better for trends.
- [ ] 18. Check monitoring, backups and the rollback plan.
- [ ] 19. Confirm the corporate licensing/mobile integration is validated before onboarding real employees.
- [ ] 20. Approve the enterprise production rollout.

> **Done when:** All critical tests pass and the production rollout is approved.

---

## Phase 3 Readiness

The Phase 2 backend must expose organization membership, entitlements, reporting and identity through **client-neutral APIs**, so a future Desktop client can reuse the same backend. No Desktop-specific tables or sync features are needed in Phase 2 unless separately approved. Phase 3 Desktop starts only after these APIs are stable.

---

## Final Backend Definition of Done

- [ ] Organizations can be configured as Corporate / Education / Healthcare / Government with contracts, seats, domains and Standard / Premium / Custom package access.
- [ ] Employees can join via work email, organization code or approved SSO.
- [ ] Seat allocation is atomic and tenant-safe.
- [ ] The backend resolves one authoritative effective access state using Standard / Premium / Custom packages.
- [ ] Corporate access safely overrides, and later falls back to personal subscription or free access.
- [ ] Pivot keeps global control, while organization admins are restricted to their own tenant.
- [ ] Aggregate baseline/live productivity, focus, challenge and mood reporting works with privacy thresholds.
- [ ] The Privacy Nutrition Label data contract is available to the mobile apps.
- [ ] SSO/SCIM, audit history, offboarding and contract expiry are tested.
- [ ] Staging, monitoring, encrypted transport, backups and enterprise QA are complete before general rollout.
