# HostDesk

Slice 01 is a local municipal infrastructure inventory: servers, projects, and explicit stage/prod environments. It does not connect to servers or perform deployments, backups, TLS, DNS, or process actions. Infrastructure facts and credentials remain unverified; demo records are fictional.

**Current delivery status:** source implementation is present, but dependencies, PostgreSQL execution, framework checks, browser verification and Git commits are blocked by the execution environment. There is no lockfile or review candidate SHA yet. Do not treat this draft as a validated release. See [docs/PLAN.md](docs/PLAN.md).

## Requirements and local startup

PHP 8.4 or 8.5 with PDO PostgreSQL, sodium, intl, mbstring, XML, ctype and iconv; Composer; PostgreSQL 16 tools (`initdb`, `pg_ctl`, `psql`, `createdb`). Symfony is constrained to 7.4; Scheb 8 is compatible with Symfony 7.4 and requires PHP 8.4+. Dependencies still need to be resolved and locked before review.

From this directory:

```sh
composer install
php bin/local-secrets.php
bash bin/local-db.sh
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console assets:install public
php bin/console app:account create admin@example.invalid --role=Admin --actor=local-bootstrap
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/login`. Enter the password chosen interactively, add the displayed enrollment key to a TOTP authenticator, then confirm a valid code. Enrollment completion signs you out; sign in again with password and TOTP. This local HTTP command is for loopback development only. Any future hosted setup needs HTTPS and its own deployment review.

`local-secrets.php` creates random, untracked `.env.local`, `.env.test.local`, database credentials and separate local/test TOTP keys under `var/keys/`, with owner-only permissions. Existing files are preserved. Nothing secret is printed. `var/` is outside the web document root and excluded from Git. Keep protected backups of the encryption key separately from database backups; replacing it makes existing enrolled secrets unreadable.

`local-db.sh` creates a cluster only in `var/postgresql`, listening on `127.0.0.1:55432`, with a private socket directory. It creates only `hostdesk` and `hostdesk_test`, owned by a project-specific login. It does not manage global PostgreSQL services or use another project's database. Local socket trust is restricted by directory permissions; TCP uses SCRAM. If the port is occupied, resolve the collision explicitly; do not point HostDesk at the existing service.

Stop this cluster with:

```sh
pg_ctl -D var/postgresql stop
```

If using an independently provisioned isolated PostgreSQL instance, put its URL in `.env.local` and the isolated test URL in `.env.test.local`. Do not substitute SQLite. This environment had PostgreSQL binaries, so a Compose fallback was not necessary; Docker's daemon was unavailable.

## Accounts and MFA recovery

All accounts are individual; there is no registration, email reset, trusted-device option or default password. Viewer and Operator read inventory; Admin creates, edits and archives. All roles require confirmed MFA. Password and TOTP login attempts use framework throttling (5 attempts / 15 minutes); enrollment has an account-keyed limit of 5 attempts / 15 minutes. Session cookies are HTTP-only, SameSite=Lax and Secure on HTTPS. Normal forms, login, MFA, enrollment, logout and archive requests use CSRF protection.

Use the administrative CLI from a trusted local shell. `--actor` identifies the human operating that shell; it is an audit label, not a remote authentication mechanism. Operating-system access to the application/key/DB is the administrative trust boundary.

```sh
php bin/console app:account create viewer@example.invalid --role=Viewer --actor=your-name
php bin/console app:account role viewer@example.invalid --role=Operator --actor=your-name
php bin/console app:account disable viewer@example.invalid --actor=your-name
php bin/console app:account password-reset admin@example.invalid --actor=your-name
php bin/console app:account mfa-reset admin@example.invalid --actor=your-name
```

Creation and password reset require hidden interactive password entry twice (minimum 14 characters). No password argument exists. Fixed role names are Viewer, Operator and Admin. Disabling, password/MFA reset and role changes increment a session version checked through Symfony's user refresh/equality mechanism on the next request, including existing MFA-in-progress sessions. A request already executing cannot be retroactively undone. Account changes and their audit entries are transactional and exclude hashes, secrets and credential values.

For a lost authenticator, establish the account owner's identity outside HostDesk, run `mfa-reset`, and have them sign in and enroll again. Resetting MFA invalidates existing sessions. Password reset preserves confirmed MFA. Disabled accounts remain disabled; re-enabling and unarchive flows are outside this slice.

If the encryption key is lost, restore its protected backup. If that is impossible, reset affected accounts' MFA from the CLI before generating a replacement key and re-enrolling. Never print keys or commit them. This slice does not provide key rotation or self-service recovery.

## Inventory lifecycle

Create servers and projects separately. Add each environment using the Environments CRUD list. A project may have no environments (“Not configured”), stage and prod, or prod only. Domains are explicit comma/newline-separated names. Unknown application/public paths can be left empty. Deployment, backup and TLS descriptions document current knowledge and execute nothing. Credential references identify external vault records; never paste credential values or application secrets.

One environment of each kind per project is enforced by validation and a PostgreSQL unique constraint, including archived rows. Archived records remain visible and immutable. Archive environments before their server or project; active references prevent parent archival. Parent locks serialize environment writes against parent archival. There are no hard deletes or unarchive actions. Archive never implies any remote action.

Audit history is Admin-only and read-only in the UI. The explicit inventory writer commits the mutation and audit together. Audit records retain actor, target type/ID, action, UTC timestamp and field changes. Credential-reference and free-text description changes record only that the field changed. Database administrators can still change the database; this is an application audit trail, not a tamper-proof ledger.

## Fictional demo data

Run explicitly in the local dev environment (also allowed in test):

```sh
php bin/console app:demo
```

Adds two fictional servers, a project with stage/prod, a legacy project with prod only, and an unconfigured project. It creates no users, uses `.invalid` names, and never purges or updates existing records. If any reserved demo name exists, it exits without changes. The whole fixture load is transactional and audited as `cli:demo-fixtures`.

## Focused checks

After dependencies and the isolated cluster are available:

```sh
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction
php bin/console lint:container
php bin/console lint:yaml config
php bin/console lint:twig templates
php bin/console doctrine:schema:validate
APP_ENV=test php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:up-to-date
php bin/console asset-map:compile
vendor/bin/phpunit
composer audit
```

The test suite truncates **only a PostgreSQL database named `hostdesk_test`**. Never put real data there. It covers anonymous/enrollment/MFA boundaries, direct Viewer/Operator mutation denial, Admin CRUD/archive with safe actor audit, rollback, duplicate environment kinds, archival rules, account session revocation, and wrong-key encryption failure. It is intentionally focused; it does not test every field/getter.

Actual checks completed during this execution: Composer manifest validation; PHP syntax (after fixing a detected CLI typo); YAML parsing with Ruby Psych; shell syntax; direct libsodium round-trip and wrong-key rejection. Framework lint, Twig compilation, schema/migration checks, PHPUnit, dependency audit and browser walkthrough have **not** run. A Chrome installation was detected, but no browser automation tool was available and the application cannot start without dependencies. No visual verification is claimed.

The blocking errors were:

- `git init -b main`: `.git: Operation not permitted`; this session marks `.git` read-only.
- Composer: `curl error 6 ... Could not resolve host: repo.packagist.org`; shell network is restricted. Required Doctrine/EasyAdmin/Scheb packages were not available in the local Composer cache.
- `initdb`: `could not create shared memory segment: Operation not permitted` (`shmget`); the sandbox prevents PostgreSQL initialization.

No server was started, migration applied, account created, managed server contacted, code pushed or deployment attempted. Local untracked credential files have been generated and must remain untracked.

## Review handoff

Intended branch: `feat/inventory-access`. Intended base: the initial planning commit on `main`. Both base and candidate SHAs are unavailable because Git initialization was denied. Intended implementation commits:

1. Bootstrap HostDesk and mandatory MFA authentication.
2. Add server project and environment inventory.
3. Add audit history and focused acceptance checks.

Before handing this to separate Astra xhigh review: resolve environment permissions; initialize Git/main with the existing planning files; create the feature branch; install and lock dependencies; run migrations and all focused checks; fix integration failures; complete a browser walkthrough where tooling permits; commit the slice and record actual base/candidate SHAs. Implementation checks are not an independent review or approval. Do not push, merge, deploy or start Slice 02.

Framework references: [Symfony 7.4](https://symfony.com/releases/7.4), [Scheb installation](https://symfony.com/bundles/SchebTwoFactorBundle/current/installation.html), [Scheb throttling](https://symfony.com/bundles/SchebTwoFactorBundle/current/brute_force_protection.html), [EasyAdmin security](https://symfony.com/bundles/EasyAdminBundle/current/security.html).
# host-desk
