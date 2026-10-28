# WordPress plugin update

Download [crystaltap-ai-chatbox-0.2.2.zip](crystaltap-ai-chatbox-0.2.2.zip?raw=true) and use WordPress → Plugins → Add New Plugin → Upload Plugin to replace the existing CrystalTap installation. Keep only one plugin copy active. Preserve the database and Firebase settings during the update. Clear your website/page cache afterward so the new customer form loads.

This version requires a customer name for new chats, accepts optional email/phone, supports authorized contact display and includes public customer message previews in Android alerts. It retains waiting-queue alerts, idempotent device registration and website worker/queue-delay diagnostics for [Android app 0.1.3](../android/README.md).

Live hosting scheduling must still be verified. Follow the [delivery troubleshooting guide](../../docs/ANDROID.md#intermittent-or-delayed-alerts-plugin-021--app-011).
