# CrystalTap Android installation and Firebase setup

## What is delivered

Plugin 0.2.0 supplies native device approval, opaque access tokens, encrypted device registrations and FCM HTTP-v1 delivery. Android app 0.1.0 provides native sign-in, inbox/claim/reply, internal notes, images, emoji, links, notifications, lead status updates, preferences and a dedicated alert channel. Product/AI/business administration stays in the browser workspace. There is no WebView wrapper.

The user's website is `https://crystaltapwater.com/`. A Firebase project did not exist at development time. No Google project has been created or configured by this work, and no live Firebase delivery or POCO sound result is certified. The live site was not deployed from this workspace; its network access was initially blocked by the cloud egress policy.

## Install and sign in

Back up the site and test the plugin on staging. Upgrade the WordPress plugin before signing in with the APK. Install the signed candidate APK on Android 8 or later. Android may ask you to allow installation from the browser/file manager that downloaded it. Keep APK distribution limited to approved staff.

Open CrystalTap Agent and check the website address. Continue with WordPress. The app opens the normal website sign-in (including any existing 2FA/SSO). In the browser, compare the displayed eight-character code with the Android app and explicitly approve it. Return to the Android app and continue. Do not approve an unexpected pairing request.

The app does not collect the WordPress password. The website issues a random device token only after browser approval and proof of the app's private verifier. The token is stored using Android Keystore-backed AES-GCM, expires after 30 days, and can be revoked under browser Profile → Android devices. Password/salt changes or WordPress session revocation invalidate access. Server auth must receive the `Authorization: Bearer` header intact; configure proxies accordingly.

## Create Firebase (Google account action required)

1. Open [Firebase Console](https://console.firebase.google.com/) in your own Google account and create a project for CrystalTap. Analytics is optional for this app.
2. Add an Android app with package name **`com.crystaltap.agent`**. Download its **`google-services.json`**. The APK loads this client configuration from the authenticated website, so adding the file does not require rebuilding the APK.
3. Confirm the **Firebase Cloud Messaging API (HTTP v1)** is enabled for the project.
4. Create/download a service-account JSON signing key for that same project. Give the account permission to send FCM messages in the project (the Firebase Cloud Messaging API Admin role provides that permission). Restrict access to this file and rotate it if exposed. Do not send private signing keys in chat or commit them to GitHub.
5. On your HTTPS WordPress site, open CrystalTap AI → Settings → Android Firebase notifications. Upload `google-services.json` and the service-account JSON. Save them together or separately; the plugin rejects mismatched projects. Only administrators can configure them.
6. Reopen the signed-in Android app. Open Profile and enable Android notifications. Grant Android's notification permission when requested. The app obtains and registers its Firebase device token; registration is refreshed on subsequent foreground starts.

Firebase's API key in the Android client configuration is intended for the mobile client; it is distinct from the private service-account signing key. Apply suitable Android/package/signing-certificate restrictions in Google Cloud and verify FCM/Installations still work. The app uses Firebase Messaging/Installations, not Firebase Authentication or a public database.

Server requests use verified TLS to `oauth2.googleapis.com` and `fcm.googleapis.com`. Client registration also uses Firebase/Google Play services. Signing keys and OAuth access tokens never appear in REST status responses, APK resources, message payloads or logs. Firebase configuration is encrypted with WordPress salts; back up those salts securely.

## Alert sound and delivery tests

Profile → Test local sound / notification checks the app's own Android notification channel without the server. Profile → Test Firebase push checks remote delivery to the current signed-in device. A provider-accepted result is not proof the device displayed or sounded an alert.

The channel is **CrystalTap support alerts**, with high importance, a bundled tone and vibration. Open Android notification sound settings from Profile to adjust it. Android owns existing channel settings: updates do not override a user's selected tone or silence setting. Grant notification permission on Android 13+. Mute, DND, force-stop, network loss and device power policy can still affect delivery/sound. An app force-stopped in Android Settings must be reopened.

Set Online for new queue alerts. From a separate visitor browser request human support, then verify the phone while the app is foregrounded, backgrounded, swiped away and locked. Verify notification tapping opens the right conversation and server authorization still applies. Test logout and remote device revocation. Ordinary AI-only chat does not generate agent alerts. Automatic alerts respect event preferences and website-timezone quiet hours; the manual Firebase test bypasses those preferences.

Native push still uses the plugin's background job worker. Set a real one-minute WordPress scheduler if your hosting plan permits it. Namecheap shared-plan minimum intervals must be confirmed with support; a five-minute interval can delay alerts. Native Android does not fix a stopped server scheduler.

## Remove the former PWA

No new manifest/install/offline/push feature is served. The old manifest returns HTTP 410; old browser-push API routes return a retirement message. The browser workspace unregisters only the CrystalTap-scoped worker and unsubscribes its old browser endpoint. The retirement worker removes only `ctai-shell-*` caches and unregisters itself. Remove the old installed web app/shortcut manually. Preserve the WordPress plugin database during upgrade; old browser subscription data and encrypted VAPID options are retained inert for backup/rollback, but are not used for delivery.

## Signing and reproducible builds

Run `python3 scripts/android_build.py --test`. Tool versions/checksums are pinned in `android/toolchain.json`; downloads use verified TLS and trusted enterprise CA configuration. The APK is `dist/CrystalTap-Agent-0.1.0.apk`, with a SHA-256 record in `dist/ANDROID-SHA256SUMS`.

The build creates a local candidate signing identity in `.runtime/android-signing.jks` with its private password in `.runtime/android-signing.json`. Both are mode 0600 and ignored by Git. Back them up securely outside the checkout before replacing the environment. **Future APK updates must use the same signing key.** Do not publish those files or cache them in public CI artifacts. A fresh CI build creates a different candidate identity; its APK is a test artifact, not an update for an already distributed build. Google Play distribution needs its own account, policy review and release-signing plan.

The app blocks screenshots and backups of its private session. Image files are selected through the Android system picker and sent only to authorized conversation endpoints. Native drafts/files remain in memory; no offline send queue or process-death draft recovery is promised.

## Instrumented emulator checks

`android/app/src/androidTest` contains three checks for native launch/site preset, Android Keystore session encryption, and the app's actual local notification channel/custom-sound URI. On the dedicated disposable `CrystalTap_Api28` emulator, run `python3 scripts/android_device_test.py` (or `--container ctai-android-smoke` for the isolated cloud emulator). The script refuses physical phones and other AVD names and reinstalls only its test packages. The cloud has no KVM; software-only API 35 and API 28 boot attempts did not complete, so the three device checks were compiled but not run. The optional fixture uses API 28 and needs a working emulator on a supported development machine. No emulator check establishes audible output from a real speaker or live Firebase delivery.
