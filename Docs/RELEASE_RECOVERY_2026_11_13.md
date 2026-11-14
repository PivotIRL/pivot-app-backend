# Recovery fixes — 2026-11-13

Backend: `staging`. Frontend: `Feat/New-Subscription`. Changes are local and uncommitted; no merge or deployment was performed.

## Implemented

- **Research:** daily articles and saved articles expose the same integer research-article ID. Premium content gating remains enforced. Bookmark creation, library lookup, and removal are exercised together.
- **Reservations:** new holds carry `durable_hold=true`. Their slot remains reserved until explicit abandon or idempotent completion, including long pauses and offline completion. `expires_at` remains in the response for older clients; new clients use the durable flag. Old clients retain their existing timer behavior.
- **Older expired holds:** revalidate available capacity under the account lock before promoting a hold. A slot already consumed/reserved elsewhere returns a conflict; it never steals another device's credit. Completion preserves access granted at reservation time, while resuming still checks current access.
- **Lost/reinstalled-device holds:** an account-scoped outstanding-hold endpoint and frontend cancellation action allow users to release orphaned holds. A translated confirmation explicitly says cancellation affects all devices. Discovery returns up to 100 outstanding holds per refresh; cancelling them exposes any further holds.
- **Legacy pack balances:** archive the original local snapshot on the backend, match the verified transaction to the authenticated account, then publish its current server balance. Never subtract a balance difference or create credits from a device claim. Stale local product labels do not override verified receipt metadata. Replays return the latest balance rather than restoring the first reconciliation's balance.
- **Missing receipts:** retain the local pending record and return `receipt_required`; other verified server packs remain available. Reconciliation succeeds after the verified transaction arrives. Receipt IDs are never guessed from product names. Historical records cannot prove whether ambiguous local-only usage was already applied: the policy preserves the verified server balance and does not retrospectively deduct that ambiguous usage. This can forgive previously unreported device-only consumption.
- **Checkout recovery:** discover account-owned purchase/change holds after lost keys or reinstall, verify RevenueCat before retiring an unpaid hold, and permit another selection only after confirmation. Completed/scheduled purchases cannot be cancelled through this endpoint. Retired quotes cannot be replayed to reopen checkout. Late verified receipts can still settle a retired attempt.
- **Frontend checkout:** same-selection retries recover/reuse the hold; changed selections retire it after verification; both new purchases and package changes recover old server holds. Membership UI exposes cancellation for unfinished checkouts. Completed local attempt caches are cleared after server-confirmed recovery.

## Backend migration and rollout order

Apply `database/migrations/2026_11_13_000001_add_durable_challenge_holds_and_legacy_reconciliation.php` with the backend deployment before rolling out this frontend. It adds a boolean to the existing reservation table and creates an audit table; it does not rewrite user identities, login credentials, history, or pack balances.

New routes:

- `GET /api/v1/challenge-reservations`
- `POST /api/v1/challenge-pack/reconcile-legacy`
- `POST /api/v1/membership/checkout-recovery`
- `POST /api/v1/membership/checkouts/cancel`

All require the authenticated account. Existing reserve/resume/complete/abandon routes honor durable holds. Account locks precede pack/reservation mutation; store HTTP verification runs outside the cancellation transaction, followed by a locked terminal-state recheck. Outstanding challenge titles are loaded in a batch.

Do not roll back the durable-hold schema or old quota logic while durable holds remain active: the old expiry logic could release their slots prematurely. Reconcile outstanding holds before such a rollback. Cancelling a checkout retires the application's quote; it is not a store refund or cancellation of an already-paid subscription.

## Validation

- Backend regression run before the last refinements: **114 tests passed, 758 assertions** (membership, reservation, account compatibility, recovery).
- Final targeted backend run after receipt-metadata and batched-discovery refinements: **28 tests passed**, including all 11 recovery integration tests plus reservation and existing-user compatibility tests. The broader repeat was interrupted after progress appeared stalled; its buffered output subsequently showed 66 tests had progressed without a reported failure. It was replaced by this completed diagnostic targeted run.
- Frontend unit suite: **193 tests passed**, zero failures/errors/skips. Includes lost-response keys, old-hold discovery, cancellation verification failure, changed selections, expired holds, and completed/scheduled payment protection.
- Android instrumentation sources compile. The preceding phase's three emulator migration tests passed; they were not rerun in this follow-up, which adds no new Room schema change.
- Backend tests used an isolated PostgreSQL 17 cluster on localhost port 55439 and database `recovery_integration`, not the application database. The temporary cluster was stopped after validation. Store verification in endpoint tests is mocked; existing membership verification regressions were included separately.
- Final release build/lint: **BUILD SUCCESSFUL** for `:app:testDebugUnitTest :app:lintRelease :app:assembleRelease` (11m 3s). Release lint: **0 errors**, 403 warnings and 9 hints. Optimized unsigned APK built; staging host remains configured. Full translation audit: 862 English resources, 21 locales, no missing entries or format mismatches.
- Logs: `storage/framework/testing/recovery-final-results.log` in backend; `build/recovery-final-validation.log` in frontend.

The original reservation tests had two stale expectations (two free slots and HTTP 200 for a reserved replay). They now match the existing contract: three free slots and HTTP 201 while the reservation remains reserved.

## Live acceptance status

Live acceptance is **not complete**. The user explicitly asked to **skip ADB/physical-device testing** because no mobile device is available; those cases are deferred, not failures. The supplied account is `rahmyathasamrd@gmail.com`; no password, account reset, purchase, or live data modification was performed.

Read-only staging preflight on 2026-11-13:

- Existing `/api/v1/access`: HTTP 401 without authentication, confirming the staging API is reachable.
- New checkout-recovery and legacy-pack reconciliation routes: HTTP 404; these changes are not deployed there.
- ADB: no connected Android device before testing.
- Repository contains no deployment workflow or staging deployment instructions.

For a future live acceptance pass, deploy this reviewed backend and migration to staging, then use the matching frontend on two test devices with a signed-in Google Play license-test session for the supplied account plus a separate clean test account for registration. No device testing or staging deployment is being performed in this task. Verify the staging store environment and license-test purchase sheet before any purchase; do not use real-money purchases as a substitute.

Remaining live cases: existing installed-user login/data preservation; new-user signup/login; bookmark round trip; purchased pack consumption and restore; checkout cancellation/change and delayed receipt; pause beyond 30 minutes; offline completion followed by reconnect; duplicate completion from two devices; reinstall/orphan-hold cancellation. Local tests cover these contracts with fixtures/time travel and mocked store verification, not real Google/RevenueCat purchase acceptance.

Realtime TLS and production API host configuration remain outside this change, as previously deferred. No claim of production readiness is made until deployment and live acceptance are complete.

## Follow-up fixes and deployment check

Post-deployment recheck (2026-11-13, after user confirmation): the new reservation-list, legacy-reconciliation and checkout recovery/cancellation routes now return 401 without authentication instead of the earlier 404/405. Article, resume/complete and authenticated emotion-count routes also return 401. Public leaderboard returns 200 with both canonical and legacy identity fields; all 311 returned rows have matching aliases. These checks establish route availability and the public leaderboard contract, not authenticated recovery behavior or migration correctness. Authenticated acceptance remains pending; no live account mutations were performed.

Final follow-up validation: **62 tests passed, 367 assertions** (`ReleaseRecoveryTest`, `GroupChallengeControllerTest`, `ChallengeReservationControllerTest`, `RevenueCatMembershipFetchTest`, `MobileUserCompatibilityTest`). Log: `storage/framework/testing/followup-recovery-final.log`. The first attempt failed because the disposable database was unavailable; after startup recovery, the complete rerun passed. No application database was used. PHP syntax checks and `git diff --check` passed.

- Leaderboard now includes both canonical `user_id`/`user_name` and legacy `host_id`/`host_name`. Both identify the ranked user, including non-host participants. Contract tests assert both pairs.
- `GET /api/v1/emotion-log/counts` explicitly calls `counts`. The emotion resource registers only its implemented `store` action, eliminating routes to undefined methods. Existing public `/leaderboard/emotion` remains supported.
- Unmatched legacy packs now trigger an account-scoped RevenueCat purchases lookup outside database locks. The additive `recovery_status` reports `verification_unavailable`, `receipt_not_found`, or `receipt_verified_balance_review_required`; existing `resolution=receipt_required` remains compatible with the frontend. Matched server balances report `resolved` and require no external lookup.
- Receipt verification requires the exact store transaction, configured environment, owned/purchased status, and an allowed pack SKU resolved from RevenueCat product metadata. It never guesses a receipt from a product label. Reference: https://www.revenuecat.com/docs/api-v2/customer/resources
- This does **not** resolve evidence-free balances automatically. A verified purchase proves acquisition, not unused credits. Preserve the original local audit record; obtain authoritative purchase and usage/balance evidence before any manual balance repair. If no receipt exists, leave the record pending. Do not replay an old purchase webhook merely to recreate a full balance.

Unauthenticated staging recheck on 2026-11-13:

| Route | HTTP result |
| --- | --- |
| GET access, daily-article, profile/article-library | 401 |
| GET challenge-reservations | 405 |
| POST challenge-reservations/{test UUID}/resume and /complete | 401 |
| POST challenge-pack/reconcile-legacy | 404 |
| POST membership/checkout-recovery and membership/checkouts/cancel | 404 |
| GET emotion-log/counts | 401 |

401 proves only authentication is required; it does not prove the controller action or business flow works. No live account data was changed. The user will deploy and asked us to wait; deployment and authenticated acceptance remain pending their confirmation. ADB testing remains skipped by request.
