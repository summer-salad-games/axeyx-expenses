# Project instructions

## Workflow

- All code implementation and review is AI-only. Multiple AI agents may contribute; the primary agent scopes tasks, integrates changes and verifies milestones. No human code writing/editing/review is expected.
- Read SPEC.md before changing behavior. It owns requirements/exclusions; PLAN.md owns milestone progress/evidence; README.md owns setup, environment evidence and operations. Avoid duplicating them.
- Architecture review remains active: do not scaffold, install dependencies or develop features until the owner explicitly approves the proposal. Initial Pi diagnostics are reviewed; remaining deployment evidence is listed in README.md.
- Implement milestone by milestone, completing applicable verification before continuing. Discuss material scope/architecture changes with the owner.

## Implementation

- Use the Laravel/Filament/Livewire/SQLite/Reverb monolith and native ARM64 deployment specified in SPEC.md. Prefer framework conventions, validated inputs, small methods, built-in components and minimal dependencies.
- Resolve maintained stable packages compatible with the production PHP baseline; inspect uncertain hooks first. Commit Composer/npm lockfiles; never use development branches or ignore platform requirements.
- Enforce SPEC.md's authorization matrix server-side through Policies, Filament strict authorization, Livewire actions, attachment routes and private channels.
- All monetary arithmetic uses positive integer millimes; parse decimal strings exactly and format three places with TND. No floats. Apply required SQLite PRAGMAs on every connection, short transactions and bounded lock retries; no network I/O in transactions.
- Protect server-owned fields, account/MFA state, private files and secrets. CLI passwords use hidden prompts. Record required audit events without credentials or document contents. Preserve file/record consistency and broadcast only after commit.
- Follow the specified coherent SQLite/attachment backup, Google Drive verification/retention, temporary cleanup and SMTP failure-alert design. Never copy a live SQLite database file or archive credentials.

## Verification

- Each feature requires appropriate PHPUnit/Filament/Livewire tests and Playwright critical workflows, authorization/MFA, desktop/mobile, error/empty states and two-browser real-time/reconnection checks.
- Automated tests use disposable project-local data and isolated/mock backup destinations. Never run destructive/security test suites or test uploads against production. Owner-run release checks are separate.
- Run applicable tests, formatting, asset build and dependency audits; record actual results, failures and unverified checks in PLAN.md.
- Production requires security-sensitive code/deployment review by a separate AI agent that did not implement the changes under review. Resolve findings and identify this accurately as AI review, not an independent human audit.

## Permissions and production boundary

- After architecture approval, autonomously read/write this project, install project-local dependencies, run local servers/builds/tests, reset disposable databases and perform ordinary Git operations. Keep caches, browser binaries and generated files project-local; do not silently alter Herd/global configuration.
- Read-only diagnostics outside the project are allowed. Obtain explicit approval before sudo, system/global changes, writes outside the project, publishing, destructive remote Git operations, changes affecting other services or deleting irreplaceable data/the repository. Provide exact commands, purposes, effects and risks first.
- Never connect to the Pi, request SSH credentials or execute remote commands. The owner runs production diagnostics/changes and supplies evidence; do not assume commands succeeded.
- Preserve existing sites, Caddy, CrowdSec, DDNS, firewall and security settings. Inspect before proposing additive changes and provide validation/rollback. Redact secrets and unrelated identifiers from shared evidence.
