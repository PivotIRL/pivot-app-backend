# User model compatibility

`User` is the canonical mobile account model. `Users` remains a subclass so existing
Sanctum tokens and serialized jobs containing `App\Models\Users` still resolve.
Both names access the same `users` rows and relationships. Do not remove the legacy
class or rewrite account IDs as part of deployment.

## Existing accounts and sessions

- No database migration, password reset, or forced sign-in is required.
- Existing password hashes are read unchanged; new email/beta logins issue `User` tokens.
- Historical `Users` tokens retain their original owner type and remain valid.
- Profile, hobby, challenge, membership, and organization relationships keep `user_id`.
- `subscription` returns the active subscription consistently for both model names.
  `latestSubscription` and `subscriptions` include inactive records for administration.
- Logout retains the existing all-mobile-sessions intent, now across both owner types.
  Password reset and account deletion also clean up both mobile token types.
  Revocation is scoped by account ID and mobile owner types, excluding dashboard tokens
  and other accounts. Account deletion still removes the underlying account as before.

## Deployment checks

Deploy the model, authentication repository/service, revocation call sites, and admin
subscription relation changes together. Restart long-running application/queue workers
through the normal deployment procedure so they load the updated classes.

Run `MobileUserCompatibilityTest`, `ChallengeReservationControllerTest`,
`GroupChallengeControllerTest`, and the organization authentication/onboarding tests
against a disposable PostgreSQL database. The repository's default PHPUnit database
name is `pivot`; explicitly override the connection and database before using
`RefreshDatabase` tests.

The compatibility tests use both real Sanctum token owner types and pre-existing
password rows. External Google/Apple identity verification still requires the normal
staging smoke test; repository identity lookup is tested locally without external calls.

## Validation on 2026-11-10

- Related membership, engagement, organization authentication, and onboarding run:
  183 tests passed, 1,034 assertions.
- Final compatibility and both corporate token-owner cases: 16 tests passed,
  139 assertions. Includes beta device restrictions and a completed group challenge
  with one historical token and one canonical token.
- Changed PHP files passed syntax checks; `git diff --check` passed.
- All database tests used a temporary local PostgreSQL 17 cluster and a disposable
  database, separate from the configured application database.

The broader existing challenge suites have four failures reproduced against both
the modified application and unchanged `staging` commit `d4cfda2` (29 baseline tests,
97 assertions). They are outside the user-model fix and remain release follow-ups:

1. Reservation quota test expects rejection after two holds; current free allowance is three.
2. Reservation replay test expects HTTP 200; the controller returns 201 while status is reserved.
3. Leaderboard test expects `host_id`; the response contains `user_id`.
4. Emotion-count request routes to an undefined `EmotionLogsController::show()` method.

No production deployment or merge was performed. Keep the normal staging smoke test
for real Google/Apple sign-in and an already-installed mobile client before rollout.
