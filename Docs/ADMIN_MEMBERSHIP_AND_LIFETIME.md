# Verified admin membership and lifetime conversion

The user detail card reads MembershipAccessService rather than the legacy subscriptions tier. It displays the current effective package, payment period, status, environment, source and UTC expiry. It uses the latest backend record; viewing the page does not call RevenueCat.

Lifetime checkout supports consented cancellation after verified payment when the automatic cancellation service is configured. See [setup and deployment](LIFETIME_AUTO_CANCELLATION.md), including the new migration. Otherwise the API requires verified cancellation of recurring renewal and returns code renewal_cancellation_required. Android handles this code with an instruction to cancel renewal in Google Play, refresh membership and select Lifetime again.

Reconciliation retries optimistic concurrency conflicts up to three fresh reads. Each attempt rechecks versions under the user lock; upstream failures are not retried as conflicts and stale results are never applied.

Deploy the backend changes and refresh compiled views with php artisan view:cache. Install the updated Android build. Follow the automatic cancellation deployment guide for its migration and credentials. No production deploy or device installation was performed.

Backend validation: 8 integration tests, 46 assertions passed in an isolated PostgreSQL database, including rendered admin detail with stale legacy tier, expiry, monthly-to-lifetime cancellation guard and successful quote, concurrency refetch, and environment routing.
