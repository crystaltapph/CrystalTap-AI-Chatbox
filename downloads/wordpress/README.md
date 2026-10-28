# CrystalTap WordPress plugin

Download [crystaltap-ai-chatbox-0.3.0.zip](crystaltap-ai-chatbox-0.3.0.zip?raw=true) and use WordPress → Plugins → Add New Plugin → Upload Plugin to replace the existing CrystalTap installation. Keep one copy active and clear the website/page cache.

This update adds Firebase live signals with polling fallback. Existing customer details, messages, widget settings and FCM notifications remain available. Install Android 0.1.4 for its native live listener, publish the supplied rules and configure **Settings → Realtime chat** using the [Firebase guide](../../docs/REALTIME.md). Cron remains required for background alerts, retries and maintenance.

`SHA256SUMS` verifies the download. No credentials are included.
