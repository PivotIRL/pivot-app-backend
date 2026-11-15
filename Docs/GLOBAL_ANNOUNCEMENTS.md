# Global in-app and push notifications

Admin navigation: **Notifications** (`/dashboard/notifications`). Choose Android, iOS, or Both; select in-app and/or push, enter a title and message, and choose 1–90 days availability. Publishing makes in-app messages visible immediately and queues push delivery to devices registered at publication time. A form request UUID prevents duplicate submission. Withdrawal hides the in-app message and cancels pending pushes. Already-sent or in-flight pushes cannot be recalled.

## Deploy

- Run migration `2026_11_15_000001_create_global_announcements.php` before exposing the new endpoints.
- Deploy the updated Android client. Old clients do not register the platform-specific device record or display these in-app messages. Legacy `users.fcm_token` values are deliberately not guessed as Android or iOS.
- Keep Laravel's scheduler running (`php artisan schedule:run` every minute). It invokes `notifications:send-global`; manual execution also works. Configure existing Firebase project credentials. iOS delivery through FCM additionally requires APNs configuration in Firebase and iOS notification permission.
- No actual announcement was published to live users during implementation. Tests mock Firebase.

The new device registry supports multiple devices per account and uniquely identifies tokens by SHA-256. Re-registering a token updates ownership/platform. Messages are public global campaign text, not private account information. Existing challenge/engagement token handling remains separate. Tokens without known platform registration do not receive these campaigns. New registrations after publication do not receive historical pushes, but can see unexpired in-app messages.

## API (Bearer authentication required)

`POST /api/v1/notification-devices`

```json
{"token":"<FCM registration token>","platform":"android"}
```

Use `ios` for an iOS FCM token, not a raw APNs token. Response: 204. Register after login, on token refresh, and when resuming the app.

`GET /api/v1/announcements?platform=android`

```json
{"data":[{"id":1,"title":"A fresh start","body":"Make time for a small win today."}]}
```

Returns up to 50 active unread messages for that platform, including Both. Query with `platform=ios` on iOS. Read records are account- and platform-scoped. Fetch on foreground and periodically while foregrounded. Text is plain text, not HTML.

`POST /api/v1/announcements/1/read`

```json
{"platform":"android"}
```

Response: 204; repeated acknowledgements are safe. Wrong-audience, expired and withdrawn messages return 404. Android allows dismissal offline, suppresses dismissed messages for the current session, and retries acknowledgements on refresh. A failed acknowledgement can reappear after a process restart. No forced update or non-dismissible gate is introduced.

Push data accompanies the FCM title/body:

```json
{"type":"GLOBAL_ANNOUNCEMENT","event_id":"announcement-1","announcement_id":"1"}
```

Android channel: `pivot_announcements`. Background notification payload display uses Firebase; foreground delivery uses the app helper. Tapping opens Pivot normally, where an active in-app copy is fetched when selected for that channel. Push-only messages do not create in-app inbox entries. No notification can guarantee display if OS permission is denied.

## Delivery results

`pending`, `processing`, `accepted`, `failed`, `cancelled` are shown in the dashboard. Accepted means FCM accepted the request, not device delivery/read. Claims are atomic so repeated commands do not send an accepted/failed item twice. Failed sends are not automatically retried; publish a new campaign deliberately if needed. An interrupted worker may leave processing rows with unknown delivery; do not blindly reset them, because the push may already have been accepted. Large audiences are processed in chunks; counts refer to registered devices, not unique people.

The admin form renders text escaped, uses admin authentication and CSRF protection, validates channel/audience/length, and restricts the UTF-8 title/body payload size. Messages currently have one administrator-provided language. iOS client implementation is not included because that source is absent.

## Validation — 2026-11-15

- 15 backend notification tests passed with 66 assertions, using the isolated PostgreSQL database and mocked Firebase. Covers platform targeting, read/account isolation, expiry, withdrawal, duplicate submissions, duplicate worker runs, failures and existing engagement behavior.
- 200 Android unit tests passed. Sentence tests verify dynamic quantities, unlimited features, feature switches and absence of the Micro-Movement mention. New sentence-style benefits apply to English; existing translated labels remain unchanged.
- Android release lint and optimized unsigned release build passed (`build/announcements-validation.log`). PHP syntax and whitespace checks passed.
- No device installation, live notification send, production deployment or merge performed. Actual Firebase delivery and on-device presentation remain live acceptance checks. The isolated test database was stopped after validation.
