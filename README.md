# CrystalTap AI Chatbox

Standalone WordPress plugin with a public customer widget, WordPress support dashboard, and same-origin **CrystalTap Agent** PWA. This repository contains a development candidate, not a certified production release.

No Chatway, RO Shop, WooCommerce, theme, external database, or hosted messaging dependency is required. OpenAI calls are server-side; human support works while AI is disabled.

## Install on WordPress

Use the packaged `crystaltap-ai-chatbox-0.1.4.zip`, which includes production dependencies. Starting with **0.1.2**, GitHub → Code → Download ZIP also includes the production libraries. Older GitHub source archives omitted them. Check `plugin_version` and `push_diagnostic` under CrystalTap Settings → Environment health when diagnosing an installation. To build an installable package from source, follow the development and packaging commands below.

If the source archive is already installed, back up the site, deactivate that copy, then upload and activate the packaged plugin. Accept WordPress's replacement prompt when offered. If WordPress creates a separate entry because the source folder has a different name, leave the source copy deactivated; keep only one CrystalTap copy active. Plugin-owned settings and tables are retained on deactivation.


## Develop locally

Requirements: Docker with Compose, Python 3, Node 20+ and npm. PHP, MariaDB and WordPress run in containers. The local HTTP binding is restricted to loopback. Production requires HTTPS.

```sh
python3 scripts/install.py
python3 scripts/dev.py
```

The install script uses the committed Composer/npm lockfiles and verified Git source downloads. It mounts the host CA bundle for enterprise/cloud proxy trust without disabling verification. The development script prepares WordPress, activates the plugin and creates a shortcode demo. It refuses to reset accounts if the site URL differs from the local development URL.

Local credentials are generated into `.runtime/dev-login.json`, mode 0600, and never committed or printed. These development credentials are not for production. Development startup refreshes that account's password from the private file and invalidates its previous sessions.

Use the existing checkout. Cloud tasks already have isolated environments; do not create a Git worktree unless explicitly requested.

## Validate

```sh
npm run check
docker compose run --rm cli wp eval-file wp-content/plugins/crystaltap-ai-chatbox/tests/integration.php
python3 tests/concurrency.py
npm test
```

Browser tests use system Chromium when available. Otherwise run `npx playwright install --with-deps chromium`; `CTAI_CHROMIUM` can select a browser executable. Integration and race tests create disposable plugin tables and temporary WordPress users. Browser tests create clearly labeled development conversations. Run these tests only on the local development stack, never against production.

```sh
python3 scripts/release.py
```

The ZIP in `dist/` bundles production PHP dependencies and excludes development credentials, Git metadata, tests and node_modules. `SHA256SUMS` records its digest. Test the resulting ZIP on staging before any live deployment.

## Configuration

1. Configure business contact information, support hours and the privacy notice.
2. Add approved knowledge and products. New records default to drafts. Never seed invented business facts.
3. Configure `CTAI_OPENAI_KEY` as a server constant/environment variable, or enter it in the encrypted credential form. Server configuration takes precedence; keys are never returned by REST.
4. Verify the selected model, supported parameters and current token prices against official OpenAI documentation. Configure budgets and run the connection test before enabling AI.
5. Assign WordPress users CrystalTap Support Agent or Supervisor roles. Existing WordPress administrators receive plugin management capabilities. CrystalTap Administrator is a plugin role, not full WordPress administration.
6. Generate VAPID keys in Settings, review the public key/contact, then register devices from Profile. Private keys are encrypted using WordPress salt-derived key material unless supplied externally as `CTAI_VAPID_PRIVATE`.
7. Set a real one-minute cron, confirm HTTPS and private-response cache exclusions, and validate push on supported physical phones.

On a live site the agent app is served under the site's `/crystaltap-agent/` route. Its worker scope is limited to that directory. Embedded customer chat uses `[crystaltap_ai_chat]`; floating mode uses `[crystaltap_ai_chat mode="floating"]`.

## Documentation

- [Architecture and invariants](docs/ARCHITECTURE.md)
- [Deployment, privacy and recovery](docs/OPERATIONS.md)
- [Validation evidence and release gates](docs/STATUS.md)

## License

Project code: GPL-2.0-or-later. Bundled libraries retain their own licenses. See `LICENSE` and the license files and Composer metadata under `vendor/` in a built package.

## Missing notifications or sound

Version 0.1.3 fixes new support-request alerts for explicitly Online agents whose phone stops sending heartbeats while asleep. After updating, reopen the agent app, apply its update if prompted, and go to Profile → Enable push on this device → Test push on this device. Profile's delivery checks distinguish device registration, quiet hours, pending jobs and a stale background worker. Test push sends immediately without waiting for cron; service acceptance alone does not prove phone receipt.

For a chime while the workspace is open and visible, tap Profile → Enable / test in-app sound each time you reopen/reload it. Use Mute in-app sound to stop it. The browser/OS controls sound while the phone is locked.

See [Namecheap scheduling and push troubleshooting](docs/OPERATIONS.md#namecheap-scheduling-and-push-troubleshooting) for hosting setup.

## WhatsApp, Messenger and Viber

In CrystalTap AI → Business Information, enter your WhatsApp number (including country code), your `https://m.me/YourPage` Messenger link, and your Viber number. You may instead provide an official Viber share/bot link in the optional Viber link field; it overrides the Viber number. Leave a contact blank to hide its button.

These buttons open the relevant messaging app or website. Replies are handled in that service. CrystalTap website messages and history are not automatically transferred, and messages from those services do not appear in the CrystalTap agent inbox. Viber phone links need the Viber app installed. No additional provider API key is needed for contact buttons. Account ownership and app availability must be verified on your devices.

Two-way inbox integration is a separate setup requiring the platforms' business accounts/API approvals, secure server credentials, verified webhooks and messaging-policy handling. No such accounts are provisioned by this plugin.
