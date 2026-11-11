# ProxyMed — Dépenses

Expense register for one company and approximately ten fixed users. TND currency, required TOTP MFA, shared expense visibility and administrator/editor/viewer roles.

**Status:** approved on 2026-11-11. Local foundation implemented; authentication/MFA and business workflows are not implemented yet. Panel access is deliberately denied for every user until milestone 2.

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

PHP 8.4+ with the extensions listed in composer.json, Composer and a compatible Node/npm are required. Lockfiles resolve against PHP 8.4.0 as a conservative compatibility floor. Local checks ran on PHP 8.5.12; actual PHP 8.4 execution is still required before deployment.

```sh
COMPOSER_HOME="$PWD/.cache/composer/home" COMPOSER_CACHE_DIR="$PWD/.cache/composer/cache" composer install --no-plugins
npm ci --cache .cache/npm
```

On first setup only: copy .env.example to .env, run php artisan key:generate, create database/database.sqlite, then run php artisan migrate. Existing local .env and database must be preserved on subsequent setups. Never run these local instructions against production. Composer's reviewed autoload hook discovers packages and publishes Filament assets. npm lifecycle hooks are disabled in .npmrc.

```sh
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open [http://127.0.0.1:8000/](http://127.0.0.1:8000/), which redirects to /login. There is no /admin URL prefix. This foundation has no seeded account, registration or account provisioning command; access remains closed until milestone 2. The Reverb package/config is installed, but channels and broadcasts belong to milestone 6.

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

PHPUnit uses .data/testing; Playwright starts its own loopback-only server/database under .data/e2e and refuses an existing server or production/staging configuration. Both reject database/storage symlinks and isolated cached configuration. Do not run multiple PHPUnit suites concurrently against the shared test database. These directories, caches, private files, .env and test artifacts are ignored by Git. Current results are in PLAN.md.

## Deployment preparation — owner-executed

Before preparing exact commands, obtain only the remaining non-secret Caddy site/snippet/logging/drop-in excerpts, a sanitized access-log example, bouncer chain/origin settings, existing-site baseline and DNS/router/CDN/proxy details. Never paste credentials or complete credential-bearing configurations.

Deployment will use isolated runtime identities, a dedicated FPM pool/socket, private writable state, source read-only to runtime, one queue worker and loopback Reverb. Add only the app's Caddy host configuration. Validate configurations and save rollback copies before owner-approved reloads; preserve existing sites and security services.

Before migrations, verify a backup and explain rollback/data-loss consequences. A release switch does not reverse incompatible schema changes; restore only a verified database/attachment pair with owner approval. Never use migrate:fresh/reset on production.

Planned units: axeyx-expenses-queue.service, axeyx-expenses-reverb.service, axeyx-expenses-backup.service/.timer and a backup-failure helper. Add cleanup scheduling only if required. Record exact service commands and permission checks when implemented.

The owner must verify IPv4 HTTPS/WSS, intended IPv6 rejection, MFA/roles/private downloads, queue/reconnect behavior, CrowdSec and existing sites. Automated destructive/security tests use disposable local data, not production.

## Accounts, backups and recovery

Account command contracts are in [SPEC §4](SPEC.md#4-authentication-and-cli-accounts); they belong to milestone 2 and are not implemented. Passwords use hidden prompts. Private attachments must never use a public storage symlink or be manually purged.

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
