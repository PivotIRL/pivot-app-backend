# Lifetime purchase followed by renewal cancellation

The updated Android app asks for consent, reads the existing subscription token through BillingClient.queryPurchasesAsync(SUBS), and submits Purchase.purchaseToken to the authenticated backend. A GPA order ID is not a purchase token. Users do not need to copy tokens or provide credentials.

The backend matches Google's token response to a receipt already owned by this account (order lineage, product, base plan and sandbox/production). It encrypts the token using Laravel's APP_KEY. Only a completed checkout with a verified, non-revoked Lifetime receipt authorizes cancellation. The Lifetime sheet is a separate one-time purchase at its displayed full price; previous charges are not refunded. Failed, pending or cancelled purchases never trigger cancellation.

The scheduled job stops renewal using subscriptionsv2.cancel with USER_REQUESTED_STOP_RENEWALS, then verifies autoRenewEnabled=false. Transient errors persist with backoff and can retry after a process restart. Tokens are deleted after confirmed cancellation. The app displays a pending notice until cancellation is confirmed; Google Play management remains available. Cancellation is asynchronous and is not guaranteed to finish before an imminent renewal charge.

Supported: one Google Play recurring subscription to the same or higher package's Lifetime product. Multiple independent subscriptions and installment commitments are rejected before payment. Sandbox tokens must match the account's configured environment. Existing manually cancelled subscriptions continue to use the previous flow. Promotional grants are not cancellable Play subscriptions.

## Server setup

1. Enable the Google Play Android Developer API in the credential's Google Cloud project. Create a service account and grant it access to this app in Play Console, including View financial data and Manage orders and subscriptions. Generate a JSON key and store it outside the repository and public directory, readable only by the PHP/queue service account. Do not use google-services.json from the Android app.
2. Add these server environment settings (replace the credential path with the actual private path):

```ini
MEMBERSHIP_AUTO_CANCEL_RENEWAL=true
GOOGLE_PLAY_SERVICE_ACCOUNT_FILE=/etc/pivot/google-play-service-account.json
GOOGLE_PLAY_PACKAGE_NAME=com.pivotirl.mobile
```

Keep the existing REVENUECAT_STORE_ENVIRONMENT and REVENUECAT_SANDBOX_USER_IDS settings. The Google credential is server-only and works with the authorized app's sandbox and production purchases. Leave MEMBERSHIP_AUTO_CANCEL_RENEWAL=false until the credential, scheduler and worker are ready.

3. After deploying the backend code, run from /var/www/html/pivot_app_backend:

```sh
php artisan migrate --force
php artisan config:cache
php artisan view:cache
php artisan queue:restart
```

4. Ensure the existing scheduler invokes `php artisan schedule:run` every minute. For example, the PHP service user's crontab may contain (do not duplicate an existing scheduler entry):

```cron
* * * * * cd /var/www/html/pivot_app_backend && php artisan schedule:run >> /dev/null 2>&1
```

For an asynchronous queue connection, keep `php artisan queue:work --timeout=90` running under your process manager, with the queue connection retry_after greater than 90 seconds. For the sync queue, scheduled jobs execute in the scheduler process. The command `php artisan membership:cancel-lifetime-renewals` dispatches eligible cancellations and can cause real subscription cancellations; use only after setup is complete.

5. Release the updated Android app. Validate on a designated sandbox account: cancel the Lifetime payment sheet (monthly remains renewing), complete a Lifetime purchase (access becomes Lifetime), then confirm the cancellation row reaches completed and Play renewal stops. Also test a temporary Google API failure and recovery. Mocked automated tests do not replace this store test.

For operational monitoring, inspect only id, user_id, status, attempts, next_attempt_at and completed_at from lifetime_renewal_cancellations. Investigate rows remaining retrying; do not log decrypted tokens or enable request-body tracing for the lifetime-renewal endpoint. Keep APP_KEY stable so pending encrypted tokens remain readable. Consent rows for abandoned checkouts do not authorize cancellation.

References: [Google Billing integration](https://developer.android.com/google/play/billing/integrate), [service-account setup](https://developers.google.com/android-publisher/getting_started), [cancellation API](https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.subscriptionsv2/cancel).
