# CrystalTap AI Chatbox + Android Agent

WordPress customer chat, a browser agent dashboard, and a dedicated **CrystalTap Agent Android app**. Plugin **0.2.2** replaces the former PWA/browser push with native Firebase Cloud Messaging. Existing customer conversations, leads, private images and settings are retained. This is a locally tested candidate; live deployment and physical-phone notification tests remain required.

## Install the update

1. Back up the website and database. Install `dist/crystaltap-ai-chatbox-0.2.2.zip` in WordPress, keeping only one CrystalTap plugin copy active.
2. Install `dist/CrystalTap-Agent-0.1.3.apk` on the Android phone (Android 8+). The candidate APK is signed; retain its signing identity for future updates.
3. Open the app. The website is preset to `https://crystaltapwater.com/`. Continue to the WordPress browser sign-in and approve the code only if it matches the app. Return to the app.
4. Create/configure Firebase as described in [Android setup](docs/ANDROID.md). Without Firebase, chat and the browser dashboard work, but remote Android push does not.
5. Enable notifications in the app, set Online, test local sound, then test Firebase push and a real visitor request for human support. A functioning hosting scheduler is still needed.

Version 0.2.1 restores alerts for customer messages in an unclaimed support queue and exposes website worker/queue delay. App 0.1.1 adds opt-in system-displayed background alerts with the existing sound channel. See [intermittent-alert troubleshooting](docs/ANDROID.md#intermittent-or-delayed-alerts-plugin-021--app-011).

Android app **0.1.2** refreshes the native interface with a card-based inbox, persistent bottom navigation, aligned chat bubbles, a clearer composer and grouped notification/account settings. [See the UI and rendered previews](docs/ANDROID-UI.md). It works with plugin 0.2.1.

The WordPress page `/crystaltap-agent/` remains a browser workspace and device-approval page. It has no install prompt, manifest or offline cache. Opening it unregisters the old CrystalTap worker; a minimal retirement worker is retained for existing installations. Remove the old PWA/home-screen shortcut manually. Other site workers are left untouched.

## Customer details, branding and previews (0.2.2 / 0.1.3)

New website chats ask for **Name (required)**, **Email (optional)** and **Phone (optional)**. Validation runs in the browser and server. Details resume only for the same visitor and appear in conversations for assigned agents/supervisors; they follow existing retention and privacy export/erasure. Older active conversations continue working.

Android 0.1.3 uses the supplied CrystalTap faucet-and-droplet artwork as its adaptive launcher icon. Automatic phone alerts contain a bounded public customer message preview, including in the background. Private team notes are excluded. Eligible unclaimed-queue recipients can see the message snippet in an alert; contact fields and full history still require the existing conversation access. Android lock-screen settings control whether previews are visible while locked. Install the plugin update first, then the APK over the existing app.

## Customer widget

Use `[crystaltap_ai_chat]` for embedded chat or `[crystaltap_ai_chat mode="floating"]`. Floating chat is also available through widget settings. Configure the chat icon under Chat Widget. Composers support emoji, HTTP/HTTPS links and private JPG/PNG/WebP attachments (2 MB each, three per message, 20 per conversation). PHP GD is required. Photos enter human support and are not sent to OpenAI.

Business Information controls WhatsApp, Messenger and Viber contact buttons. They open those apps; replies remain there. Unified third-party messaging is not implemented.

## Develop and validate

Use the existing checkout; do not create a worktree unless explicitly requested. Requirements: Docker/Compose, Python 3.12+, Node 20+. Java/Android tools are installed into ignored `.runtime` state with official checksums and verified TLS.

```sh
python3 scripts/install.py
python3 scripts/dev.py
npm run check
docker compose run --rm cli wp eval-file wp-content/plugins/crystaltap-ai-chatbox/tests/integration.php
docker compose run --rm cli wp eval-file wp-content/plugins/crystaltap-ai-chatbox/tests/customer.php
docker compose run --rm cli wp eval-file wp-content/plugins/crystaltap-ai-chatbox/tests/native.php
python3 tests/concurrency.py
npm test
python3 scripts/release.py
python3 scripts/package_smoke.py
python3 scripts/android_build.py --test
```

Browser tests require the local development URL and reset its throttling counters before a run. Never run fixture scripts on production. Generated development login, signing files and server credentials must never be committed or printed. Android source is in `android/`; it uses native views and networking, not a WebView/PWA wrapper.

## Configuration and documentation

OpenAI is optional for human chat. Configure `CTAI_OPENAI_KEY` on the server or use the encrypted credential form. Verify billing/model compatibility and budgets before enabling AI. Firebase server signing credentials are uploaded through the HTTPS administrator form and stored encrypted; they are not bundled in the APK. The Android app receives only Firebase's client configuration after agent authentication.

- [Android installation, Firebase, signing and test checklist](docs/ANDROID.md)
- [Deployment and recovery](docs/OPERATIONS.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Validation evidence and remaining gates](docs/STATUS.md)

Project code: GPL-2.0-or-later. Android dependencies retain their upstream licenses. No Chatway, WooCommerce or theme dependency is required.
