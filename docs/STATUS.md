# Development candidate status

Version 0.1.1 is a development candidate. The website has not been deployed or modified, no GitHub changes have been pushed, and live OpenAI/push behavior has not been certified.

## Implemented

- Independent plugin bootstrap, additive schema installation, dedicated roles/capabilities and redacted audits.
- Customer widget with embedded/floating modes, verified product cards, resumable guest sessions, handoff and voluntary lead capture.
- Same backend for WordPress and mobile agent inbox, claims, replies, private notes, transfers, resolution and AI return.
- Approved knowledge/products, business configuration, media upload through WordPress, lead operations and JSON exports.
- Responses API adapter, bounded context, background summaries, local budget reservations, usage records and optional streaming/fallback.
- Persistent notification bell/read state, device registration/revocation, VAPID generation, encrypted subscription storage and Web Push delivery jobs.
- Scoped PWA manifest/worker, offline shell, no offline sends, install guidance and controlled updates.
- Retention cleanup, conversation erasure/export and WordPress privacy integration.
- Locked dependency installation, repeatable local startup, automated tests and an installable ZIP builder.

## Automated validation

The current development target is WordPress 7.1.3 / PHP 8.3 / MariaDB 10.11 and system Chromium on Linux.

- 47 backend integration assertions: migrations, visitor isolation, capabilities, message/notification deduplication, private-note isolation, ownership, state transitions, encryption, SSE decoding, approved retrieval, lead consent, budgets, provider orchestration, streaming callbacks, summaries, takeover fencing, nested rollback and erasure.
- Three real competing-process/database scenarios: one claim winner, bounded concurrent budget reservations, and one persisted message for competing duplicate submissions.
- Four browser/API workflows: customer continuation/isolation/handoff; agent replies and private notes; mobile layout/bell/content/settings; SSE fallback plus CSRF/origin rejection and blocked development-file access.
- PHP and JavaScript syntax checks; repeat installation/startup; release ZIP integrity and excluded-file checks.

Deterministic AI tests use a provider substitute and do not prove live API compatibility, answer quality or provider billing. SSE tests validate local transport/decoding and fallback, not live proxy chunk timing. Chromium's phone-sized viewport does not establish physical iPhone/Android behavior.

## Required release gates still outstanding

| Gate | Required evidence |
|---|---|
| Approved business content | Actual prices, availability, specifications, sources, water guidance, delivery/install/warranty policies and branding approved by CrystalTap |
| Live OpenAI | Secure key, actual chosen model/parameters, current pricing, real response/usage tests, multilingual grounded-answer evaluation and failure recovery |
| Streaming deployment | Real host/proxy flushing, interruption and takeover behavior verified before enabling the option |
| Physical phones | Supported Android and iPhone installation, login, keyboard, push permission, foreground/background/closed delivery, click routing and logout/revocation |
| Hosting and scheduler | HTTPS, one-minute real cron, outbound access, private cache exclusions, job recovery and resource limits verified |
| Site regression | Actual Divi 5/theme/forms/checkout/plugins/CDN tested on staging; no production site access has been used |
| Compatibility/load | Minimum supported WordPress/PHP/database matrix, representative concurrency/load, large retention batches and upgrade/restore rehearsals |
| Security/privacy acceptance | Independent review of the target deployment, finalized retention/notice/access policies and incident procedure |

## Scope and operational limits

Single-site only. No payment processing, checkout, attachments or offline message queue. Polling is not instant transport. Push is best-effort. Keyword retrieval needs indexed search work/load validation for large catalogs. Model capability hints require confirmation against the actual selected model. Agent enrollment uses WordPress user management. Metrics are operational summaries; official provider billing is separate. A setup checklist is provided rather than claiming an independently verified production onboarding wizard.

Production release remains gated by the evidence above. Do not label all original acceptance criteria passed based solely on this local candidate.

## 0.1.1 diagnostics

The connection test now distinguishes recognized OpenAI quota/billing errors from temporary rate limits. Quota errors are not retried. Unknown 429 responses retain guidance for both causes; raw provider error messages are never displayed. These classifications were tested with deterministic HTTP responses. The exact cause on a live site must be established from its response or OpenAI account status; local tests do not inspect the live account.
