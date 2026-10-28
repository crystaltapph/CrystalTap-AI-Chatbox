# Deployment and operations

## Staging before production

This candidate has been exercised locally, not deployed to crystaltapwater.com. Do not run the development stack or test fixtures on production. The local Compose database password and generated account are development-only.

1. Back up WordPress files, database and encryption configuration. Confirm a tested restore path.
2. Verify single-site WordPress 6.6+, PHP 8.2+, InnoDB, curl, mbstring, openssl and HTTPS. The local test target is WordPress 7.1.3, PHP 8.3 and MariaDB 10.11; other combinations remain release-matrix work.
3. Build the ZIP from locked dependencies and verify `dist/SHA256SUMS` after transfer. Version 0.1.2 and newer commit the production dependency bundle, so both GitHub source archives and packaged ZIPs contain it. Older GitHub source archives lack dependencies. The packaged ZIP must include vendor/autoload.php and the Web Push library. If replacing an installed source copy, deactivate it before activating the packaged version; different folder names can otherwise leave duplicate plugins.
4. Install on a staging copy without required companion plugins. Confirm activation/migrations and existing functionality.
5. Configure actual business information, approval sources, products, water-treatment guidance, delivery/installation policies and support hours. No invented catalog is included.
6. Confirm the privacy notice and retention policy with the business. Configure approved agent access and a monitored support queue.
7. Configure credentials securely, verify model compatibility/pricing, test the connection, and set conservative daily/monthly budgets before enabling AI.
8. Generate/configure VAPID, schedule cron, test Android/iOS installation and push, and complete the release gates in STATUS.md.
9. Deploy in a controlled maintenance window, retain the previous package and monitor errors, job delays, usage and support requests.

## Secrets and trust

Prefer constants/environment variables `CTAI_OPENAI_KEY` and `CTAI_VAPID_PRIVATE` set outside the web root. The encrypted settings alternative uses key material derived from WordPress authentication salts; database backups alone do not contain that key. A full server compromise still compromises secrets. Back up salts securely and plan key rotation. Never export credentials with plugin settings or write them to browser storage.

Server-supplied credentials override encrypted database values. Removing a stored key does not unset a server variable. VAPID rotation invalidates device subscriptions; agents must register again. If a private VAPID key is externally configured, its matching public key must also be configured; do not generate a mismatched replacement in the dashboard.

The local cloud stack mounts the host's trusted CA bundle. Production normally uses WordPress/system trust. Set `CTAI_CA_BUNDLE` to an explicitly trusted readable PEM bundle only when the deployment requires custom CA trust. Never disable TLS verification. Salt changes invalidate encrypted stored keys/subscriptions; restore or re-enter keys and register devices again.

Required outbound destinations depend on enabled features:

- OpenAI: `api.openai.com` over HTTPS.
- Supported push: `fcm.googleapis.com`, `updates.push.services.mozilla.com`, `web.push.apple.com`, and the exact supported Windows push destination if used.
- Dependency refresh: package registries and GitHub source hosts. The tested Composer installation uses verified Git source downloads because this cloud policy blocks GitHub API archives.

## Cron

Production requires a real one-minute scheduler for timely push processing, summaries and escalation. For example, using WP-CLI on the host:

```cron
* * * * * cd /path/to/wordpress && /usr/local/bin/wp cron event run --due-now --quiet
```

Adjust the PHP/user/environment and paths for the host. Only disable traffic-driven WP-Cron after verifying the real scheduler. Inspect the last-worker timestamp in Settings and check `ctai_tick`/`ctai_cleanup` scheduled events. A cron process or push-provider acceptance is not proof a user received a notification.

WP-Cron-only deployments remain usable for interactive chat, but background delivery/escalation has no timing guarantee. Background job failures are redacted in audit logs and retried within bounded limits. Check queue/cron behavior under the actual host's resource limits.

## Cache and routing

Exclude `crystaltap-ai/v1` private REST responses, authenticated agent documents and authenticated bootstrap requests from page/CDN caching. Preserve `Cache-Control: no-store`, cookies, REST nonces and origin headers. The public widget assets/configuration may be cached, but session bootstrap and conversation data must not be cached.

The Apache guard file denies direct HTTP access to development state, repository metadata and dependency internals. Nginx deployments must provide equivalent server rules if a source checkout is exposed; never expose the development checkout or its private files on a public server. The release ZIP excludes development state.

Flush rewrite rules once after activation or permalink changes. Confirm the manifest and worker return 200 without canonical redirects, correct content types and scope. Audit existing root-scope service workers. Do not expand the plugin worker to `/` or WordPress admin.

Reverse-proxy HTTPS configuration must make WordPress `is_ssl()` accurate using trusted proxy configuration. The plugin does not blindly trust client-supplied forwarding headers.

## Mobile and push

Use a supported current browser/OS. On Android use Install App/Add to Home Screen. On iPhone use Safari's Add to Home Screen, then open the installed app and enable notifications from Profile. iOS web push depends on supported Home Screen web-app versions and user permission; test the actual target devices.

Push content is generic on lock screens. Customer previews are shown only within authorized in-app notification views. Disabled permission, revoked/expired sessions, quiet hours, browser limitations and OS delivery policies can prevent alerts. The durable bell and inbox are the authoritative fallback. Notification sound and badge behavior remain controlled by the browser/OS; native-app feature parity is not promised.

## Privacy and retention

Default conversation retention is 90 days and must be reviewed by the business. The daily cleanup processes bounded batches; large backlogs require monitoring and additional scheduled runs. Erasure removes linked messages, read state, assignments, leads, notifications and events, and disconnects retained aggregate usage records. Operational audit references may remain under the log retention policy.

WordPress privacy tools find email-associated leads and public conversation history. Anonymous requests without a captured email need a separately verified support process. Administrative conversation exports can contain private notes; restrict and audit them. Lead export is JSON to avoid spreadsheet formula execution. Do not reuse support consent for marketing.

Messages relevant to AI assistance are sent to OpenAI when enabled. Review applicable provider terms and Philippine privacy obligations. Establish staff access, processor arrangements, incident handling, notices and legal retention decisions before launch.

## Upgrade, rollback and uninstall

Deactivation and uninstall retain data and encrypted configuration. Intentional deletion uses authenticated privacy tools; uninstall is not an implicit data wipe. Plugin roles are retained to preserve account assignments on reinstall.

Before upgrading, back up database/files/keys and test the migration on staging. Migrations are additive and versioned. If an upgrade fails, disable the plugin, inspect the failure and restore the tested backup when necessary. Installing an old ZIP does not automatically reverse database changes. Never run a destructive downgrade migration against live customer data.

## Incident controls

Disable AI in settings to stop new provider calls; human support stays available. Review provider-side project limits separately. Revoke compromised sessions/devices, rotate affected credentials, and audit access. Never place raw provider error bodies or request credentials in logs or support messages.

## Web Push dependency diagnostics

Version 0.1.2 adds plugin_version, plugin_directory, push_ready, push_vapid_class, push_missing_extensions and push_diagnostic to the administrator-only Environment health view. The active copy must report version 0.1.2 or newer. Missing/unreadable files require restoring the complete dependency bundle or correcting filesystem access. Present files with unavailable classes need investigation of stale active copies/autoload conflicts. Missing PHP extensions or disabled OpenSSL functions require hosting configuration. These checks report no credentials or absolute server paths. They do not prove actual device push delivery.

## Namecheap scheduling and push troubleshooting

On Namecheap cPanel hosting, open cPanel → Advanced → Cron Jobs. The intended worker interval is every minute (`* * * * *`), but shared plans may restrict how frequently jobs run. Confirm the permitted minimum with Namecheap support before adding a job. If the minimum is five minutes, use `*/5 * * * *` and expect notification delays. EasyWP has a different hosting interface; ask support for its supported scheduler rather than assuming cPanel is available.

A PHP cron command has this form (example placeholders, not a command to paste unchanged):

```sh
/ABSOLUTE/PATH/TO/php -q /home/CPANEL_USERNAME/WORDPRESS_FOLDER/wp-cron.php
```

Use the site's actual document root and a PHP CLI binary matching its supported PHP version. Namecheap support can supply both. Do not copy the development Docker paths. Do not add a second scheduler if one already runs WordPress cron. Leave traffic-driven WP-Cron enabled until the real job is verified.

Copyable support request:

> Please check my WordPress site's background scheduler. Configure wp-cron.php using the correct PHP CLI binary and document root at the shortest interval my plan permits, ideally every minute. Please confirm whether one-minute cron is allowed and verify that the ctai_tick WordPress event runs. Also confirm outbound HTTPS access to my browser's push service (Android Chrome normally uses fcm.googleapis.com), with TLS verification enabled.

After installing 0.1.3, reopen the agent app and accept its update when ready. In Profile, enable push on the device again, then use Test push on this device. This is an intentional visible test; it bypasses quiet hours and automatic event preferences, targets only the current session's registered endpoint, and is limited to three attempts per minute.

- If the test reports an error, follow its configuration/registration or hosting guidance. No provider response bodies or private keys are displayed.
- If accepted but no notification reaches the tray, inspect browser permission, worker update and OS delivery restrictions. Acceptance is not device receipt.
- If the test arrives but real support alerts do not, set Online, enable the relevant notification preferences, check quiet hours, and inspect Background worker last ran / pending alerts in Profile. Ask for human support from a separate visitor browser. Routine AI-only chat does not generate agent alerts. A worker age over three minutes suggests a scheduler delay; it does not by itself identify its cause.
- For a sound while the workspace is open and visible, enable the optional in-app sound in Profile. It requires an explicit gesture after each page reload. Locked-phone notification sound uses the browser/OS notification channel; Web Push does not supply a custom ringtone.

The plugin can repair a missing WordPress cron event, but cannot create a hosting-account scheduler. Development tests do not access the live Namecheap account or verify physical-phone delivery.
