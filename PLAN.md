# Implementation plan

Status: architecture review; owner approval required before implementation. Complete and verify each milestone before continuing; update this file with results. Code and review are AI-only, per AGENTS.md.

## Planning evidence

- [x] Inspect local tools and review owner-supplied Pi diagnostics (2026-11-10); current findings and accepted deployment choices are in README.md.
- [x] Specify architecture, schema, authorization, workflows, backups and acceptance criteria in SPEC.md.
- [x] Consolidate project documents (2026-11-11), removing superseded diagnostics, repeated requirements and planning history.
- [ ] Obtain architecture/security/backup/milestone approval before scaffolding or dependency installation.

No application, dependency lockfiles, tests or deployment scripts exist yet. Configuration evidence still needed for deployment does not block local work after approval.

## 1. Foundation

- [ ] Scaffold Laravel 13 inside this repository without replacing planning documents or .git; review Composer/npm hooks before execution. Keep all caches/browser binaries within project paths.
- [ ] Resolve current stable Filament 5 >=5.11.2, compatible Livewire 4/Reverb, and framework-skeleton PHPUnit version; commit lockfiles. Recheck advisories at install time. No development branches or ignored platform requirements.
- [ ] Resolve for the proposed production PHP 8.4 patch rather than local PHP 8.5 alone; verify platform requirements/tests on actual PHP 8.4 before deployment without changing global Herd configuration.
- [ ] Configure SQLite/file sessions/file cache/database queue/private disks, Vite assets and one Filament panel.
- [ ] Add schema migrations, database constraints, enums and initial factories, with no default production credentials.
- [ ] Verify WAL/busy timeout/FULL/foreign keys on all connection types; create isolated test and E2E databases and local-only environment guards.
- [ ] Configure PHPUnit/Livewire test harness, Playwright with project-local browser cache, Pint, npm build and dependency audits.

Completion: clean install/migrate/build and framework smoke tests pass; platform requirements are verified; panel loads locally; lockfiles and setup docs are reproducible. Browser smoke test does not establish auth/security completion.

## 2. Authentication and roles

- [ ] Built-in login/logout/profile, required TOTP enrollment/challenge/recovery, secure sessions and throttles; no registration/invitations/email reset/user-management UI.
- [ ] Implement all six CLI commands in SPEC.md using hidden password prompts; audit relevant changes and protect output.
- [ ] Policies with strict authorization; deny inactive/enrollment-only sessions across panel/Livewire/download/channel routes.
- [ ] Credential/session version revocation and remember-token rotation; explicit socket revocation behavior finalized with milestone 6.
- [ ] Password/MFA/profile confirmation and replay/concurrent-recovery tests; every role, anonymous and disabled-account security cases.

Completion: automated auth/role tests and Playwright login/MFA/recovery/logout pass; crafted requests cannot bypass enrollment, change roles or reuse revoked sessions. CLI commands do not expose secrets.

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
- Production release requires separate AI review and owner-run verification; neither has occurred.

## Latest verification

2026-11-11: all four documents passed whitespace/fence, local link/anchor and planned shell syntax checks. Shell blocks were not executed. No application tests/builds ran; implementation has not started.
