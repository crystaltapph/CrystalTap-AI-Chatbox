=== CrystalTap AI Chatbox ===
Contributors: crystaltap
Tags: customer-support, chatbot, live-chat, pwa
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone approved-knowledge AI chat, human support, and an installable agent workspace.

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
7. Under Settings generate VAPID keys and configure the contact subject.
8. Configure a real one-minute WordPress cron and private-response cache exclusions.
9. Open /crystaltap-agent/ on HTTPS. Install on supported phones and register push by user action.

== Shortcode ==

[crystaltap_ai_chat]
[crystaltap_ai_chat mode="floating"]

Embedded mode takes precedence if multiple launch points exist on the same page.

== Privacy ==

Conversation content is stored in dedicated tables in your WordPress database.
When AI is enabled, relevant recent messages and approved business sources are sent to OpenAI.
Push uses the browser vendor's push service; alerts omit customer message contents on lock screens.
Use privacy tools and an approved retention policy. Deactivation/uninstall preserve data.

== Known limitations ==

Single-site WordPress only. No offline sends or attachments. Polling is not instant delivery.
AI streaming is opt-in and depends on hosting/proxy buffering. Background summaries require a functioning scheduler.
Browser/OS installation and push support vary. Physical-device testing is required before release.
WP-Cron alone does not guarantee timely escalation. Local costs are estimates, not provider invoices.

== Changelog ==

= 0.1.1 =
Distinguish OpenAI billing/quota errors from temporary rate limits without exposing provider messages or retrying exhausted quota.

= 0.1.0 =
Initial development candidate with shared backend, customer widget, agent workspace and PWA infrastructure.
