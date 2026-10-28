=== CrystalTap AI Chatbox ===
Contributors: crystaltap
Tags: customer-support, chatbot, live-chat, pwa
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone approved-knowledge AI chat, human support, and a native Android agent app and browser workspace.

== Description ==

Development candidate. Production rollout requires the release checks in the source documentation.
No WooCommerce, theme, or third-party messaging plugin is required.
OpenAI Responses API is optional until AI is enabled. Human support remains usable without it.

== Installation ==

1. Back up your site and database. Test the ZIP on staging first.
2. Install the bundled ZIP through WordPress Plugins and activate it on a single-site installation.
3. Open CrystalTap AI. Configure business information and publish verified knowledge/products.
4. Configure a server-side CTAI_OPENAI_KEY or save an encrypted key under AI Configuration.
5. Verify current model pricing, set budgets, test the connection, then enable AI.
6. Assign WordPress users the CrystalTap Support Agent or Supervisor role.
7. Under Settings upload matching Android Firebase client and service-account JSON files.
8. Configure a real one-minute WordPress cron and private-response cache exclusions.
9. Open /crystaltap-agent/ on HTTPS. Install the dedicated Android APK and approve sign-in through the browser workspace.

== Shortcode ==

[crystaltap_ai_chat]
[crystaltap_ai_chat mode="floating"]

Embedded mode takes precedence if multiple launch points exist on the same page.

== Privacy ==

Conversation content is stored in dedicated tables in your WordPress database.
When AI is enabled, relevant recent messages and approved business sources are sent to OpenAI.
Android alerts use Firebase Cloud Messaging and include bounded public customer message previews. Private team notes are excluded. Android lock-screen settings control preview visibility. New chats collect a required name and optional email/phone for support; these details follow conversation retention and privacy export/erasure.
Use privacy tools and an approved retention policy. Deactivation/uninstall preserve data.

== Known limitations ==

Single-site WordPress only. No offline sends. Image attachments require PHP GD and obey size/rate limits. Polling is not instant delivery.
AI streaming is opt-in and depends on hosting/proxy buffering. Background summaries require a functioning scheduler.
Native Android push needs Firebase configuration and Google Play services. Physical-device testing is required before release.
WP-Cron alone does not guarantee timely escalation. Local costs are estimates, not provider invoices.

== Changelog ==

= 0.2.2 =
Require a customer name before starting a new chat; offer optional email and phone. Display saved details to authorized agents and include public customer message previews in Android alerts. Support the new branded Android 0.1.3 app.

= 0.2.1 =
Alert online agents when customers send more messages in an unclaimed support conversation. Avoid throttling unchanged device-registration refreshes. Add scheduler/queue-delay diagnostics and opt-in Firebase system notifications for Android app 0.1.1, preserving compatibility with the original APK.

= 0.2.0 =
Replace PWA/browser push with a native Android app, browser-approved device pairing, revocable encrypted sessions, Firebase HTTP-v1 alerts and a dedicated notification channel. Retire old worker/cache/install routes while preserving the browser dashboard and existing customer data.

= 0.1.5 =
Add a built-in/uploadable chat launcher icon, real customer/agent typing indicators, a basic emoji picker, safe clickable HTTP(S) links and private JPG/PNG/WebP chat attachments. Image messages go to human support; image bytes are not sent to OpenAI.

= 0.1.4 =
Add configurable WhatsApp, Messenger and Viber contact buttons. Fix stale conversation responses, draft loss during sends, expired visitor session recovery, reopened queue alerts and persistent app-update prompts.

= 0.1.3 =
Keep support alerts for explicitly online agents while their phone sleeps, repair missing cron events, and add secure device push testing, delivery checks and optional foreground sound.

= 0.1.2 =
Bundle production libraries in GitHub archives and report precise Web Push file, class and PHP requirement diagnostics.

= 0.1.1 =
Distinguish OpenAI billing/quota errors from temporary rate limits without exposing provider messages or retrying exhausted quota.

= 0.1.0 =
Initial development candidate with shared backend, customer widget, agent workspace and PWA infrastructure.
