# Implementation plan

Architecture approved on 2026-11-11. The owner approved a scope revision on 2026-11-15: transactions cover expenses and revenues, categories are separated by type, existing permissions and ordinary save/error feedback remain, and the development database will be reset without compatibility migrations. Maximum intended users: 20. SPEC.md owns requirements; README.md owns setup/environment/operations.

Milestones 1–4 have owner-authorized Git checkpoints. Milestone 5 and the revised transaction scope are implemented and verified locally. The owner reported a quick test with no apparent issues and authorized the milestone commit/push on 2026-11-15. Device/scenario details were not specified. No production changes have been executed. Implementation and review are AI-only.

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

Transaction/attachment deletion is permanent after explicit confirmation. Rows/audits commit before physical cleanup; failed transactions preserve files. One file consistency lock covers mutations and future backup staging; failed/orphan cleanup is retried conservatively by reconciliation after a 24-hour grace period. No public storage symlink. Concurrent limit and database/filesystem/audit failure cases are tested.

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

Milestone checkpoint checks (2026-11-15): final full PHP suite **244 tests / 1677 assertions passed**; Pint, production asset build, Composer strict validation, whitespace checks and Composer/npm audits pass with zero known vulnerabilities. The earlier full 16-browser suite plus affected desktop/mobile reruns cover the final UI/CLI refinements above. Logs: .artifacts/m5-checkpoint-*. Milestone 6 remains pending discussion/authorization; no backup implementation or production changes were started.

## 6. Security, backups and reliability — pending

Apply and record OWASP ASVS 5.0 Level 1 controls, production cookie/header/CSP/error/proxy/upload throttling review and least permissions. Implement coherent SQLite online snapshot plus referenced immutable attachments, hash/integrity/FK checks, private temporary staging and cleanup, verified Google Drive transfer/retention (latest 30 successful backups), synchronous SMTP failure alerts and isolated restore.

Timer: daily 03:15 Europe/Luxembourg, catch-up after missed run; one attempt, bounded timeouts, no network under consistency lock. Separate credentials/APP_KEY from backups, deduplicate failure alerts/retry delivery and handle signals/killed processes/orphans. Use mocks/isolated destinations, never live production data. Test actual disposable WAL snapshots during writes and failure/restore paths. Owner must separately verify actual Drive/SMTP/restore results. No persistent local archives.

## 7. Raspberry Pi deployment preparation — pending

Owner-run diagnostics and exact reviewed additive commands only; agent never SSHs to the Pi. Resolve maintained patched PHP 8.4/ARM64/extensions and a PHP-linked SQLite library containing the WAL-reset fix. CLI SQLite version alone is insufficient. Verify actual connection PRAGMAs, FPM parser permissions/resource limits and real concurrent behavior on SSD.

Prepare isolated identities, dedicated FPM socket/pool, read-only releases and private writable state, systemd scheduler/backup/failure-alert units and host-only Caddy configuration. Preserve existing sites, CrowdSec/firewall and IPv4-only policy. Inspect client-IP/log acquisition and align upload body limits. Validate configs, provide rollback and leave production execution to the owner. Preserve confirmed hostname and /srv/axeyx-expenses and /var/lib/axeyx-expenses paths.

## 8. Final validation and production release gate — pending

Run full code/security/dependency/browser checks and review security-sensitive implementation/deployment with a separate AI agent that did not implement it. Record actual evidence and unresolved limits accurately. Owner checks HTTPS, blocked IPv6, all roles/MFA/private files, concurrent reads/writes, coherent restore, backup schedule/retention/failure mail, CrowdSec detection/enforcement and existing-site regression.

Do not call this production-ready until patched runtime, backup/restore and all infrastructure checks pass. Quarterly restore rehearsal and measured recovery time remain operational requirements.

## Remaining owner inputs

Only at deployment/backup preparation: remaining sanitized host/logging/proxy/firewall excerpts, DNS/router/CDN details, private Drive/OAuth/SMTP provisioning and separate secret recovery storage. No credentials in chat or Git. Completed initial Pi diagnostics are summarized in README.md; do not repeat them without a concrete gap.
