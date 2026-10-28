# Current candidate status

WordPress plugin **0.2.1**, native Android app **0.1.1**. This change replaces the PWA with a dedicated Android app. The browser dashboard, customer widget, leads, business contact links, private photos and optional OpenAI backend remain available.

## Verification

- 91 existing backend integration checks, including customer/agent isolation, state transitions, atomic retries, private images, retention, AI orchestration/budgets and browser schedule repair.
- 47 native backend checks: browser-approved verifier pairing, encrypted token retries, bearer scope, revocation, Firebase file validation, private-key protection, RSA OAuth signatures, high-priority generic FCM messages with compatible opt-in system display, worker delivery, expired registrations, in-place device schema upgrades, waiting-queue message alerts, idempotent token refresh and scheduler/queue-age diagnostics. Firebase HTTP responses are deterministic substitutes; no live Google account is contacted.
- 14 browser workflows exercise customer chat, agent replies/private notes, photos, emoji/links, mobile layout, pairing approval and PWA retirement. Retirement checks confirm only CrystalTap caches are deleted and no push/fetch handlers remain.
- The signed Android APK builds successfully; ten Java unit tests pass, lint passes with warnings, and APK signature verification passes. Three instrumented device checks compile but were not executed: software-only API 35 and API 28 emulator boot attempts did not complete in this cloud without KVM. No audible-device or physical-speaker test is claimed.
- Production ZIP and GitHub-style ZIP fresh-install tests check 20 plugin tables, native pairing, private image processing and public contact configuration.

An Android build, synthetic FCM response or provider acceptance is not physical-device delivery evidence.

## Outstanding deployment gates

The user reports that Firebase is configured and that alerts work intermittently. This workspace has no authenticated access to inspect the live Firebase project, Namecheap scheduler or phone. Register `com.crystaltap.agent` and upload matching Android/client and service-account files through HTTPS administrator settings when configuring another deployment. Those credentials were not fabricated and are not present in the APK/source. Real Firebase delivery, Google Play Services behavior and custom sound on the POCO C65 remain unverified until configuration and device tests are completed.

The live website has not been updated by this workspace. Verify site TLS, authorization headers, hosting scheduler, quiet hours, cache exclusions, PHP GD/upload limits, WordPress/theme/plugin compatibility, data backup/restore and upgrade/rollback on the actual host. Namecheap scheduling limits can delay server-side alert dispatch.

Browser sessions and native sessions share capability checks. Keep signing keys and WordPress salts backed up securely. Do not label the system flawless or production-certified from local automated results.
