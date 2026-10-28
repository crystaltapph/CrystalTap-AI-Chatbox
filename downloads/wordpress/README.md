# WordPress plugin update

Download [crystaltap-ai-chatbox-0.2.1.zip](crystaltap-ai-chatbox-0.2.1.zip?raw=true) and use WordPress → Plugins → Add New Plugin → Upload Plugin to replace the existing CrystalTap installation. Keep only one plugin copy active. Preserve the database and Firebase settings during the update.

This version adds missing waiting-queue message alerts, idempotent device registration, website worker/queue-delay diagnostics and compatible background notification payloads for [Android app 0.1.1](../android/README.md).

Live hosting scheduling must still be verified. Follow the [delivery troubleshooting guide](../../docs/ANDROID.md#intermittent-or-delayed-alerts-plugin-021--app-011).
