# Required app updates (new client builds only)

Admin navigation: **Required App Updates**, `/dashboard/app-updates`. Separate Android and iOS policies provide an enable switch, minimum supported version, latest available version, message and official store URL. Policies start disabled. Publish the store release before raising the minimum; saving increments a revision and rejects stale admin forms.

Example: minimum `1.2.2`, latest `1.3.6`: version `1.2.1` is blocked, while `1.2.2`, `1.3.4` and `1.3.6` continue normally. Comparison is numerical, not alphabetical. Use three numeric components (`major.minor.patch`). The minimum is inclusive: setting it to `1.2.1` allows that version. The latest field is informational and does not force all users to update.

## Deployment

Run `2026_11_15_000002_create_app_update_policies.php`, deploy backend and updated Android client, then configure and enable the policy in admin. Current Android version is read from `BuildConfig.VERSION_NAME`; continue assigning valid three-part version names in future releases. Old builds without this client code are intentionally unaffected. The iOS API/admin policy is supplied, but no iOS client source exists in this workspace.

The API is public so update checks work before login:

`GET /api/v1/app-update-policy?platform=android` (or `ios`)

```json
{
  "schema_version": 1,
  "platform": "android",
  "revision": 2,
  "enabled": true,
  "minimum_version": "1.2.2",
  "latest_version": "1.3.6",
  "message": "Please update to access the latest features, improvements and more.",
  "store_url": "https://play.google.com/store/apps/details?id=com.pivotirl.mobile"
}
```

Android checks at initial startup, every resume and every minute while foregrounded. Initial checking displays a loading view (network timeout 12 seconds). When blocked, the main navigation and announcement host are not composed; only the message and Update app button are displayed. Back cannot bypass the gate, and login/deep links pass through the same gate. The button opens the configured official Play Store HTTPS URL. Returning from the store triggers a fresh check. Existing background timers/services are not erased or forcibly stopped by this UI gate.

Validated policies are cached by API host and revision. A known required update remains blocked offline; a newer admin disable/minimum change takes effect after a successful refresh. Without any valid policy, network/API failure preserves offline access rather than treating an outage as a mandatory update. An offline device cannot discover a new restriction until it reconnects. This is client UI enforcement, not an API security boundary or a push notification campaign.

The message is administrator-provided text. The default is the requested sentence; the Update app button has resources for all supported locales. The application version minimum is separate from the latest release and does not automatically change when a new app build is published. No live policy has been enabled as part of implementation.

## Validation — 2026-11-15

- Android: 205 unit tests passed, zero failures/errors/skips. Includes minimum-version boundaries, numeric comparison, disabled policies, malformed versions, unsupported store URLs and inconsistent latest versions.
- Release lint and optimized unsigned APK build passed (`build/app-update-policy-validation.log`). Existing staging API configuration remains unchanged.
- Backend: 2 integration tests passed with 21 assertions against the isolated PostgreSQL database; PHP syntax and repository whitespace checks passed.
- Temporary test database stopped. No device installation, live policy change, deployment or merge performed. On-device presentation and live store handoff remain acceptance checks. iOS requires implementation in its separate client project.
