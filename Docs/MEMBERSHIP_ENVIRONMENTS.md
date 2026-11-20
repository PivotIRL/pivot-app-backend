# Production and sandbox membership routing

Production supports real purchases and designated sandbox tester accounts. Each account uses one environment consistently for reconciliation, change quotes, cancellation, membership webhooks, and challenge-pack receipt handling. The app and webhook cannot choose the environment. Use dedicated test accounts; an allowlisted account ignores production receipts until removed from the list.

## Production server

Set in the server .env (96 is the backend user ID for the current test account):

```dotenv
REVENUECAT_STORE_ENVIRONMENT=production
REVENUECAT_SANDBOX_USER_IDS=96
```

Other accounts keep production verification. Add further numeric backend user IDs separated by commas. These are not emails or RevenueCat anonymous IDs.

## Staging server

```dotenv
REVENUECAT_STORE_ENVIRONMENT=sandbox
REVENUECAT_SANDBOX_USER_IDS=
```

Staging ignores production purchase events. Keep separate databases and verify that RevenueCat IDs identify the intended account in each backend.

## Deploy

Deploy the backend code, then run:

```bash
php -d pcre.jit=0 artisan migrate --force
php -d pcre.jit=0 artisan config:cache
php -d pcre.jit=0 artisan queue:restart
```

The additive migration records the environment of new/reconciled account access. Existing rows without an environment are treated as the server default until refreshed. Changing a test account's routing requires refreshing membership; access from a different recorded environment is not reused. Do not run migrate:fresh or reset subscriptions.

Configure the production RevenueCat webhook integration to deliver both production and sandbox events; the backend filters them per account. Staging should receive sandbox events. Keep existing webhook authentication. Both SDK and server must use the same RevenueCat project and customer mapping.

After deployment: refresh membership for user 96; the access response should show Standard Monthly from the store. Then request Premium Monthly and verify a replacement quote/payment sheet. This does not require a new purchase of Standard. Also verify a normal production account and staging sandbox account. No live deployment, environment changes, purchases, or webhook changes were performed by this code change.

## Validation

27 focused unit tests passed (180 assertions), plus 24 integration tests (156 assertions) on a disposable PostgreSQL instance. Coverage includes production and sandbox reconciliation, Standard Monthly to Premium Monthly quotes in all three routing modes, webhook acceptance/isolation, removal of tester access, and existing recovery/catalog behavior. The test database was separate from application databases.
