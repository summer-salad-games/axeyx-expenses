# ProxyMed — Dépenses

Expense register for one company and approximately ten fixed users. TND currency, required TOTP MFA, shared expense visibility and administrator/editor/viewer roles.

**Status:** milestones 1–4 are implemented and verified locally: foundation, authentication/roles, expenses/categories and private attachments. Statistics, notifications, real time, backups and production deployment remain later milestones. Verification is recorded in PLAN.md.

- [SPEC.md](SPEC.md): authoritative requirements, architecture, security and acceptance criteria.
- [PLAN.md](PLAN.md): milestone progress and verification evidence.
- [AGENTS.md](AGENTS.md): AI contribution rules and permission boundaries.

## Confirmed deployment choices

| Setting | Value |
| --- | --- |
| Host | expenses.axeyx.com, HTTPS/WSS, IPv4-only external access |
| Interface | French, light mode only; simple flat styling and unboxed mobile login/error pages |
| Amount input (milestone 3) | Accept comma or point; display comma, three decimal places and TND |
| Reporting timezone | Europe/Luxembourg, including daylight-saving changes; timestamps stored in UTC |
| Releases | /srv/axeyx-expenses/releases with a current symlink |
| Private state | /var/lib/axeyx-expenses: database, attachments, sessions/cache and temporary backup staging |
| Proposed runtime | Debian PHP 8.4 CLI/FPM; Laravel 13, Filament 5 >=5.11.2, compatible Livewire 4 and stable Reverb 1.x |
| Proposed Reverb listener | 127.0.0.1:8081; recheck availability before deployment |

Brand reference: [ProxyMed](https://proxymed.pages.dev). The locally served logo mark comes from that owner-provided website; palette accents are turquoise #38B6BC, coral #E84A5F and sky blue #3B9FE0, with darker accessible control colors.

## Environment evidence

Observed locally or supplied by the owner on 2026-11-10; versions and package availability must be rechecked when installing. The agent has not accessed the Pi.

| Environment | Recorded baseline |
| --- | --- |
| Development | macOS ARM64; Herd 2.1.0; PHP CLI/FPM 8.5.12; Composer 2.11.1; Node 26.13.0/npm 11.20.0. Required PHP extensions were available. Preserve the existing global setup. |
| Local SQLite | PHP library 3.45.2, sqlite3 CLI 3.54.0; test the PHP library, not inferred CLI capabilities. |
| Pi | Debian 13 ARM64, systemd 257.13; 238.5 GiB NVMe with ext4 root and approximately 218 GiB available; 15 GiB RAM. NTP synchronized, Europe/Luxembourg timezone. |
| Missing Pi tools | PHP/FPM, Composer, sqlite3 and rclone. Cached package listings offered PHP 8.4; exact patched runtime/extensions remain a deployment gate. |
| Caddy | Active 2.11.7 as caddy:caddy; /etc/caddy/Caddyfile imports /etc/caddy/sites/*.caddy. Service drop-in: /etc/systemd/system/caddy.service.d/override.conf. Standard modules; no CrowdSec module listed. |
| CrowdSec | Active 1.8.2, firewall bouncer 0.0.36 in iptables mode, disable_ipv6 false. Caddy/SSH journals acquired with type syslog; Caddy parsing, HTTP alerts, recent bouncer pulls and historical IPv4 drops demonstrated. |
| Firewall | UFW/iptables-nft; CrowdSec INPUT jumps precede UFW in both families. IPv4 permits web traffic and LAN-restricted SSH. IPv6 defaults to DROP with no web allow rules, matching the owner's IPv4-only policy. Preserve this setup. |
| Occupied loopback ports | Caddy: 2019; CrowdSec: 6060 and 8080. |

Existing evidence does not prove future app-host log coverage, actual client-IP handling, login/MFA detection, legitimate Livewire/upload/WSS behavior or end-to-end release checks. Those remain milestone 8–9 work; completed diagnostics need not be repeated now.

## Local setup

PHP 8.4+ with Argon2id support and the extensions listed in composer.json, Composer and a compatible Node/npm are required. Lockfiles resolve against PHP 8.4.0 as a conservative compatibility floor. Local checks ran on PHP 8.5.12; actual PHP 8.4 execution is still required before deployment.

```sh
COMPOSER_HOME="$PWD/.cache/composer/home" COMPOSER_CACHE_DIR="$PWD/.cache/composer/cache" composer install --no-plugins
npm ci --cache .cache/npm
```

On first setup only: copy .env.example to .env, run php artisan key:generate, create database/database.sqlite, then run php artisan migrate --seed. Existing local .env and database must be preserved on subsequent setups. Never run these local instructions against production. Composer's reviewed autoload hook discovers packages and publishes Filament assets. npm lifecycle hooks are disabled in .npmrc.

```sh
npm run build
npm run dev:server
```

`dev:server` starts the local PHP server with HTTP requests and application logs in the same terminal, and keeps a copy of application logs in `storage/logs/laravel.log`. Its logging overrides apply only to that process. Stop with Ctrl+C; restart after changing `.env` because this command preserves its environment with `--no-reload`. PHP source changes apply on subsequent requests. `npm run dev` runs the Vite asset watcher separately when needed.

Open [http://127.0.0.1:8000/](http://127.0.0.1:8000/), which redirects to /login. There is no /admin URL prefix or registration. Create your local account from another terminal in this project:

```sh
php artisan accounts:create
```

Enter name, email, role and a temporary password when prompted. Password entry/confirmation is hidden; use at least 12 characters. Spaces and accents are allowed; uppercase letters, numbers and symbols are optional. No default account/password is seeded.

Log in with the temporary password. The dedicated onboarding flow has three steps and a shared progress indicator:

1. `/setup/mfa`: scan the displayed QR code or enter the secret in your authenticator, enter its current code, then privately save the recovery codes before activating MFA. Initial enrollment does not ask for the temporary password again. Enrollment after an MFA reset still requires your current password. If the setup code expires, return to the previous step and enter a fresh code.
2. `/setup/password`: choose a different password of at least 12 characters, confirm it and enter your current temporary password. Spaces and accents are accepted; uppercase letters, numbers and symbols are optional.
3. `/setup/complete`: **Tout est prêt !** confirms setup; click **Accéder à l’application** to finish.

The setup header stays pinned while scrolling and provides **Se déconnecter** instead of a profile menu. You can sign out between completed steps and resume the first unfinished step after logging back in; an enrolled account still requires normal MFA verification. `/setup` redirects to the next unfinished step, or the dashboard when complete. Dashboard access stays blocked until final confirmation. The old `/multi-factor-authentication/set-up` URL redirects through `/setup`.

The dashboard currently contains only the account shell. **Paramètres du compte** is a permanent navigation entry with a gear icon; the top-right user menu retains logout without duplicating this link. The main application header stays pinned while scrolling. It is highlighted when its page is open, including in the mobile navigation drawer; the page title uses the same label. Its separate password/MFA sections change the password or regenerate recovery codes after confirmation; required MFA cannot be disabled. Log out and back in to check the TOTP challenge. A recovery code can replace TOTP once; store codes securely and never paste them into chat.

Sessions expire after 120 minutes without server requests (`SESSION_LIFETIME=120`). Expiry is enforced on the next request; an idle open tab is not immediately cleared. Background requests can renew the session. Persistent “remember me” login is disabled.

After updating an existing local checkout, run `php artisan migrate` to add onboarding state without resetting data. Existing accounts with configured MFA and a chosen password retain completion; unfinished accounts resume setup.

The Reverb package/config is installed, but live channels and socket disconnection belong to milestone 6.

To start over with an empty local database, stop the development server and run:

```sh
npm run db:reset
php artisan accounts:create
npm run dev:server
```

`db:reset` runs `php artisan migrate:fresh --seed && php artisan optimize:clear`: it permanently deletes all database records, recreates the tables, restores default expense categories and clears cached framework state. It creates no user accounts. Use it only for local development. Uploaded files and `.env` are retained. Afterward, use a fresh private browser window for new-account onboarding.

## Expenses and categories

After login/onboarding, use **Dépenses** in the left menu (`/expenses`). All roles see the shared register and details. Administrators and editors can create expenses; editors can change/delete only their own, while administrators can change/delete any expense. Viewers have read-only access. Mobile rows stack vertically; search, date/category/creator filters, sorting and pagination are available. Every dropdown in the expense/category filter popups includes a search box, including category status.

Twenty default French categories are provided for ProxyMed’s respiratory diagnostic centre, covering medical equipment/maintenance/consumables, diagnostic products/gases and general operating expenses. Administrators manage them or add more under **Catégories** (`/categories`). Category names are unique ignoring case and equivalent Unicode spellings. Editing a category can deactivate/reactivate it; deactivation preserves history and prevents new selections. An existing expense may retain its own deactivated category. Unused categories have a confirmed permanent deletion action on the list and edit page. A category referenced by any expense cannot be deleted; deactivate it instead. References are rechecked when deleting, including concurrent expense creation.

An expense requires a date (future dates are allowed), category and positive amount; description is optional. The optional Description table column shows up to 20 characters followed by `...`; the full text remains in forms and details. The amount input selects its text on focus, blocks letters/signs/extra separators and a fourth fractional digit while typing, and accepts comma or point. Both `12,5` and `12.5` become `12,500` when you leave the field, and display as `12,500 TND` after saving. A leading decimal separator automatically gets a zero. Malformed pastes are rejected in full, without stripping/truncating them. **Créer & Ajouter un autre** saves the expense and resets the amount to empty for the next entry. Values have at most three decimal places and are stored as integer millimes. The default maximum is `999 999 999,999 TND`; `config/expenses.php` can lower it within the database ceiling. Expense deletion requires an explicit irreversible-action confirmation and permanently removes the expense, its attachment records and their files. There is no recycle bin or restore operation; deletion audit events are retained.

To add missing defaults to an existing database without resetting it, run:

```sh
php artisan db:seed --class=DefaultCategorySeeder
```

Seeding matches normalized category names and leaves existing names, active/disabled status and custom categories unchanged. Only newly created categories receive an operator audit event. No accounts or expenses are seeded. Default categories can be renamed/deactivated in the UI; rerunning the seeder adds any default names that are missing.

After updating this checkout, run `php artisan migrate`. The permanent-deletion migration purges expenses previously soft-deleted during development and their linked files, with operator audit records, then removes the soft-delete column. The owner explicitly approved that purge. It preserves current expenses and accounts; rolling the schema back cannot recover deleted data. A database reset is not required. Dashboard statistics arrive in milestone 5; notifications and real-time updates in milestone 6. For now, another browser refreshes the register to see changes.

## Private attachments

Use **Ajouter des pièces jointes** when creating or editing an expense, then save the form. The upload field shows filenames and progress without photo previews. Existing files remain attached. The **Pièces jointes** section on details/edit pages shows saved-image thumbnails and the same **Ouvrir**, **Télécharger** and confirmed **Retirer** actions for every file type. **Ouvrir** opens `/attachments/{id}/view` in a new tab for both images and PDFs. Saved-image thumbnails use this same authenticated view URL. Viewers can read/download; editors can add/remove only on their own expenses; administrators can do so on any expense. Deleting an expense permanently removes its documents too. Files are removed only after the expense/attachment/audit transaction commits; a failed database transaction preserves the records and files. If filesystem cleanup fails, reconciliation retries unreferenced files.

Limits live in `config/expenses.php`: 10 files, 10 MiB each, 50 MiB combined including existing documents. Uploads run individually. JPEG, PNG, WebP and readable non-encrypted PDFs are accepted; image decoding is limited to 16 megapixels and PDFs to 500 pages. MIME/extension, content structure, readability and resource limits are checked again on save. Validation is not antivirus scanning. `ext-gd` with JPEG/PNG/WebP support, PHP CLI and enabled local process execution are required alongside the existing PHP extensions. Document parsing runs in a bounded child process with private temporary storage. The maintained parser is [smalot/pdfparser](https://github.com/smalot/pdfparser), locked by Composer.

Final files are generated beneath the private attachments disk (`ATTACHMENTS_PATH`); temporary uploads/validation and the shared consistency lock use the private local disk (`PRIVATE_STORAGE_PATH`). Never expose either directory or create a public storage symlink. Keep both stable across releases; future backup staging uses the same lock. File removal follows the committed metadata/audit change; a failed cleanup is retried by reconciliation after its grace period.

```sh
npm run attachments:reconcile
npm run attachments:reconcile -- --delete
```

The first command checks hashes/references and reports eligible orphans without deleting anything. The second removes only generated, unreferenced files at least 24 hours old and expired private temporary files. All files still referenced by attachment records are retained; missing/altered referenced files cause a nonzero exit and are reported by attachment ID. The scheduler runs cleanup hourly when `php artisan schedule:work` is running locally; production scheduling is prepared in the deployment milestone. No scheduler or global settings are installed automatically.

Before deployment, align PHP CLI/FPM and Caddy with individual 10 MiB uploads plus multipart overhead (proposed `upload_max_filesize=10M`, `post_max_size=12M`, host-specific Caddy body limit at least 12 MiB), verify GD format support and child-process execution under the actual FPM identity. Production commands/changes remain owner-executed.

## Verification

```sh
COMPOSER_HOME="$PWD/.cache/composer/home" COMPOSER_CACHE_DIR="$PWD/.cache/composer/cache" composer validate --strict
COMPOSER_HOME="$PWD/.cache/composer/home" COMPOSER_CACHE_DIR="$PWD/.cache/composer/cache" composer check-platform-reqs
php artisan test
vendor/bin/pint --test
npm run build
COMPOSER_HOME="$PWD/.cache/composer/home" COMPOSER_CACHE_DIR="$PWD/.cache/composer/cache" composer audit
npm audit --cache .cache/npm
PLAYWRIGHT_BROWSERS_PATH="$PWD/.cache/ms-playwright" npm exec --cache .cache/npm -- playwright install chromium
PLAYWRIGHT_BROWSERS_PATH="$PWD/.cache/ms-playwright" npm run test:e2e
```

PHPUnit uses .data/testing; Playwright resets its own disposable database, generates random test accounts and starts a loopback-only server under .data/e2e. It refuses an existing server or production/staging configuration. Both reject database/storage symlinks and isolated cached configuration. Do not run multiple PHPUnit suites concurrently against the shared test database. These directories, caches, private files, .env and test artifacts are ignored by Git. Test-only password hashing is deliberately cheaper than normal runtime hashing. Current results are in PLAN.md.

Local 405 diagnostics are written to `storage/logs/laravel.log` when `APP_ENV=local`: method, path, allowed methods, routing message and whether the request retained the Livewire transport marker. Query strings, cookies and request bodies are excluded. The file may not exist until an event is logged. To inspect recent entries:

```sh
tail -n 30 storage/logs/laravel.log
```

After an MFA setup error, refresh the page before retrying. Completed security steps are retained and the app resumes the first unfinished step. Completing onboarding does not require resetting the database.

## Deployment preparation — owner-executed

Before preparing exact commands, obtain only the remaining non-secret Caddy site/snippet/logging/drop-in excerpts, a sanitized access-log example, bouncer chain/origin settings, existing-site baseline and DNS/router/CDN/proxy details. Never paste credentials or complete credential-bearing configurations.

Deployment will use isolated runtime identities, a dedicated FPM pool/socket, private writable state, source read-only to runtime, one queue worker and loopback Reverb. Add only the app's Caddy host configuration. Validate configurations and save rollback copies before owner-approved reloads; preserve existing sites and security services.

Before migrations, verify a backup and explain rollback/data-loss consequences. A release switch does not reverse incompatible schema changes; restore only a verified database/attachment pair with owner approval. Never use migrate:fresh/reset on production.

Planned units: axeyx-expenses-queue.service, axeyx-expenses-reverb.service, axeyx-expenses-backup.service/.timer and a backup-failure helper. Add cleanup scheduling only if required. Record exact service commands and permission checks when implemented.

The owner must verify IPv4 HTTPS/WSS, intended IPv6 rejection, MFA/roles/private downloads, queue/reconnect behavior, CrowdSec and existing sites. Automated destructive/security tests use disposable local data, not production.

## Accounts, backups and recovery

Npm shortcuts for account commands (`USER` is an ID or email; `ROLE` is `administrator`, `editor` or `viewer`):

```bash
npm run accounts:create
npm run accounts:list
npm run accounts:role -- USER ROLE
npm run accounts:disable -- USER
npm run accounts:password -- USER
npm run accounts:mfa-reset -- USER
```

The six account commands in [SPEC §4](SPEC.md#4-authentication-and-cli-accounts) are implemented. Run them from the project directory. `php artisan accounts:list` shows IDs and enrollment status; use an ID or email for USER in the other commands. Passwords use hidden prompts, and MFA reset asks for confirmation. Role changes, disabling, password changes and MFA resets revoke existing sessions on their next request. `accounts:password` sets a temporary password: after the next MFA login, the user must choose a different password and finish setup. It retains existing MFA enrollment; `accounts:mfa-reset` repeats MFA setup and final confirmation without repeating an already-completed password step. A password change or recovery-code regeneration in **Paramètres du compte** preserves that browser's session and revokes the others. Account changes are audited; password/MFA failures log only factor, internal user ID when known and IP. Live socket revocation will be completed with Reverb in milestone 6.

Private attachments must never use a public storage symlink or be manually purged.

The backup/restore algorithm is in [SPEC §11](SPEC.md#11-google-drive-backup-and-failure-alerts). Confirmed schedule/retention: daily 03:15 Europe/Luxembourg, latest 30 successful backups, Google Drive only, no additional encryption and no persistent local archives.

Before deployment, the owner privately configures a dedicated Drive folder/minimum workable OAuth scope, SMTP provider and alert recipient, and a separate secure recovery store for APP_KEY and credentials. Credentials must not enter Git, chat or backup archives.

Before enabling the schedule, verify real upload/check/retention, a received failure email and an isolated restore. Rehearse recovery before launch and quarterly; record restore duration. A powered-off Pi cannot send an immediate alert. Restore procedures must include hash/integrity/reference checks, safe extraction, correct permissions, separately held secrets, session revocation and safe handling of restored transient jobs.

## References

Reference review: 2026-11-11; dependency resolution and audits recorded in PLAN.md. Recheck compatibility and advisories at dependency resolution; these links are not evidence of a successful install.

- [Laravel releases](https://github.com/laravel/docs/blob/13.x/releases.md), [Debian PHP 8.4 FPM](https://packages.debian.org/trixie/php8.4-fpm).
- [Filament installation](https://filamentphp.com/docs/5.x/introduction/installation), [MFA](https://filamentphp.com/docs/5.x/users/multi-factor-authentication), [security](https://filamentphp.com/docs/5.x/advanced/security), [MFA advisory](https://github.com/filamentphp/filament/security/advisories/GHSA-52xp-w8hr-xv3c), [scope advisory](https://github.com/filamentphp/filament/security/advisories/GHSA-6p72-cx74-63f5).
- [Livewire installation](https://github.com/livewire/livewire/blob/4.x/docs/installation.md), [Reverb](https://laravel.com/docs/13.x/reverb), [Playwright](https://playwright.dev/docs/intro).
- [SQLite online backup](https://sqlite.org/backup.html), [PRAGMAs](https://sqlite.org/pragma.html), [rclone Drive](https://rclone.org/drive/), [verification](https://rclone.org/commands/rclone_check/).
- [Caddy FastCGI](https://caddyserver.com/docs/caddyfile/directives/php_fastcgi), [reverse proxy](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy), [CrowdSec acquisition](https://docs.crowdsec.net/u/getting_started/post_installation/acquisition/), [health checks](https://docs.crowdsec.net/u/getting_started/health_check/), [OWASP ASVS 5.0](https://asvs.owasp.org/).
