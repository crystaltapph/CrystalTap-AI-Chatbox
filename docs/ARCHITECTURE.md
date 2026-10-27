# Architecture

## Shared backend

The three interfaces call versioned REST services under `crystaltap-ai/v1`. Authorization, conversation state, approved content and costs are decided server-side. Namespaced PHP modules keep persistence, application rules and interfaces separate. Browser interfaces use small native JavaScript/CSS bundles; there is no frontend runtime framework dependency.

`Plugin` owns lifecycle, roles, assets, routing and cron. `API` validates route permissions and request structure. `Auth` owns visitor credentials, origin/CSRF checks, input bounds and atomic rate buckets. `Conversations` owns messages, assignments and transitions. `Content` owns approved products and retrieval. `AI` orchestrates a provider abstraction. `Notifications` and `Push` implement durable alerts and best-effort delivery. `Leads` and `Privacy` own consent-based inquiries and erasure.

## Persistence

Seventeen dedicated InnoDB tables use the configured WordPress prefix: sessions, conversations, messages, conversation_reads, conversation_assignments, agent_profiles, leads, products, knowledge, notifications, push_subscriptions, usage, budget_buckets, events, jobs, audit_log, rate_limits. WordPress users, media and options remain authoritative for identity, images and configuration.

Transactions protect message sequences, claim/state transitions and budget reservations. Unique keys enforce message idempotency and notification/job deduplication. Nested operations use savepoints. Foreign-key relationships are enforced in application services for WordPress migration compatibility. Schema changes are additive through `dbDelta`; errors prevent recording the new schema version.

Message ordering uses a sequence allocated while holding the conversation row lock. Public history excludes internal notes; sequence gaps from hidden notes are valid. Current assignments are stored on conversations; a separate table records assignment history. Message reads and notification reads have independent per-agent state.

## Guest sessions

Visitor tokens contain 256 random bits, travel only in host-only HttpOnly SameSite cookies, and are hashed in the database. Production sessions require HTTPS. CSRF tokens are HMAC-derived from the session token and server nonce salt; the bootstrap supplies them to memory, not browser persistence. Public mutations require session ownership, matching origin and CSRF. Site-wide page caches never receive personalized bootstrap values in public HTML.

Sessions have an absolute configurable expiry. A session can own multiple conversations, but all customer messages count toward its shared message limit. Navigation preserves the cookie. Cross-device account recovery is not implemented. New chat does not imply erasure.

## Agent identity

Use WordPress login/cookies, REST nonces and server-side capabilities. The PWA uses the same origin and a safe login redirect. An authenticated uncached bootstrap refreshes expired REST nonces; expired sessions require login. Agent accounts have additional bounded login-attempt buckets. A presence heartbeat never changes explicit availability from away/offline to online.

Agents can inspect their own assigned conversations and claim eligible queue items. Unassigned queue summaries hide customer message text from ordinary agents. Supervisors can inspect and manage the queue. Management capability is separate from supervision. User/account creation remains a WordPress administrator responsibility.

Device push registration is tied to a WordPress session hash. Logout removes registrations for that session. Delivery rechecks user capability, session expiry, conversation access, quiet hours and preferences. Users can revoke registrations and sign out other sessions. No reusable account tokens are stored in localStorage.

## Handoff invariants

Supported states are `ai_active`, `human_requested`, `waiting`, `assigned`, `human_active`, `resolved`, and `closed`. Human Requested is recorded durably before entering Waiting. Claim uses a locked row and expected state version. A stale claim returns HTTP 409. Transfers require supervision; replies require an eligible human state and record access.

Customer messages fence previous generations. Handoff, assignment and other transitions increment the generation. OpenAI requests run outside database transactions; publication reacquires the row and requires the original AI state/generation. A completed provider call may still incur cost even when publication is suppressed. Provider tools never run arbitrary business or database operations.

Streaming can display partial text before takeover. Bytes already delivered cannot be recalled; subsequent deltas are checked against current state, and the final answer still requires the atomic publication check. For the strongest display guarantee leave streaming disabled. Non-streaming is the default.

## Retrieval and AI

Retrieval searches approved/enabled titles, keywords and content, ranks a bounded result set and sends at most five excerpts. Products carry source revisions and cards are reloaded from approved catalog IDs. Unknown or unpublished product IDs are discarded. Search currently uses bounded-result SQL text matching, which is suitable for a small initial catalog; high-volume deployments need indexed full-text retrieval and load validation before release at that scale. Semantic retrieval is an extension point.

Recent context is bounded. A background summary job processes up to 20 older public messages and maintains a bounded summary with a sequence watermark. Summaries are explicitly untrusted conversational memory, not approved evidence. Private notes never enter retrieval/context/summarization. Delayed cron can delay summaries; recent context remains available.

The Responses provider uses explicit HTTP timeouts, verified TLS, no redirects, `store:false`, bounded output and schema-constrained replies for configured model families. Reasoning settings are limited to known family hints; final capability validation requires a live connection test against the chosen model. Models can change capabilities over time. No automatic retry is attempted for ambiguous network timeouts. Explicit 429/503 responses have a bounded configurable retry count.

Daily and monthly USD estimates reserve atomically before dispatch. Conservative input bounds reduce concurrent exposure. Known usage settles reservations; uncertain outcomes are conservatively charged locally. Estimates depend on administrator-entered pricing and are not invoice figures or an absolute provider-side cap. Summaries and connection tests also consume budget.

## Synchronization and background work

Per-conversation sequence cursors fetch committed history without global event commit-order ambiguity. Inbox/notification state is refreshed by visibility-aware polling. Polling is not instant delivery. The event table is an internal durable audit/integration feed; its raw auto-increment ID is not exposed as a guaranteed commit-ordered global client cursor.

Jobs have uniqueness keys, leases, bounded batches and retries. Push TTLs and notification tags limit duplicate impact, but delivery is best-effort. Follow-up alerts have per-conversation state and deduplication; one alert is generated per waiting episode. Supervisors receive durable queue alerts even when ordinary agents are unavailable.

## PWA and caching

The manifest, worker and app live under the dedicated route, accounting for WordPress subdirectory installations. The worker caches only explicitly selected public UI resources and an offline shell. It does not cache customer APIs, authenticated documents or credentials. Offline sends are disabled. Updates require a user action after drafts are handled. Existing broader site workers must be audited on staging.

## Extension boundaries

`ctai_ai_provider` replaces the provider for deterministic tests or future integrations. `ctai_table_prefix` isolates test tables. `ctai_settings` supports controlled runtime overrides. `ctai_push_hosts` extends exact supported push hosts; any addition requires a destination security review. None of these hooks accept arbitrary public request parameters.
