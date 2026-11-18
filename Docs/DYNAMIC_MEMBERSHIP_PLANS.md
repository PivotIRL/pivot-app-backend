# Dynamic membership plans

## Admin workflow

Open **Subscriptions → Manage membership features** (`/dashboard/membership-plans`) while signed into the existing admin dashboard. Edit Standard or Premium and save that plan. Changes apply to existing and new members, not just future purchases. Each save increments that plan's revision; an outdated form cannot overwrite a newer save.

Editable fields: daily challenges, category count, blocked apps, blocking hours/day, intervals, blocked-app usage minutes/day, team connections, Hardcore mode, and full research (including available videos). Empty category/app/interval fields mean unlimited. Zero is supported for daily challenges, hours, usage minutes and team connections. Micro-Movement remains mandatory for a finite category selection. Existing legacy all-category benefits remain grandfathered.

Store pricing, products, trial duration and RevenueCat hosted paywall copy are separate from these limits. Edit hosted paywall text in RevenueCat if it advertises numeric benefits; it cannot automatically read this API. The Android native plan cards use the API values.

The older backend's free-account allowance remains separate in `config/membership.php`; editing a paid plan does not expand that legacy allowance. Current Android clients still require verified active access before granting membership features.

## Deployment

1. Deploy the backend with `2026_11_14_000001_create_membership_plans.php` and run the normal migration process. It seeds the existing Standard/Premium values once; subsequent deployments do not reset admin edits.
2. Deploy the updated Android app. No migration of its existing user database is required: the plan catalog uses a separate Room database containing no account grants or tokens.
3. Update iOS using the contract below. No iOS source is present in this workspace, so the iOS client implementation is not included.

Apply the database migration before serving the new backend code. Do not roll back/drop the plan table while that code is serving traffic. This work does not deploy, merge or change the production host.

## Mobile API contract (Android and iOS)

Authenticated `GET /api/v1/membership/plans` returns:

```json
{
  "schema_version": 1,
  "plans": {
    "standard": {
      "revision": 1,
      "limits": {
        "daily_challenges": 3,
        "category_count": 5,
        "blocked_apps": 10,
        "scheduled_blocking_hours": 12,
        "blocking_intervals": 8,
        "blocked_app_usage_minutes": 15,
        "hardcore": false,
        "team_connections": 5,
        "full_research": false
      }
    },
    "premium": {
      "revision": 1,
      "limits": {
        "daily_challenges": 5,
        "category_count": null,
        "blocked_apps": null,
        "scheduled_blocking_hours": 24,
        "blocking_intervals": null,
        "blocked_app_usage_minutes": 30,
        "hardcore": true,
        "team_connections": 10,
        "full_research": true
      }
    }
  }
}
```

The same object is included as `plan_catalog` in `GET /api/v1/access` and other access-snapshot responses. `policy_version` on account access and `schema_version` on the catalog describe wire formats; changing a feature value increments `revision`, not the schema version.

Validate the entire response before replacing local data. Both known plans must be present, with positive revisions and valid limits. Missing fields are not equivalent to explicit `null`. Only the three nullable limits above allow unlimited. Cache the complete validated catalog transactionally; merge revisions per plan so late requests cannot roll back newer values. Keep the last valid cache on network/validation failure. Unknown catalog schemas require a client update.

Configuration does not grant membership. Use authenticated `/access` status, expiry, selected categories and account-specific `limits` for the user's effective access. Never infer a purchase from the cached catalog. The existing server-side reservation and team checks enforce the saved limits, and category/research checks use the same configuration.

## Android behavior

The `membership_catalog` Room database stores the validated catalog as JSON. It is global plan data and may survive account changes; no account entitlements or credentials are stored in it. Account access continues to be verified independently. A RevenueCat entitlement identifies a package, but its limits come from backend access or the downloaded catalog; an unavailable catalog does not invent feature access.

Catalog refresh accompanies existing account access/reconciliation calls on feature entry/resume and is retried every 15 minutes while the repository is running. A process restart first loads the cache. Offline devices retain downloaded configuration until a successful refresh; server operations always apply current backend rules. Native plan cards render localized labels and dynamic quantities. App store/RevenueCat pricing continues to come from the store.

Changing a finite category count can require existing members to select categories again; old selections/history are preserved. Lowering limits does not delete accounts, completed history, or already reserved challenges. Existing durable challenge reservations retain their original reservation semantics.

## Validation — 2026-11-14

65 backend tests passed with 434 assertions against the isolated PostgreSQL test database. Android: 198 unit tests passed; release lint passed with zero errors (413 warnings, 9 hints); optimized unsigned release APK built successfully. No live migration, admin edit, purchase, device installation, merge or deployment was performed for this change. The temporary database server was stopped after testing.
