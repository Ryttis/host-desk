# HostDesk plan

## Purpose
A small municipal infrastructure inventory for approximately 2–3 servers and 12–13 applications with stage/prod environments. Infrastructure facts and credentials remain unverified. All demo details are fictional.

## Fixed architecture
Symfony 7.4 LTS, compatible PHP, Twig, EasyAdmin, AssetMapper, Doctrine ORM/PostgreSQL, Symfony Security and maintained Scheb TOTP. Local individual accounts, mandatory confirmed MFA, encrypted TOTP secrets with a protected external key, fixed Viewer/Operator/Admin roles. Explicit inventory fields and atomic safe audit records. No remote actions in Slice 01.

Only User, Server, Project, ProjectEnvironment and AuditEntry entities. Prefer framework features and maintained packages; no generic metadata, provider or event-sourcing systems.

## Roadmap
01 inventory-access — authenticated inventory (current).
02 read-only-observations — SSH, status, HTTP/TLS, restricted logs.
03 pilot-stage-deployment — Deployer, operations, stage configuration.
04 pilot-backup-restore — off-host backup and demonstrated isolated restore.
05 pilot-production-release — stage-tested SHA to prod, code rollback.
06 dns-certificate-actions — scoped Cloudflare and TLS operations.
07 process-schedule-actions — assigned workers and schedules.
08+ adopt-<project>-<operation> — individual legacy integrations.

Slices 01–05 are sequential. After 05 select DNS/TLS, process management or individual legacy integrations according to actual need. Process management does not depend on DNS automation.

## Status
Slice 01 is an implementation candidate on `feat/inventory-access`, with review base `e15f7d0`. The checkpoint contains the implementation. Safe `.env` and `.env.test` defaults were restored, and the Symfony lockfile was updated for published security advisories.

On 2026-11-06, fresh local keys and isolated development/test PostgreSQL 18 databases were created. Both databases migrated and matched the Doctrine mappings. Composer validation, platform requirements and audit, container/YAML/Twig lint, assets, migration status and PHPUnit (27 tests, 235 assertions) passed on PHP 8.5.4. Earlier test and browser results belong to a different environment. Headless Chrome rendered the login form; a full authenticated browser walkthrough on this machine remains unverified.

Separate Astra xhigh review is pending; these checks are implementation validation, not independent review or approval. Do not push, merge, deploy, launch another review agent or begin Slice 02 without explicit instruction.
