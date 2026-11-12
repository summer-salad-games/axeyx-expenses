# Small Business Expense Tracker

Status: owner-approved architecture and authoritative functional specification (2026-11-11). Reviewed 2026-11-11. Foundation and authentication/roles implemented locally; remaining workflows follow PLAN.md.

## 1. Scope

Self-hosted expense register for one company, approximately ten fixed users, shared visibility, desktop/mobile HTTPS access. One Laravel/Filament/Livewire application; SQLite alone; Reverb; native Linux on Raspberry Pi 5 ARM64 with SSD. Existing Caddy, CrowdSec and static sites must survive unchanged except explicitly reviewed additive integration.

Excluded: registration, invitations, user-management UI, multi-company/tenancy, invoicing, banking, payments, reimbursements, approval workflows, budgets, ERP/accounting, CSV export, native apps, public APIs, external identity providers, complex RBAC/analytics/cache infrastructure, containers, Kubernetes, Redis service, separate frontend, ordinary expense email, client-side backup encryption, persistent local backup archives.

## 2. Runtime and architecture

- Laravel 13, maintained Filament 5 >=5.11.2, compatible Livewire 4 and stable Reverb 1.x. Target Debian PHP 8.4 CLI/FPM; resolve stable dependencies for its selected patch, check advisories and verify on actual PHP 8.4. Commit lockfiles; never bypass platform requirements. Local runtime observations are in README.md.
- Filament is the entire UI/authentication shell: one panel, built-in login/profile/MFA, resources/tables/forms/widgets/charts. No custom auth stack or Fortify dependency unless a demonstrated gap requires review.
- Caddy terminates HTTPS and WSS, serves only public assets and index.php, passes PHP over an isolated FPM Unix socket and proxies WebSocket /app/* to Reverb on proposed 127.0.0.1:8081. Preserve CrowdSec on port 8080 and recheck 8081 availability before deployment. Server-side publish /apps/* remains internal; do not expose Reverb's publishing API unnecessarily.
- Owner-confirmed production hostname: expenses.axeyx.com, HTTPS/WSS with IPv4-only external access. Preserve existing IPv6 blocking; do not add public IPv6 access or an app AAAA record. DNS/router/CDN/proxy details remain unverified; confirm before deployment.
- Database queue with one worker; filesystem sessions and cache. One Reverb process with scaling disabled; no Redis server.
- Keep existing Caddy. Accepted app paths: /srv/axeyx-expenses/releases with a current symlink, and /var/lib/axeyx-expenses for shared database/attachments/session/cache. Configuration stays outside the web root. Runtime identities and permissions are finalized during deployment preparation.
- Deployed source is read-only to the runtime identity; only required storage/cache paths are writable. Caddy reads public assets and connects to the FPM socket, with no attachment/database/secret access. Separate backup credentials are readable only by the backup service identity. Build assets locally; Node is not a production service.
- UTC timestamps; expense_date is a date without time. The owner confirmed Europe/Luxembourg as the company reporting timezone; handle its daylight-saving changes, with Monday week starts and inclusive date bounds. The setting remains configurable.

## 3. Schema and integrity

All IDs are server-assigned. Required foreign keys restrict parent deletion unless stated otherwise. No account/category hard-deletion interface. Use indexes justified by ten-user queries, not speculative scale.

| Table | Columns and constraints | Indexes/notes |
| --- | --- | --- |
| users | id; name <=120; normalized email unique <=254; password hash; role CHECK administrator/editor/viewer; is_active default true; must_change_password boolean default true; onboarding_completed_at nullable timestamp; remember_token; auth_version integer default 1; app_authentication_secret nullable TEXT; app_authentication_recovery_codes nullable TEXT; created_at/updated_at | Framework-compatible MFA fields use maintained encryption/casts; never serialize secrets. auth_version revokes old file sessions on disable/password reset/MFA reset/role change. Password/completion onboarding state is server-owned. |
| categories | id; name <=100; protected Unicode NFC/casefolded name_key unique; is_active default true; created_at/updated_at | Names trimmed, case-insensitive uniqueness; disable preserves history. |
| expenses | id; expense_date DATE; amount_millimes INTEGER CHECK typeof integer and >0 and <=999999999999; category_id FK; description nullable plain text <=5000; created_by FK users; created_at/updated_at; deleted_at nullable | Index (deleted_at, expense_date), (category_id, expense_date), (created_by, expense_date). Creator immutable. Currency is constant TND, not an editable field. |
| attachments | id; expense_id FK; path unique; original_name <=255; mime_type; size_bytes positive integer; sha256; uploaded_by FK users; created_at/updated_at | Index expense_id. Server-generated relative path on a fixed private disk; immutable bytes after creation. Original name is display metadata only. |
| notifications | Laravel UUID id; type; notifiable_type/id; data JSON text; read_at nullable; created_at/updated_at | Framework notification indexes, plus recipient/read_at if needed. Database data follows Filament format with expense_id and required display fields. |
| audit_logs | id; actor_id nullable FK users; actor_kind (user/operator/system); action; subject_type/id; minimal metadata JSON; created_at | No financial descriptions, attachment contents or authentication secrets. CLI actions record local operator context, affected user and timestamp. Runtime offers no edit/delete UI. |
| jobs / job_batches / failed_jobs | Standard Laravel database queue migrations | One worker, bounded retries/backoff; notification/broadcast jobs idempotent. |
| migrations | Standard Laravel migration history | No sessions/cache tables: these use files. |

Amount ceiling is 999999999,999 TND to bound inputs safely; document as configurable within signed 64-bit constraints. Accept ASCII digits with at most one decimal comma or point and one to three fractional digits when a separator is present; whole-number inputs are valid. Parse exactly into integer millimes, padding missing fractional places without rounding. Reject mixed/repeated separators, digit grouping, signs, exponents, zero, negatives, excessive precision, non-finite values and overflow. Display French decimal commas, exactly three fractional digits and TND: inputs 12,5 and 12.5 both display as 12,500 TND. Aggregate with integer SUM/COUNT; never SQLite TOTAL or floating decimal casts. Guard aggregate overflow instead of silently coercing. Charts carry millimes and format labels exactly.

## 4. Authentication and CLI accounts

Laravel Argon2id password hashing, sessions, CSRF, regenerated login sessions, complete logout/invalidation, rate-limited password and MFA challenges. Require passwords of at least 12 characters; allow passphrases, spaces and Unicode, without mandatory uppercase/lowercase, numbers or symbols; hidden interactive entry/confirmation for CLI passwords. No secrets in command arguments, history, logs or fixture commits. Production cookies Secure/HttpOnly/SameSite=Lax, host-only; inactivity timeout 120 minutes, no persistent remember-me UI.

Page GET/HEAD requests retaining a Livewire update header after an HTTP redirect must render as ordinary pages. Component updates remain POST requests with transport-header, JSON, CSRF and persistent authorization checks intact.

Use Filament required app-based TOTP and recovery codes. Users without enrollment can reach only MFA setup and logout. Sensitive profile changes require password/second-factor confirmation; users cannot disable required MFA and continue using the panel. Recovery codes are single-use; verify replay/concurrent use prevention with the file cache's atomic locks. Administrators reset enrollment through CLI; next login requires enrollment. No email-based MFA, email password-reset flow or email address changes through the profile. Account security provides password change and MFA/recovery management only.

First-login onboarding is mandatory and visually separate from the application: `/setup/mfa` enrolls MFA, `/setup/password` chooses and confirms a strong password different from the temporary one while confirming current credentials, and `/setup/complete` displays “Tout est prêt” with an explicit application-entry button. `/setup` redirects to the first unfinished step, or the dashboard when complete. Initial MFA enrollment verifies the authenticator code without repeating the temporary password; later enrollment after an MFA reset retains password confirmation, including when password selection is still pending. Code validation, attempt limits and replay prevention remain mandatory. All steps share a centered setup card and a three-icon progress indicator (MFA shield, password key, completion rocket). Show only branding and CSRF-protected sign-out, without profile menu or app navigation. Keep OTP autofill styling neutral and provide an explicit username hint for password managers where a password field is present.

Persist completed steps across sign-out/sign-in; resume the first unfinished step after normal authentication/MFA. App data stays inaccessible until MFA, password choice and final confirmation are complete. Only a successful user password change clears its requirement, atomically with audit/session revocation; the confirmed browser stays authenticated. Final confirmation stamps `onboarding_completed_at` and audits idempotently. Existing fully configured accounts are backfilled as complete; pending accounts remain pending. CLI password resets set a temporary password and clear final completion. MFA reset clears MFA and final completion while preserving an already chosen password. No new account configuration fields are part of the rocket step.

Account commands (implemented in milestone 2; socket revocation follows in milestone 6):

| Command | Behavior |
| --- | --- |
| php artisan accounts:create | Prompt name/email/role and hidden temporary password twice; default viewer; require MFA and user-chosen password; audit. |
| php artisan accounts:list | Show ID, name, email, role, active status and enrollment status only. |
| php artisan accounts:role USER ROLE | Validate one of the three roles; audit and revoke old sessions/sockets. |
| php artisan accounts:disable USER | Set inactive, rotate remember token/auth_version, revoke sockets; audit. |
| php artisan accounts:password USER | Hidden temporary password prompt/confirmation, hash, require user-chosen replacement after next MFA login, revoke old sessions; audit. |
| php artisan accounts:mfa-reset USER | Explicit interactive confirmation; clear enrollment/recovery state, revoke sessions/sockets; audit. |

USER is a stable ID or email, never a password. No public registration, invitations or user-management resource. No account hard deletion. No CLI command accepts a plaintext password option. Account disabling is rechecked on every HTTP/Livewire/download/channel-auth request. Socket authorization alone does not revoke an already open connection: test active disconnection, with a Reverb process restart as the simple ten-user fallback for administrative revocation; deny reauthorization on reconnect.

## 5. Complete authorization matrix

All application-data Yes entries require an active authenticated user with completed MFA, password choice and final onboarding confirmation. Anonymous, inactive, and incomplete-onboarding sessions are denied application data. Onboarding sessions can only reach their required step or log out; recovery regeneration requires completed onboarding. Policies apply per request/action, including crafted Livewire state and bulk actions; do not rely on button visibility.

| Action | Administrator | Editor | Viewer |
| --- | --- | --- | --- |
| List/view all non-deleted expenses | Yes | Yes | Yes |
| View/download attachments on those expenses | Yes | Yes | Yes |
| View company statistics/date ranges | Yes | Yes | Yes |
| Create expense, server assigns creator | Yes | Yes | No |
| Edit/delete own expense | Yes | Yes | No |
| Edit/delete another user's expense | Yes | No | No |
| Add/remove own expense's attachments | Yes | Yes | No |
| Add/remove another user's attachments | Yes | No | No |
| List/view/create/edit/disable categories in management section | Yes | No | No |
| Read category labels/active options as needed | Yes | Yes | Yes |
| List/view/mark read or unread/mark all own notifications | Yes | Yes | Yes |
| Access or modify another user's notifications | No | No | No |
| Change own password/manage required MFA | Yes | Yes | Yes |
| Manage accounts/roles through web UI | No | No | No |
| Subscribe to company expense-change channel | Yes | Yes | Yes |
| Subscribe to own notification/security channel | Yes | Yes | Yes |
| Subscribe to another user's private channel | No | No | No |
| Change creator, role, amount storage field or file path via expense forms | No | No | No |
| View deleted expenses/attachments, restore or force-delete in web UI | No | No | No |

Implement ExpensePolicy, CategoryPolicy, AttachmentPolicy and notification owner checks, plus explicit dashboard/security page and broadcast authorization. Filament strict authorization must reject absent policies/methods. Administrator is not a blanket global Gate bypass (notification ownership still applies).

## 6. Pages and workflows

Shared panel at the site root, with login at /login; no /admin URL prefix. Visual identity follows the owner’s ProxyMed reference: turquoise/blue, coral red and dark ink, with its existing logo. Light mode only, regardless of system preference; no theme switcher. Keep the design simple: flat colors, no decorative top accent line; unboxed, full-width login/error layouts on mobile. Keep the main application header and setup branding/sign-out header pinned at the top while scrolling. Error 404 is French and centered on desktop/mobile.

Interface language: French. Amount entry accepts decimal comma or point; display uses the decimal comma and three fractional digits.

| Page | Required behavior |
| --- | --- |
| Login / onboarding / MFA challenge | Built-in Filament login/challenge and native MFA setup validation; dedicated resumable three-step `/setup` UI, rate limits, required TOTP, single-use recovery and mandatory user-chosen password before application access. |
| Dashboard | Company-wide totals/count, monthly trend, category totals, creator totals; one active date-range state. |
| Expenses | Shared paginated sortable list; search description/category/creator; date/category/creator filters; exact TND; policy-gated actions. |
| Expense details | Date, amount, category, description, creator, timestamps and authenticated image previews/PDF links. |
| Create/Edit expense | Filament form, validation errors, authorized attachment management, server-owned fields, confirmation before deletion/removal. |
| Notifications | Dedicated newest-first section, unread badge, read/unread state, mark read/unread and all read, related expense link. |
| Categories | Administrator only: create, edit, disable; never delete historical associations. |
| Paramètres du compte | Available after onboarding. Permanent gear-icon navigation entry with active-page highlighting on desktop/mobile; matching page title. Keep this link out of the top-right user menu; retain logout there. Regular app layout with separate password/MFA sections and stable visible password fields; own password and required MFA/recovery management; no account/role administration. |

Expense fields: required date (future dates allowed unless business rules change), positive exact TND, one category, optional description. Create uses only active categories. Edit can keep its existing disabled category, but cannot switch to a different disabled category. Validate active category in the write transaction to handle concurrent disabling. Creator comes from the authenticated user and cannot be changed.

Soft deletion hides expenses from listings, details, attachment routes and statistics and retains attachment records/files. No recycle-bin UI. Changing details preserves existing files unless removal was explicitly requested. Destructive actions require confirmation. Clear loading/errors/empty states, accessible controls/contrast and mobile layouts use Filament conventions.

## 7. Attachment security and consistency

- JPEG (.jpg/.jpeg), PNG (.png), WebP (.webp), PDF (.pdf) only. Validate extension and independently detected content/MIME; verify image readability and PDF signature in addition to maintained file validation. Reject SVG/HTML/scripts, spoofed formats, invalid/malformed files, forged stored-file references and any client-supplied path. Do not claim signature checks constitute malware scanning.
- Defaults configurable: 10 files/expense, 10 MiB/file, 50 MiB combined (existing retained files + new files). Enforce server-side under concurrent edits. Align Livewire temporary limits, PHP upload/post limits and Caddy body limits; use individual uploads to avoid a 100 MiB simultaneous request. Enforce limits again when finalizing.
- Private temporary uploads and final Laravel filesystem disk outside public/. Generated names/paths; no public symlink or original filename as a storage key. Exclude upload directories from execution. Rate-limit uploads; expire abandoned temporary files.
- Authenticated route loads attachment by ID, resolves its stored expense, checks policy and permitted path prefix, and streams with fixed allowlisted Content-Type, nosniff, private/no-store and safely encoded Content-Disposition. Render safe raster image previews; PDF downloads by default with an explicit browser-view route using a restrictive sandbox policy. Original filenames are escaped and stripped of control characters. No response accepts a user path.
- Stage files privately, validate all limits, then persist expense/attachment/audit rows in a short transaction with bounded lock retry. Filesystem and DB are not atomically committed: compensate failed commits, remove files only after successful record removal, and use retryable cleanup plus a conservative reconciliation command to recover from crashes. Never delete files referenced by live or soft-deleted records; reconcile stale unreferenced generated files only after a grace period. Audit add/remove actions.
- Use one file-backed application consistency lock for attachment mutations and backup snapshot/staging; release it before network transfer. Serialize aggregate-limit checks and deletion cleanup; no long database transaction around copying or network. Reconcile after interrupted uploads/deletes. Future permanent-deletion tooling must clean files safely; no force-delete feature in scope.

## 8. Statistics

Default current month; presets Today, This week (Monday–Sunday), This month, Last month, This year, Custom. Show active inclusive period and start/end controls for Custom. Validate real dates and start <= end consistently at the server, not only in the browser. All widgets use the same date-only expense bounds and ignore soft-deleted rows.

SQLite integer SUM/COUNT and GROUP BY expense month/category/creator. Aggregate expenses directly; do not join attachments/notifications in a way that multiplies totals. Return exact formatted TND, integer counts, sensible empty charts/zero totals and missing-month zeros. One selected range applies to every chart/statistic and survives broadcasts/reconnections. No caching beyond normal file framework cache.

## 9. Notifications and real time

In the successful expense-create transaction, persist expense, audit and Laravel database notifications for every other active user (roughly nine inserts), using Filament-compatible notification data. Include creator, exact TND, category, expense date, creation timestamp and authorized expense link. No notifications for edits/deletes and no expense emails. Notification records/jobs must not duplicate on transaction or queue retries; use stable per-expense/recipient notification IDs. A rolled-back creation emits nothing.

Broadcast minimal expense-change events only after commit; payload is identifier/change kind, no descriptions/files/financial values. Use private company channel and private per-user notification channel; notification broadcasts can signal a database refetch instead of duplicating content. Queue database publishing with bounded retries; data commits remain authoritative if WebSockets fail. All refreshed records use normal policies.

Echo reconnects automatically; on subscribed/reconnected, visible-page focus and a modest outage fallback polling interval, refetch list/count/stats without clearing filters/date ranges or overwriting unsaved forms. Notify users when their edited record changes; do not silently replace their input. Refresh dedicated notifications and unread badge in real time. Access to a now-deleted linked expense shows a clear unavailable state.

Reverb binds loopback only, allowlists the selected host origin, scaling disabled. Caddy serves WSS; /broadcasting/auth uses authenticated active/MFA checks and CSRF. Test channel isolation, two independent sessions, outage/reconnect data catch-up and disabled-user connection revocation. Do not emit notification content to the company channel.

## 10. Reliability, security and operations

SQLite WAL on SSD, foreign_keys ON, busy_timeout=5000, synchronous FULL for web/CLI/worker/backup connections where applicable; verify PRAGMAs in tests. File sessions/cache are private. No network filesystems. Short write transactions, bounded retries only for known transient busy errors and idempotent operations; report safe errors when retries exhaust. Protect SSD free space and log growth.

OWASP ASVS 5.0.0 Level 1 is the minimum review baseline, with recorded applicable controls and evidence. Use parameterized Eloquent/query-builder queries, escaped plain-text output, CSRF, password/MFA/login/upload throttles, safe production errors, DEBUG=false, APP_KEY protected, HTTPS/WSS and current dependencies. Apply nosniff, frame restrictions, referrer and permissions policy; test a CSP compatible with Filament/Livewire rather than breaking controls. Apply HSTS only to the app host after HTTPS verification, without broad subdomain changes. Trust proxy headers only from actual configured proxies.

Record expense create/update/delete, attachment add/remove, provisioning/role/disable/password/MFA reset and relevant security events. Audit action/actor/subject/time, avoid secrets and unnecessary personal data. Authentication failure logs contain sanitized event/IP/context sufficient to evaluate CrowdSec compatibility, never passwords or TOTP. Local audit logs are not tamper-proof against a compromised runtime; remote tamper-evident auditing is outside scope.

Native systemd services: one database queue worker, loopback Reverb, daily backup oneshot/timer and failure-alert helper; scheduler timer only if cleanup/reconciliation tasks require it. Least permissions and resource limits must fit detected systemd version. Dedicated FPM pool, Unix socket, restart/reload only app workers/pool as safely supported. Existing Caddy/CrowdSec services and unrelated sites stay intact.

CrowdSec must be assessed from owner diagnostics: health, Caddy JSON log acquisition/parsing, app host coverage, installed collections/scenarios, recent bouncer pulls and actual remediation (IPv4 and IPv6), authentication-failure visibility, WSS/Livewire/upload false positives and continued existing-site protection. A running service or detected attack alone does not prove enforcement. Prefer existing maintained integration; propose no custom parser/scenario or Caddy binary replacement without an evidenced gap. Later controlled owner-run detection/enforcement tests need a safe test source and rollback to avoid blocking household/shared-IP users.

Preserve the existing UFW/iptables-nft ownership and CrowdSec ordering. External web access is IPv4 only: verify working IPv4 HTTPS/WSS and intended IPv6 rejection without opening IPv6. Current infrastructure evidence and remaining deployment inputs belong in README.md.

## 11. Google Drive backup and failure alerts

Google Drive is the sole persistent backup destination. Owner-confirmed daily systemd timer: 03:15 Europe/Luxembourg, Persistent=true to catch a missed run, one attempt at a time with flock and bounded service/network timeouts. Owner-confirmed retention: latest 30 successful backups, always retaining at least the last verified good backup. No rclone crypt or additional encryption.

Backup algorithm (to implement/test in milestone 7):

1. Create an attempt ID and restrictive (0700 directory/0600 files) temporary staging area on private SSD with enough free space. Track only non-secret attempt status outside the archive. Acquire the storage consistency lock; attachment writes/removals wait while authorized reads continue.
2. Use SQLite online backup API (PHP SQLite3::backup or sqlite3 .backup), never cp of the live DB/WAL. Run integrity_check and foreign_key_check against the snapshot. Copy exactly the immutable attachments referenced by that snapshot, including soft-deleted expenses, verify each stored size/hash. Fail if anything is missing. Release the lock immediately after local staging; no network under it. Avoid long DB write transactions.
3. Package snapshot, referenced files, a whitelist of non-secret recovery settings/schema/app release information and a manifest with hashes/counts. Exclude .env, APP_KEY, SMTP/rclone/Reverb credentials, source secrets, cache/session files, logs and temporary uploads. Snapshot inevitably includes password hashes and encrypted MFA data; treat it as sensitive.
4. Upload into an attempt-specific directory under the dedicated Drive folder using rclone copy (not a broad sync). Check archive/manifest sizes and hashes with rclone check; require checksums or downloaded verification, never accept size-only silently. Publish and read back a completion marker only after verification. Incomplete uploads are never restore candidates.
5. Apply retention only inside this app's remote folder after a verified backup. Remove old completed attempts beyond policy while preserving the newest verified good one; prune stale incomplete attempts separately. A retention error fails the attempt and triggers its alert. Test Google Drive trash/purge semantics and quota reclamation before selecting narrowly scoped permanent deletion flags; never purge the whole Drive or empty account-wide trash.
6. Clean local archive/snapshot/attachment staging on success or failure. Shell/PHP cleanup handles normal errors/signals; systemd ExecStopPost and next-start/boot orphan cleanup handle killed processes. Persist no local backup archives. A crash/power loss can leave temporary data until the next cleanup; identify and remove only this service's abandoned staging paths.
7. One systemd OnFailure path invokes a synchronous SMTP-only backup-failure alert helper independent of the queue and database availability. Include attempt ID, timestamp/timezone, failure stage, short sanitized error and relevant exit/status. Cover snapshot/copy/auth/upload/check/retention/unexpected termination. Deduplicate by attempt ID; retain minimal delivery status for SMTP retry. No script trap sends a second email. Test bootstrap/queue/database failure paths explicitly.

No computer can send an alert while powered off, and SMTP cannot guarantee receipt during an outage. Record interrupted attempts for alerting/catch-up after restart and retry failed SMTP deliveries with deduplication; surface delivery failures in systemd journal. Do not claim exactly-once mail delivery across ambiguous SMTP acknowledgements. Expected recovery point objective: up to 24 hours plus failures; restore time measured in rehearsal, not assumed.

Use a dedicated Google account/folder where feasible, minimum rclone Drive scope that can create/manage/read these backups (evaluate drive.file), no sharing/public links and protected OAuth tokens. Folder selection alone is not an OAuth authorization boundary. Keep APP_KEY, credentials and OAuth recovery material in a separate owner-managed secure recovery store; losing APP_KEY makes encrypted MFA recovery impossible. Unencrypted Drive backups expose financial data and attachments if that account is compromised. Home-network/power/SSD/Google/SMTP outages remain operational risks.

Recovery: select a completed verified attempt; download into an isolated environment; verify manifest/archive hashes before extraction and reject unsafe paths; integrity/FK-check DB and match attachment hashes/references; restore to private paths with correct owner/modes; restore separately held secrets/APP_KEY and non-secret config; discard stale sessions and queued transient work under a documented procedure; migrate only to a compatible tested release; test MFA/roles/attachments/stats; then owner performs controlled production cutover/service restart. Never reset production. Rehearse before launch and at least quarterly; tests use fake/isolated destinations, never production Drive.

## 12. Acceptance and evidence

Every feature requires working implementation, validation, server-side authorization tests, applicable browser checks and updated documentation. Required coverage:

- Unit: exact money parsing/formatting/overflow, comma/point equivalence and rejection of mixed separators/grouping, integer aggregation, categories/business rules, date presets/custom invalid dates and timezone boundaries.
- Feature/security: password login/logout/throttling, TOTP/enrollment/recovery/replay, disable/reset/role session revocation; every matrix permission; all-user visibility; protected-field tampering; category lifecycle; soft deletion; private photo/PDF viewing/upload/removal; malicious MIME/extension/path/oversize/count/concurrent total limits; orphan/partial failure recovery; notification ownership/read state/idempotence; private-channel authorization.
- Statistics: all presets and custom ranges across all widgets, deleted exclusion, no join duplication, empty datasets and exact totals.
- Backups: actual disposable WAL SQLite online snapshot with writes, coherent attachments including deleted references, upload/check/retention success/failure via mocks or isolated destinations, SMTP alerts/dedup/retry, signals/timeouts/killed process cleanup, manifest validation and full isolated restore. Never assert live Drive or mail delivery from mocks.
- Playwright: login/MFA/recovery, create/edit/editor own delete/admin other delete/viewer restrictions, filters, custom dashboard period, image/PDF upload/view/download, notifications/read counts, two-session real-time changes and reconnect catch-up without manual refresh, mobile and keyboard/error states.
- Release: formatting, full PHPUnit suite, asset build, Playwright suite, Composer/npm audits, production configuration review, security/deployment evidence reviewed by a separate AI agent that did not implement the changes under review (not an independent human audit); owner verifies Drive transfer/SMTP delivery/restore, Caddy HTTPS/WSS, CrowdSec detection/enforcement on permitted IPv4 traffic, preserved IPv6 blocking/remediation, and existing-site regression checks.

Nothing is production-ready until these checks and owner-run infrastructure verification are recorded. See PLAN.md for milestone gates and README.md for environment evidence and operations.
