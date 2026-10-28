# Firebase live chat setup — plugin 0.3.0 and Android 0.1.4

The website widget, browser agent workspace and Android foreground chat now listen for Firebase Realtime Database change signals. WordPress saves each message first, publishes a signal immediately after commit, and serves the authorized message history. Firebase receives opaque random markers and timestamps, never the chat message, name, email, phone, conversation ID or internal note. Background notifications still use FCM and the existing cron worker.

## 1. Install both updates

Install [plugin 0.3.0](../downloads/wordpress/crystaltap-ai-chatbox-0.3.0.zip?raw=true) through WordPress → Plugins → Add New Plugin → Upload Plugin, replacing the current version. Keep one plugin copy active. Clear the website/page cache.

Install [Android 0.1.4](../downloads/android/CrystalTap-Agent-0.1.4.apk?raw=true) over your existing CrystalTap app **without uninstalling**. It uses the same signing certificate. Existing conversations, Firebase notification credentials and approved devices are retained. Older app versions continue to work through regular refresh.

Live updates are disabled until the following settings are complete. You can continue chatting during setup.

## 2. Publish the private database rules

In Firebase Console select **CrystalTap PH → Realtime Database → Rules**. Replace the rules for your new, otherwise unused database with [firebase-database.rules.json](../assets/firebase-database.rules.json), then click **Publish**. You can also download this file from the plugin's Realtime chat settings.

If another application already uses this database, merge its existing private rules carefully; a broader `.read: true` or `.write: true` on a parent overrides restrictions below it. Do not enable public/test-mode rules. The provided rules deny root/parent reads, all client writes and all reads except the authenticated client's own short-lived feed. No Anonymous or Email/Password sign-in provider needs to be enabled: WordPress issues Firebase **custom authentication tokens** after checking the existing visitor session or staff login.

## 3. Grant the existing service account database access

Your existing service-account file in **CrystalTap AI → Settings → Android Firebase notifications** must belong to `crystaltap-ph`, matching the database and registered web/Android apps. In Google Cloud Console for that project, open **IAM & Admin → IAM**, find that service account, and give it **Firebase Realtime Database Admin** for database publishing and cleanup. Retain its existing Firebase Messaging role. Do not grant broad Project Editor/Owner roles. Reuse the saved account; no additional private key is needed for this update.

The private signing credential stays encrypted in WordPress. Do not put it into the website JavaScript, mobile app, repository or chat. Keep the website server clock accurate and outbound verified HTTPS enabled.

## 4. Save the web configuration in WordPress

1. Firebase Console → gear beside Project Overview → **Project settings → General → Your apps**. Select the web app you registered and copy its `firebaseConfig` object. You may copy the complete displayed SDK code; the plugin extracts only the configuration fields and does not execute pasted code. This public web configuration is different from a service-account JSON file.
2. Open **CrystalTap AI → Settings → Realtime chat** in WordPress.
3. Database URL: `https://crystaltap-ph-default-rtdb.asia-southeast1.firebasedatabase.app`.
4. Paste the web configuration into **Firebase web configuration**, select **Enable live chat updates**, then **Save realtime settings**.
5. Click **Test database connection**. This verifies that public root reads are denied and that the server can publish. It does **not** certify client authentication or phone delivery.

The Android listener uses the existing Android `google-services.json` configuration already saved under Android Firebase notifications. The website listener uses the web app configuration above. Both must belong to the same project. Keep any web API-key HTTP-referrer restrictions appropriate for `https://crystaltapwater.com/*`; Android API-key package/certificate restrictions must match `com.crystaltap.agent` and the installed APK signing certificate. Allow Firebase Authentication/Identity Toolkit and Token Service APIs as required by the keys' API restrictions. If authentication fails, check Firebase Authentication initialization, enabled APIs, restrictions and service-account/project alignment before weakening restrictions.

## 5. Verify both directions

Reload a website chat and reopen Android 0.1.4. The widget/browser workspace shows **● Live** once authenticated and connected. In Android **Profile → Notifications**, look for **Live updates connected**.

Start a new visitor chat, send a message and confirm it appears in the agent inbox without manually refreshing. Claim the conversation, reply from Android, and confirm the visitor sees it. Leave an unsent draft in each client while receiving a reply; it should remain intact. Send several messages and check their order. Disconnect/reconnect the phone network, then confirm the missing history loads. Repeat from a second visitor: it must see only its own conversation. Verify private notes remain staff-only. If a client shows regular refresh, chat still works while its Firebase connection needs attention.

Test background FCM alerts separately with the phone locked. Realtime listeners run while the app/page is visible, and stop in the background. They do not make Android sound or delivery guarantees or replace FCM notification settings.

## Cron and hosting

**Keep Namecheap's once-per-minute WordPress cron.** Normal committed chat changes publish immediately without waiting for cron. Failed publishes remain in the existing jobs queue for bounded background retries. Cron also continues FCM alerts, follow-ups, summaries and maintenance. Firebase and hosting latency can still affect response times; this change removes the normal wait for the next message poll.

The server needs outbound HTTPS to `oauth2.googleapis.com` and the project's database host. Clients need Firebase Authentication endpoints (`identitytoolkit.googleapis.com`, `securetoken.googleapis.com`) and the database HTTPS/WebSocket connection. Exclude private CrystalTap REST endpoints from caching as before. Keep TLS verification enabled.

## Access, reconnects and retention

WordPress grants a feed lease of at most 180 seconds, normally renewed every 120 seconds. Visitor-session/native-device expiry can shorten it. Removing WordPress access immediately blocks canonical message reads; a previously issued Firebase lease can still receive opaque hints until its short expiry. Clients cannot publish or enumerate feeds. Credentials are not logged, and browser Firebase auth stays in memory. Signing out or backgrounding closes listeners. Reconnects and initial subscriptions refresh canonical history, including changes whose immediate publish failed. Polling continues as a fallback, less frequently while connected.

Expired visitor feeds and inactive staff feeds are removed by the existing retention/maintenance run. WordPress salt changes alter the feed namespace and signing/encryption context; retain the existing salts and backups during ordinary upgrades.

## Validation scope

Automated WordPress checks cover scoped grants, CSRF/capabilities, RSA signatures and expiry, publish-after-commit, rollback, private-note filtering, failure persistence and cron retries. Isolated listener tests cover renewal, permission cancellation, reconnect and teardown; browser tests cover event-triggered refresh with draft preservation and safe configuration parsing. Native tests/build/lint cover the app integration and safe fallback/close behavior.

`python3 scripts/realtime_test.py` runs the access-rule suite against a **local demo-project Firebase emulator** without production credentials. This cloud environment cannot download the pinned emulator JAR because its storage domain is blocked, so that suite has not run locally. A dedicated GitHub Actions job runs it where the download is available. No live Firebase rules, WordPress settings or physical-phone delivery were changed or certified here; complete the console steps and two-device checks above before relying on live mode.

Development dependency review: the pinned Firebase browser SDK is 13.1.0, with patched gRPC 1.14.6 for Node test dependencies. The final npm audit has no high/critical findings and five moderate transitive reports in Firebase CLI tooling (Pub/Sub/OpenTelemetry and gaxios/uuid). These development packages are excluded from both installable artifacts; the browser bundle imports only Firebase app, authentication and database modules.
