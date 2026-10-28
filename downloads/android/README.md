# CrystalTap Agent for Android

Download [CrystalTap-Agent-0.1.5.apk](CrystalTap-Agent-0.1.5.apk?raw=true) for Android 8 or newer. Install plugin 0.3.1 first, then update the existing app **without uninstalling**. The signing certificate matches earlier APKs, retaining sign-in and preferences. The website preset is https://crystaltapwater.com/.

This update adds a foreground Firebase live listener, reconnect recovery and regular-refresh fallback. Existing branding, customer details, notes, images and FCM alerts remain available. Follow the [Firebase live chat setup guide](../../docs/REALTIME.md) to publish private database rules and enable realtime in WordPress. Cron still handles background notifications. Live phone delivery and sound require physical-device checks.

`SHA256SUMS` verifies the download. Private signing keys and credentials are not included. Future app updates must retain the same signing identity.

This update cleans up the customer widget and fixes Android lead-list loading. Clear the website/page cache after updating the plugin; install the APK over your existing app.
