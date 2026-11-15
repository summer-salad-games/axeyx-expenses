# Implementation plan

Architecture approved on 2026-11-11. The owner approved a scope revision on 2026-11-15: transactions cover expenses and revenues, categories are separated by type, existing permissions and ordinary save/error feedback remain, and the development database will be reset without compatibility migrations. Maximum intended users: 20. SPEC.md owns requirements; README.md owns setup/environment/operations.

Milestones 1–6 are implemented and verified locally with owner-authorized Git checkpoints. The owner authorized the milestone 6 commit/push on 2026-11-15. Production deployment and release validation remain milestones 7–8. No production changes have been executed. Implementation and review are AI-only.

## 1. Foundation — completed locally

Laravel 13, Filament 5 and Livewire 4 monolith; stable locked dependencies resolved against PHP 8.4. Native Filament French light UI, ProxyMed branding, responsive login/error pages, SQLite integrity constraints and connection PRAGMAs, private file/cache/session paths, scoped audit records, strict authorization and disposable test isolation.

Initial verification covered disposable migrations/PRAGMAs, formatting/build/audits and desktop/mobile shell checks. Actual PHP 8.4/ARM64 runtime verification is a production gate; local PHP is 8.5.12. Starter checkpoint tag: starter-auth-v1, at 15d2298.

## 2. Authentication and roles — completed locally

CLI account creation/list/role/disable/password/MFA reset; hidden credential prompts, Argon2id, required TOTP, encrypted secrets/hashed recovery codes, replay and recovery concurrency protection. Server-side account version/MFA proof revokes stale sessions after account changes. Login limits cover email/IP and aggregate IP, with sanitized failure logging.

Separate resumable onboarding at /setup/mfa, /setup/password and /setup/complete; shield/key/rocket progress, pinned header and sign-out. Initial enrollment does not repeat the temporary password; re-enrollment after reset requires confirmation. Temporary CLI passwords always require a different chosen password. Minimum 12 characters, spaces/accents allowed, no composition rule. Normal account settings use active sidebar navigation, no duplicate profile link. Idle timeout 120 minutes, no remember-me.

Verification at checkpoint: 108 PHP tests / 987 assertions and 10 desktop/mobile browser checks, formatting/build/audits. Coverage includes onboarding/resumption, tampering, revoked sessions, TOTP replay and real two-process recovery races. Separate read-only AI reviews found and rechecked enrollment-code consumption and final-login revocation fixes. This is AI review, not an independent human audit. Owner authorized checkpoint 2026-11-12.

## 3. Transactions and categories — revised locally

Native list/create/edit/details, shared visibility, editor-own/admin-all mutations and viewer read access. Searchable selectors, type/category/date/creator filters, signed sorting, pagination and mobile stacked rows. Exact positive millime magnitudes, explicit expense/revenue type and signed individual display. Type change clears category; both service validation and composite foreign key enforce matching types. Only active categories can be newly selected; an edit may retain its current disabled category. Server-owned creator/storage fields remain protected.

Shared category table with a Type column/filter and immutable category types. Names normalize Unicode/case and are unique within type. Twenty medical/operating expense defaults and three owner-approved revenue defaults: Examens médicaux, Consultations médicales, Autres recettes. Idempotent seeding preserves custom/disabled records and audits only additions. Unused category deletion is confirmed and rechecks references transactionally; used categories may only be disabled.

The amount input selects text, rejects invalid typed/pasted characters and excess precision, accepts comma/point and displays French comma. Create-another clears amount; table descriptions truncate at 20 characters. Initial expense/category checkpoint passed 165 PHP tests / 1244 assertions and 12 browser checks; owner reported no alarming issues and authorized commit/push 2026-11-12. Revised scope is included in the current verification below.

## 4. Private attachments — completed locally, integrated with transactions

Private JPEG/PNG/WebP/PDF uploads with bounded MIME/extension/signature/structure checks, a resource-limited parser process, 16-megapixel/500-page ceilings and aggregate limits (10 files, 10 MiB/file, 50 MiB/transaction). Filename/progress only during upload; saved images have thumbnails. All attachments use authenticated /attachments/{id}/view for Ouvrir, with separate Télécharger and confirmed Retirer. Policies, fixed private paths, sandbox/no-store/nosniff and safe response names apply.

Transaction/attachment deletion is permanent after explicit confirmation. Rows/audits commit before physical cleanup; failed transactions preserve files. One file consistency lock covers mutations and backup staging; failed/orphan cleanup is retried conservatively by reconciliation after a 24-hour grace period. No public storage symlink. Concurrent limit and database/filesystem/audit failure cases are tested.

Milestone 4 verification: 205 PHP tests / 1518 assertions, 14 desktop/mobile browser checks, Pint/build and zero Composer/npm advisories. Separate read-only AI review reported no remaining actionable blocker. Owner validated attachment workflows and authorized commit/push 2026-11-13; checkpoint 1d8616042af4897bcb5cc2c7ad0ed1f24141e58b. This revision uses a fresh schema; historical development migration/purge compatibility has been removed as requested.

## 5. Financial statistics and scope revision — completed locally, checkpoint approved

Cards: Recettes, Dépenses, Net de la période (revenues minus expenses), transaction count. Time/person charts compare directions; category charts remain separate. Gross direction totals stay positive under explicit labels; net can be negative and is not a bank balance. Exact sums/counts avoid attachment multiplication, float conversion and silent overflow.

Shared inclusive Luxembourg period: Today, Monday–Sunday week, current/last month, year, custom. Daily buckets through 31 days, monthly thereafter, zero filling, exact accessible tables/tooltips, persisted browser-session selection and invalid/empty states. Owner approved custom periods up to 120 calendar months. Reports use one short DEFERRED WAL snapshot, no storage lock or report polling.

Removed unused event/policy, application notification tables, socket packages/configuration, queued-job tables and background-worker infrastructure. Framework synchronous dispatch and ordinary form success/error messages remain. No data migration: owner runs local reset when ready; current owner accounts/database/uploads have not been reset by the agent.

Scope verification (2026-11-15, before the UI refinements below):
- Full PHP suite: **243 tests / 1662 assertions passed**, including mixed-direction reports for every role, positive/zero/negative net, typed-category matching/uniqueness/tabs, signed formatting at integer boundaries, snapshot consistency and absence of application job/notification tables.
- Twenty independent PHP-process clients passed concurrent mixed writes/read snapshots; final count 20 with exact balanced totals. This is local service coverage, not a 20-browser or production-Pi benchmark.
- Full Playwright suite: **16 desktop/mobile checks passed** (Chromium desktop and mobile emulation). Coverage includes MFA/onboarding, stale/revoked sessions, transaction/category/attachment workflows, expense-to-revenue selection clearing, category management, shared reports, custom/invalid/empty periods, daily/monthly exact values and no application WebSocket connections. Dashboard screenshots were visually inspected on desktop/mobile.
- Pint, Vite production build, Composer strict validation/platform checks on local PHP 8.5.12, and git diff whitespace checks pass. Composer/npm audits report **zero known vulnerabilities**.
- Earlier browser runs had stale label/role assertions and fixture cleanup cascades. Category navigation and a PHP query-parameter fixture issue were corrected; the scope suites passed.
- A separate read-only AI agent reviewed financial/type integrity, Policies/MFA, attachment consistency and removed infrastructure. No remaining actionable security/correctness blockers; category redirect and documentation follow-up confirmed. This is AI review, not an independent human audit; the integrating agent ran the suites.
- Logs: .artifacts/transactions-php-final.log, transactions-e2e-final.log, transactions-pint-check.log, transactions-build-final.log, transactions-composer-validate.log, transactions-platform.log and transactions-composer-audit.log / transactions-npm-audit.log.
- Owner database/accounts/uploads have not been reset by the agent. The owner reported a quick test with no apparent issues and authorized commit/push on 2026-11-15; device/scenario details were not specified. Patched PHP-linked SQLite and actual PHP 8.4/ARM64 verification remain production gates.

Completion: full applicable PHP/browser checks, formatting/build/audits and scoped AI review pass; README/SPEC/PLAN match behavior; owner receives simple reset/test instructions. Do not continue to milestone 6 without a request.

### Owner UI refinements — 2026-11-15

Transaction forms now use a searchable Type select beside Montant; Catégorie and Date de la transaction share the second row, stacking in that order on mobile. Changing type still clears category. Passed 34 transaction PHP tests / 259 assertions, four desktop/mobile transaction/statistics browser checks, Pint and production build (.artifacts/type-select-*).

Category management now shows all categories in one table. The Type column and searchable filter display Dépense or Recette without signs. The owner explicitly chose to remove the top tabs; create/edit return to the unfiltered list. Passed 34 transaction/category PHP tests / 261 assertions, Pint and build. Both desktop/mobile category browser workflows pass (.artifacts/category-type-e2e-final.log), covering mixed type display, searchable filtering, category creation and the existing transaction/authorization flows. The initial test assumed a newly created category was on page one; searching before selection fixed that harness assumption.

Type-label follow-up: shared type options now display Dépense/Recette without signs across forms and filters; the sign before Montant is retained. Passed 34 PHP tests / 261 assertions, all four desktop/mobile transaction/statistics browser checks, Pint and whitespace checks (.artifacts/type-labels-*). No asset/dependency changes.

Transaction-detail layout: Description now sits to the right of Créée par on desktop and follows it on mobile. Passed 34 PHP tests / 261 assertions, both desktop/mobile transaction browser workflows, Pint and whitespace checks; desktop detail screenshot visually verified (.artifacts/description-layout-*). No asset/dependency changes.

### English command-line tools — 2026-11-15

Account commands and attachment reconciliation use English help, argument/option descriptions, prompts, table headers/status, confirmations and errors. Console lifecycle events select English for framework validation and restore the preceding locale, including nested commands; web messages remain French. Existing standalone scripts already use English operational output. Shared storage-busy feedback is localized for CLI/web. SPEC/README/AGENTS record the language rule for future scripts.

Verification: full PHP suite 243 tests / 1664 assertions passed; after adding explicit console-lifecycle/locale-restoration coverage, all 15 account-command tests / 168 assertions passed. Laravel disables command lifecycle events during tests by default; the new regression enables the actual lifecycle. All 10 desktop/mobile authentication/foundation browser checks pass, confirming French login/onboarding/error pages. Pint and whitespace checks pass. Logs: .artifacts/cli-english-php.log, cli-english-commands.log, cli-english-e2e.log and cli-english-pint.log. No dependency or asset changes.

Milestone checkpoint checks (2026-11-15): final full PHP suite **244 tests / 1677 assertions passed**; Pint, production asset build, Composer strict validation, whitespace checks and Composer/npm audits pass with zero known vulnerabilities. The earlier full 16-browser suite plus affected desktop/mobile reruns cover the final UI/CLI refinements above. Logs: .artifacts/m5-checkpoint-*. Milestone 6 was authorized after this checkpoint; production changes remain pending.

## 6. Security, backups and reliability — completed locally, deployment checks pending

Authorized 2026-11-15. Owner chose unencrypted full archives, one standard restore that resets every account's authentication, and no email notifications. Daily systemd schedule is 03:15 Europe/Luxembourg with catch-up, 30 verified completed Drive backups and no permanent local archives. No current owner accounts, keys, database or uploads were reset by this implementation.

Implemented SQLite online snapshot plus exactly its referenced immutable attachments under the existing consistency lock; sanitation, compression and all network operations happen after releasing that lock. Sanitize only the copy, discard all usable credentials/MFA/recovery data and VACUUM to remove free-page remnants. Validate native schema fingerprint, integrity/FKs, counts, file sizes and SHA-256. Logical WAL-page sizing, reserve-space checks, bounded archive expansion/entries, private subprocess scratch, exclusive private writes and synchronized file/directory metadata bound failure exposure.

Rclone transfer uses downloaded content verification before publishing/read-checking a completion marker. Scoped retention preserves the newly verified attempt, removes only eligible app attempt directories and treats retention errors as failures. Private CLI status records latest attempt/stage and last success; native supervision records bootstrap failures and finalizes abandoned work under operation locks. A pending restore journal blocks backup execution. Daily reconciliation replaces the separate Laravel scheduler. No SMTP settings, email helpers or notification timers remain in application configuration/code. The unused stock config/mail.php was removed; Laravel retains its own general framework mail support. Account-security tests explicitly assert that no email is sent (6 tests / 60 assertions passed after removal; .artifacts/mail-removal-check.log).

Restore supports listing/verification without replacement, offline acknowledgment and explicit confirmation. It verifies privately before cutover, preserves runtime ownership/modes and a canonical deployment environment target, replaces DB/files/key with durable rollback state, clears transient authentication/cache state and records an operator audit. A fresh process can recover interrupted switches while maintenance stays active. Every restore creates a fresh APP_KEY, invalidates all passwords/sessions/MFA/recovery codes and forces full onboarding; IDs/roles/disabled status and financial records/files remain. Individual hidden CLI passwords grant access afterward. A corrupt current DB and absent old key are supported.

Application hardening adds compatible browser headers/CSP, HTML no-store and origin cache/storage cleanup on logout, opt-in host-only HSTS, production session-cookie prefix, 60 authenticated upload requests/minute/account, upload free-space reserve, daily 14-day log rotation and an offline 3,000-entry common-password check. Passphrases still require 12 characters with no composition rule. The blocklist source/license/digest are in resources/security/NOTICE.md; no external password lookup is performed.

### Verification — 2026-11-15

- Full PHP suite: **277 tests / 1851 assertions passed**. Backup coverage includes real disposable WAL writes during snapshots, coherent files, source authentication unchanged, forged schema constraints, unsafe/link/unlisted archive members, transport corruption/verification/retention, bootstrap failure, killed processes, orphan cleanup, space checks, interrupted cutovers at five stages, synchronization failure, runtime modes/environment symlinks, corrupt-current-DB restore and confirmation/cancel/verify-only behavior.
- Full Playwright suite: **18 desktop/mobile checks passed**, including the final header/password follow-up. Restore workflows verify old password/session rejection, fresh MFA/password/completion onboarding, preserved financial totals/document access and disabled-account denial. Browser data and remote transport are disposable project-local fixtures.
- Pint, production build, Composer strict validation/platform checks and dependency audits pass; Composer/npm report **zero known vulnerabilities**. Local PHP is 8.5.12. Actual PHP 8.4/ARM64 and patched PHP-linked SQLite are not verified here.
- Separate read-only AI security review resolved findings about private first-byte writes, durable journal/rollback deletion, atomic environment replacement, ownership/modes, trusted actual schema, WAL-aware free-space budgeting, private parser scratch and abandoned-work cleanup. Re-review reported no remaining actionable findings; this is AI review, not an independent human audit. The integrating agent ran the suites; the reviewer did not run infrastructure tests.
- Early verification exposed expected attachment-mode assertions, missing disposable environment warnings and browser fixture isolation/auth-version issues; these were corrected. Final PHP run has no warnings. No real Google Drive upload, OAuth consent, systemd installation or production action occurred.
- Evidence: .artifacts/m6-php-final.log, m6-browser-final.log, m6-full-browser.log, m6-pint-final.log, m6-build-final.log, m6-composer-validate.log, m6-platform.log, m6-composer-audit.log and m6-npm-audit.log.

### ASVS 5.0.0 Level 1 review baseline

Reviewed against the [official 5.0.0 requirements](https://github.com/OWASP/ASVS/tree/v5.0.0/5.0). This is a scoped engineering assessment, not an ASVS certification or a claim that deployment controls have passed.

| Applicable area | Implementation/evidence and remaining boundary |
| --- | --- |
| V1 encoding/injection | Escaped Blade/Filament plain text, parameterized Eloquent/native statements, argument-array subprocesses and archive path allowlists. Framework Alpine expressions remain controlled templates; compatible CSP permits inline/eval and is not a strict CSP. |
| V2 validation/workflows | SPEC input contracts; money/category/schema constraints and service validation; ordered, server-enforced resumable onboarding. Transaction, onboarding, policy and backup adversarial tests. |
| V3/V4 browser/HTTP | CSRF/safe mutation methods, no cross-origin application API, explicit content types, sandboxed private attachments, no-store and tested headers/logout cleanup. Secure cookies, host prefix, HSTS and real TLS/proxy behavior require production verification. Browser support for Clear-Site-Data varies; it does not promise proactive removal from an already-open offline tab. |
| V5 files | Private non-executable storage, generated paths, MIME/extension/parser/size/count checks, authenticated upload throttling; malicious uploads and archive tests. Caddy/PHP resource/path limits remain deployment checks. |
| V6 authentication | Required MFA, 12-character composition-free passwords, local common-password rejection, current-password confirmation, masked/paste-compatible fields, no default accounts or knowledge questions. Login/MFA throttles and compulsory temporary-password replacement tested. |
| V7 sessions | Native random encrypted file sessions, login rotation, logout/idle expiry, auth-version revocation after role/credential/status changes; stale/independent-browser and restore tests. Actual production cookie attributes remain a release check. |
| V8 authorization | SPEC matrix; strict Policies/service/route/Livewire checks including protected fields, disabled users and direct attachment access; all-role tests. |
| V11/V14 cryptography/data | Native authenticated Laravel encryption for MFA/sessions, Argon2id, secure random keys/tokens, no secrets in URLs/CLI args/logs/archives; sanitized snapshot and revoked credentials tested. Owner explicitly accepts unencrypted private financial archives. Host secrets/permissions and authenticated browser-cache behavior remain deployment checks. |
| V12/V13 communication/deployment | Intended host-only HTTPS, dedicated private credentials and read-only releases are documented/templates only. Real certificates/TLS, source metadata exposure, proxy trust and systemd identities are pending owner verification. |
| V15 components/fields | Locked stable packages, zero-known-advisory audits, hidden server/auth fields and deliberate serialization. Review updates monthly and before releases; remediate exploitable critical issues before exposure/within 24 hours, high issues within 7 days, other applicable issues within 30 days. Affected deployment must remain offline if a required fix cannot be applied safely. |
| Not applicable to this application | No XML/WYSIWYG input, WebSockets, public API/JWT, OAuth authorization server or application federation. Rclone is a separate OAuth client; its real provider consent/token permissions still require verification. |

Remaining release gates: real Drive OAuth/scope, transfer/download verification, retention/trash/quota/failure-status behavior; systemd validation/schedule/cgroup cleanup; UID/GID/umask/WAL permissions and resource limits on ext4; actual PHP 8.4/ARM64/patched SQLite; controlled offline restore rehearsal and measured recovery time. No physical power-loss guarantee is inferred from process-kill tests. Exact installation/rollback commands and owner evidence belong to milestone 7; do not start it without a request.

## 7. Raspberry Pi deployment preparation — pending

Owner-run diagnostics and exact reviewed additive commands only; agent never SSHs to the Pi. Resolve maintained patched PHP 8.4/ARM64/extensions and a PHP-linked SQLite library containing the WAL-reset fix. CLI SQLite version alone is insufficient. Verify actual connection PRAGMAs, FPM parser permissions/resource limits and real concurrent behavior on SSD.

Prepare isolated identities, dedicated FPM socket/pool, read-only releases and private writable state, systemd backup units and host-only Caddy configuration. Preserve existing sites, CrowdSec/firewall and IPv4-only policy. Inspect client-IP/log acquisition and align upload body limits. Validate configs, provide rollback and leave production execution to the owner. Preserve confirmed hostname and /srv/axeyx-expenses and /var/lib/axeyx-expenses paths.

## 8. Final validation and production release gate — pending

Run full code/security/dependency/browser checks and review security-sensitive implementation/deployment with a separate AI agent that did not implement it. Record actual evidence and unresolved limits accurately. Owner checks HTTPS, blocked IPv6, all roles/MFA/private files, concurrent reads/writes, coherent restore, backup schedule/retention/failure status, CrowdSec detection/enforcement and existing-site regression.

Do not call this production-ready until patched runtime, backup/restore and all infrastructure checks pass. Quarterly restore rehearsal and measured recovery time remain operational requirements.

## Remaining owner inputs

Only at deployment/backup preparation: remaining sanitized host/logging/proxy/firewall excerpts, DNS/router/CDN details, private Drive/OAuth provisioning and separate service-credential recovery storage. No credentials in chat or Git. Completed initial Pi diagnostics are summarized in README.md; do not repeat them without a concrete gap.
