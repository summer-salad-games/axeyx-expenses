# Implementation plan

Status: approved by the owner on 2026-11-11 ("I validate everything"); milestones 1–2 implemented and locally verified. Milestone 2 implementation and automated verification are complete as of 2026-11-12. Owner authorized the milestone 2 commit/push on 2026-11-12; detailed owner-run desktop/mobile edge-case results have not been separately reported. Next implementation milestone: 3, expenses/categories, when requested. Complete and verify each milestone before continuing; update this file with results. Code and review are AI-only, per AGENTS.md.

## Planning evidence

- [x] Inspect local tools and review owner-supplied Pi diagnostics (2026-11-10); current findings and accepted deployment choices are in README.md.
- [x] Specify architecture, schema, authorization, workflows, backups and acceptance criteria in SPEC.md.
- [x] Consolidate project documents (2026-11-11), removing superseded diagnostics, repeated requirements and planning history.
- [x] Owner approved architecture/security/backup/milestones on 2026-11-11.

Laravel/Filament foundation, lockfiles, schema and isolated test harnesses now exist. Production runtime/configuration checks remain deferred to deployment preparation.

## 1. Foundation

- [x] Scaffold Laravel 13 inside this repository without replacing planning documents or .git; review Composer/npm hooks before execution. Keep all caches/browser binaries within project paths.
- [x] Resolve stable Filament 5 >=5.11.2, compatible Livewire 4/Reverb and framework-skeleton PHPUnit; generate lockfiles. Recheck advisories at install time. No development branches or ignored platform requirements.
- [x] Resolve against the conservative PHP 8.4.0 platform floor; current machine passes platform checks on PHP 8.5.12.
- [x] Include foundation/UI source and dependency lockfiles in the milestone 1 Git checkpoint (2026-11-11).
- [ ] Deployment gate: execute platform checks/tests on actual PHP 8.4; no such binary is installed locally and Herd/global configuration was not changed.
- [x] Configure SQLite/file sessions/file cache/database queue/private disks, Vite assets and one Filament panel.
- [x] Add schema migrations, database constraints, enums and initial factories, with no default production credentials.
- [x] Verify WAL/busy timeout/FULL/foreign keys on file-backed Laravel connections and reconnects; create isolated test/E2E databases and local-only guards. Worker/backup integration checks follow their milestones.
- [x] Configure PHPUnit/Livewire test harness, Playwright with project-local browser cache, Pint, npm build and dependency audits.

Local result: schema migrated, panel/login shell loads in French, lockfiles/config/private storage and test harnesses implemented. No accounts are seeded. Authentication was closed at the foundation checkpoint and is implemented in milestone 2. Actual PHP 8.4 execution remains a deployment gate.

## 2. Authentication and roles

Status: complete for local implementation and automated verification (2026-11-12). Production runtime, CrowdSec integration and live socket revocation remain assigned to their later milestones.

- [x] Built-in login/logout/profile, required TOTP enrollment/challenge/recovery, secure sessions and throttles; no registration/invitations/email reset/user-management UI.
- [x] Implement all six CLI commands in SPEC.md using hidden password prompts; audit relevant changes and protect output.
- [x] Expense/category/attachment/notification Policies, explicit dashboard/security-page authorization and strict Filament authorization. Deny inactive/enrollment-only sessions across current panel/Livewire routes; download/channel wiring is verified when those routes are implemented in milestones 4/6.
- [x] Credential/session version revocation and remember-token rotation; after-commit revocation event ready for socket integration in milestone 6.
- [x] Password/MFA/profile confirmation and replay/concurrent-recovery tests; every role, anonymous and disabled-account security cases.

Completion: automated auth/role tests and Playwright login/MFA/recovery/logout pass; crafted requests cannot bypass enrollment, change roles or reuse revoked sessions. CLI commands do not expose secrets.

Implementation: Laravel Argon2id hashing, Filament MFA with encrypted secret/hashed encrypted recovery codes, file-cache replay/recovery locks, per-email/IP and aggregate-IP login limits, native per-account MFA limits. Server-only session proof binds account/version/MFA completion; account mutations rotate versions and remember tokens atomically. Password/profile and recovery-code changes retain only the confirmed current session. Password/MFA failure logs whitelist factor, internal user ID and validated IP.

Verified 2026-11-11: 108 PHPUnit tests/987 assertions and ten desktop/mobile Playwright checks pass. Coverage includes all three roles, anonymous/disabled/incomplete-onboarding access, CLI validation/hidden prompts/audits, password confirmation/tampering, MFA enrollment/login/logout, TOTP replay, recovery reuse and a real two-process recovery race, account revocation during the final MFA request, stale HTTP/Livewire sessions and recovery regeneration. Browser checks complete the dedicated setup flow, password selection, final confirmation, TOTP/recovery logins, sign-out/resumption between setup steps, password change/current-session preservation and logout. Desktop/mobile setup/security-page screenshots were visually inspected. Recovery selection submits without a blur-request race and requires a recovery code instead of the hidden OTP field.

A separate AI agent reviewed authentication/session/MFA/CLI/profile/Policies. It found premature enrollment-code consumption and a final-login version-revocation race; both were fixed with regression tests and confirmed resolved on re-review. No additional actionable blockers were reported. This was scoped read-only AI review; suites were executed by the integrating agent, not the reviewer. Login keeps a one-step TOTP clock window; enrollment expiry has a French fresh-code retry instruction. Actual PHP 8.4 and production verification remain pending.

Owner test feedback (2026-11-11): added required password onboarding after MFA, including CLI password resets as explicitly confirmed by the owner. The protected `must_change_password` flag defaults true; only a successful, different, strong user password clears it with audit/version revocation. Every role remains denied application data and recovery regeneration until completion. Migration preserves existing accounts only when their latest password-change audit proves a self-confirmed choice; otherwise it requires password selection once. Applied the additive migration to the verified project-local development database without resetting data. Tests cover invalid/blank/reused passwords, flag/role tampering, MFA-first order, revoked sessions, CLI reset flow and migration backfill.

Account settings now use Filament's regular app layout, branded desktop/mobile header and navigation, separate password/MFA sections and stable visible password fields. The required password step has no skip/cancel action; completed users retain normal settings actions. The browser harness now waits for MFA activation's server response before navigating (the dialog closes optimistically), and scopes logout to the user menu. Final checks: 95 PHP tests, eight browser checks, Pint, asset build and both dependency audits pass; no known advisories. A separate read-only AI re-review found no actionable onboarding/security blockers. No dependencies or global/production configuration changed.

Navigation feedback (2026-11-11): **Paramètres du compte** is now a permanent gear-icon navigation item, with the same label in the page title and user-menu shortcut. Its `/profile` route receives native active-page styling and `aria-current="page"`; the dashboard is not selected there. Native original-request matching preserves the active state during Livewire updates. The distinct required-password onboarding title remains. Extended the existing desktop/mobile browser flow to verify dashboard/settings selection, drawer navigation in both directions and the user-menu shortcut; initial mobile harness handling was corrected to open the closed/inert drawer before interacting. Passed 95 PHP tests/921 assertions, eight browser checks, Pint, build and both dependency audits; desktop/mobile navigation screenshots visually inspected.

MFA 405 fix (2026-11-11): owner screenshot showed a Livewire error overlay while the address stayed on MFA setup. Local diagnostics identified a redirected **GET /profile** retaining `X-Livewire`; Filament treated it as a component update and attempted fake-request route matching without a snapshot method/path. Reproduced both profile/dashboard GETs as 405 before the fix, then 200 afterward. Early middleware removes only `X-Livewire` from actual GET/HEAD page requests, preserving `X-Livewire-Navigate` and all genuine POST update handling. Local-only 405 logs whitelist method/path/allowed methods/routing message/transport-marker presence, excluding query strings and request contents.

Added eight HTTP regression tests for redirected GET/HEAD, MFA/disabled-session restrictions, preserved Navigate headers, genuine signed component POSTs with real CSRF enforcement (invalid token 419, missing transport header 404, valid request 200), and local-only sanitized diagnostics. The browser onboarding test now waits for the actual background fetch redirect instead of manually navigating away, which had hidden the failure. Added desktop/mobile post-MFA-reset fixtures exercising the native Continue button. Final checks: 103 PHP tests/941 assertions, ten browser checks, Pint, build and both dependency audits pass. A separate read-only AI review found no blockers in the normalization, guards, diagnostics or regression tests; the integrating agent ran the suites. No database reset or production/global changes were made for this fix.

Dedicated setup UX (2026-11-11): moved onboarding to `/setup/mfa`, `/setup/password` and `/setup/complete`, with `/setup` dispatching to the next unfinished step (or the dashboard when complete) with one shared card/layout, MFA/key/rocket progress icons and a plain CSRF-protected sign-out action. No profile avatar or application navigation appears during setup. Owner confirmed the rocket step is a final “Tout est prêt” screen. The MFA page renders the native mounted setup-action schema inline, retaining authenticator-code validation, recovery-code flow, replay prevention and atomic persistence. Initial enrollment omits the repeated temporary password; later enrollment retains native current-password confirmation. The omission is server-derived from pending password selection and absence of prior enrollment/reset audits. Initial step/final-submit throttling uses the native user-scoped key and five-attempt budget. Password setup reuses the native profile form/save behavior under restricted setup authorization; ordinary settings require completed app access.

The new protected completion timestamp persists the final step across logout and is stamped/audited idempotently on entry to the app. CLI password/MFA resets clear final completion; existing completed security steps are preserved. The additive migration backfills only existing accounts with MFA and a chosen password, and was applied to the verified local development database without resetting data. Native OTP hints plus an explicit password-manager username hint and scoped neutral autofill styling address the last-digit blue background. Chromium forced-autofill paint was checked, with synthetic secrets masked in screenshots.

Verified 108 PHP tests/987 assertions, ten desktop/mobile browser checks, Pint, asset build and both dependency audits. Browser coverage checks initial enrollment without a password, invalid-code rejection and visible feedback, `/setup` dispatch, and signs out after MFA and again after password choice, reauthenticates and resumes the right step; it also exercises post-MFA-reset completion. HTTP tests reject stage skipping, forged completion fields and revoked completion actions, and verify idempotence/backfill. The invalid-code browser check exposed missing inline validation feedback: native modal partial rendering was replaced by full rendering on the MFA page, and the shared setup shell was moved inside each Livewire view root so headers/cards survive validation updates. Regression checks verify visible errors and retained shell. Separate read-only AI security review found no actionable blockers, including these refinements; test execution belongs to the integrating agent. No dependencies, global settings or production services changed.

Owner UX feedback (2026-11-11): removed the duplicate account-settings link from the top-right user menu while retaining left navigation, the protected profile route and logout. Password creation/reset/change now share a minimum of 12 characters without composition requirements; French text explains that spaces/accents are accepted. Added a sticky setup branding/sign-out header; the main header uses native Filament sticky positioning. Browser checks scroll every setup stage and the main app on desktop/mobile, verify the menu contents and complete onboarding/profile password changes using lowercase accented passphrases. Updated obsolete composition-rejection cases and verified the minimum boundary through account-service validation. 108 PHP tests/987 assertions, ten browser checks, Pint, Vite build and both dependency audits pass. Separate read-only AI review found no blockers. Session timeout remains 120 minutes without requests; no remember-me feature was added. Owner authorized the milestone checkpoint on 2026-11-12; detailed owner-run desktop/mobile results have not been separately reported.

## 3. Expenses and categories

- [ ] Exact TND value parsing/formatting and bounds with unit tests; server-owned creator/storage values.
- [ ] Filament expense list/details/create/edit/soft delete; shared visibility; search/date/category/creator filters, sort/pagination and destructive confirmation.
- [ ] Administrator categories create/edit/disable, case-insensitive uniqueness and active-choice validation with historical-category preservation.
- [ ] Expense/category Policies, audit entries, transaction boundaries and bounded busy retries.
- [ ] Test every relevant matrix cell, protected-field tampering and own/other deletion; Playwright editor/admin/viewer workflows.

Completion: expenses and categories work for each role, exact money and disabled/deleted rules hold, feature/security/browser tests pass and documentation reflects actual commands/UI.

## 4. Attachments

- [ ] Private temp/final storage, generated paths, allowlisted image/PDF validation, configurable per-file/count/total limits under concurrent edits.
- [ ] Safe authorized preview/view/download routes; Filament uploads/details/removal preserve existing attachments.
- [ ] Stage/commit/compensation, after-commit cleanup, consistency locking and conservative crash reconciliation.
- [ ] Adversarial uploads/paths/MIME, anonymous/role access, totals and partial failure tests; real image/PDF browser uploads and downloads.

Completion: required formats work privately, malicious/unauthorized requests fail, deleted expenses retain files, failure injection proves reconciliation and no referenced files are removed.

## 5. Statistics

- [ ] Shared dashboard state, presets/custom bounds/active period; default current month.
- [ ] Integer SQLite total/count/month/category/creator queries, empty/missing-month handling, exact chart labels and soft-delete exclusion.
- [ ] Test date boundaries/timezone, invalid ranges, no attachment join duplication, totals and cross-widget filter consistency.
- [ ] Playwright desktop/mobile/custom-range behavior and accessible controls.

Completion: exact expected fixture totals for every range and every role; all widgets agree and browser checks pass.

## 6. Notifications and real time

- [ ] Persist new-expense notifications to other active users exactly once under retries; no edit/delete/expense email notifications.
- [ ] Dedicated Notifications page, owner-only read/unread/all-read and live unread badge.
- [ ] Reverb private channels, minimal after-commit events, one database worker, loopback/origin configuration, Echo client.
- [ ] Preserve filters/ranges/unsaved edits; reconnect/focus catch-up and outage fallback. Finalize active socket revocation for administrative account changes.
- [ ] Unit/feature channel/ownership/rollback/idempotence tests and Playwright two-session notification/CRUD/statistics/reconnect tests with real local Reverb/queue worker.

Completion: receiving browser updates without manual refresh; disconnected browser catches up; unauthorized subscriptions and disabled sessions/sockets fail; no sensitive global payloads.

## 7. Security, backups and reliability

- [ ] Review ASVS Level 1 controls, middleware/rate limits/headers/session settings, debug/secrets/logging, file handling and dependencies; document residual risks.
- [ ] Build snapshot/attachment/manifest archive command and narrow rclone transfer/check/retention wrapper; keep staging temporary and protected.
- [ ] Build systemd backup/timer/cleanup/OnFailure helper and synchronous SMTP alert; dedup/retry by attempt ID; no database/queue dependency for alerts.
- [ ] Test actual disposable SQLite online backup, fake/isolated rclone, all failure stages/retention, signals/timeouts/abandoned staging and SMTP failure behavior.
- [ ] Implement and execute isolated restore rehearsal with hash/reference/integrity/role/MFA checks; document recovery secrets and stale job/session handling.

Completion: automated failure/cleanup/restore tests pass; no persistent local archives remain. Live Google Drive and mailbox delivery are still pending owner verification, never inferred from mocks.

## 8. Raspberry Pi deployment preparation

- [x] Review owner diagnostics and record the current environment in README.md.
- [x] Confirm hostname, base paths, reporting timezone and IPv4-only external access.
- [ ] Review remaining Caddy site/snippet/logging/drop-in, access-log sample, bouncer chain/origin settings and DNS/router/proxy details; establish existing-site regression baselines.
- [ ] Finalize patched PHP/extensions, service identities, private Drive/SMTP configuration and recovery ownership; configure the confirmed backup schedule. Owner configures secrets privately.
- [ ] Prepare exact owner-run installation/deployment/verification and rollback commands, additive Caddy host, dedicated FPM pool and systemd units/timers.
- [ ] Validate CrowdSec coverage/client IPs and any evidenced gaps; preserve existing infrastructure and test legitimate WSS/Livewire/upload behavior.

Completion: deployment and rollback instructions are tailored and reviewable. Production execution/results remain milestone 9 gates.

## 9. Final validation and production release gate

- [ ] Full PHPUnit/Livewire/security suites, Pint, Vite build, Composer/npm audit and Playwright critical workflows with recorded results.
- [ ] Separate security-sensitive implementation/deployment review by an AI agent that did not implement the changes under review; resolve findings. Record reviewer scope/evidence and identify this as AI review, not an independent human audit.
- [ ] Owner verifies staging/production platform permissions, HTTPS/WSS/private routes, app account MFA and queue/Reverb lifecycle.
- [ ] Owner verifies real dedicated Google Drive upload/check/retention, real received backup-failure email and controlled restore in isolation.
- [ ] Owner verifies CrowdSec acquisition/parsing/detection/enforcement and legitimate IPv4 web traffic, preserved IPv6 blocking/remediation, plus existing websites remain operational.
- [ ] Record release, migration/rollback outcome, restore rehearsal, residual risks and operational ownership in README.md.

Completion: every definition-of-done item in SPEC.md has evidence. Do not mark production ready while live infrastructure, separate AI review, backup delivery/restore or existing-site protection is unverified.

## Open decisions and deferred inputs

- Confirmed on 2026-11-11: French interface; backups daily 03:15 Europe/Luxembourg, retaining the latest 30 successful copies.
- Confirmed on 2026-11-11: accept decimal comma or point on amount entry; display French comma with exactly three fractional digits and TND.
- Deployment-only inputs: remaining infrastructure excerpts, production identities/packages, private Drive/SMTP setup and recovery-secret storage. See README.md.
- Production release requires the full security/deployment AI review and owner-run verification; the scoped foundation/authentication AI reviews do not satisfy that final gate.

## Latest verification — 2026-11-12

- Account shortcuts (2026-11-12): added six direct npm aliases for the existing `accounts:*` Artisan commands, documented argument forwarding and verified each shortcut with `--help`. No accounts were modified; no application/dependency changes or additional feature tests were needed.

- Local developer shortcut: `npm run dev:server` saves the loopback PHP server plus console/file logging settings in package.json. Verified startup on an alternate local port, a controlled GET-to-POST-only-route warning appearing alongside its HTTP request in the same console, and clean Ctrl+C shutdown. README documents the command and separate Vite watcher. No dependencies changed; no application feature tests were needed for this script-only change.

- Local reset shortcut: `npm run db:reset` runs `php artisan migrate:fresh && php artisan optimize:clear` directly, as requested by the owner. README documents its local-only destructive effect. Validated the npm script definition; the owner’s database was not reset. No dependencies changed.

- Stable resolution: Laravel 13.42.0, Filament 5.11.2, Livewire 4.4.8, Reverb 1.13.0, PHPUnit 12.5.43, Vite 8.3.7; exact Composer/npm dependency trees are locked.
- Passed: migrations on disposable local SQLite; 108 PHPUnit tests/987 assertions; ten desktop/mobile Playwright checks; Pint; Vite build; composer validate --strict and check-platform-reqs on PHP 8.5.12; Composer/npm audits with no known advisories. Applied the additive password/completion onboarding migrations to the existing local development database.
- Regression coverage: foundation constraints/integer sums/date-only storage/Unicode category uniqueness/reconnect PRAGMAs/private files/test isolation, plus milestone 2 authentication and authorization cases listed above.
- Separate AI foundation review found unsafe symlink/cache isolation; fixes and refusal tests were added and the reviewer confirmed both findings resolved. This is scoped AI review, not a human audit or full-release review.
- Test suites disconnect file-backed connections after teardown; the browser harness uses fresh random credentials and disposable app keys/data each run.
- Unverified: actual PHP 8.4 execution, production infrastructure and milestone 3–9 workflows, including active socket revocation. No Pi access, global configuration changes, production tests or uploads occurred.

UI refinement (2026-11-11): root-level login, ProxyMed branding, light-only flat colors and no top accent line. Mobile login/error pages are unboxed and full-width with larger controls; 404 remains centered. Foundation browser checks retain usable mobile-width coverage. Light-only behavior was also verified at 390px/1280px with both system dark preference and a previously saved dark choice.
