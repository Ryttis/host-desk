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
Slice 01 is complete as a locally validated implementation candidate on `feat/inventory-access`, with review base `e15f7d0`. The existing source was preserved and completed; Git/main were not reinitialized or rewritten.

Dependencies are installed and locked on the Symfony 7.4 stack. The isolated project PostgreSQL cluster contains separate `hostdesk` and `hostdesk_test` databases, with all three migrations applied and schemas verified. Container, YAML, Twig, asset compilation, Composer validation/platform checks and dependency audit passed. PHPUnit passed 27 tests with 235 assertions. Desktop Chrome completed login, enrollment, mandatory TOTP for all three roles, Admin server create/edit/archive and audit browsing, and reader permission denial.

Integration fixes include explicit Symfony 7.4 transitive constraints, EasyAdmin Twig component registration/configuration, PostgreSQL subclass support in the test safety guard, session-version enforcement during pending MFA, and row locking/refresh for safe stale edits and accurate audit values. No speculative tables or Slice 02 features were added.

The three commits separate runtime/authentication, audited inventory, and acceptance tests/documentation. The candidate SHA is the branch HEAD recorded in the implementation handoff. README contains exact startup commands, actual validation and limits. Temporary credentials, keys, browser files, database data, dependencies and generated assets remain ignored.

No implementation blockers remain. Separate Astra xhigh review is pending; these checks are implementation validation, not independent review or approval. Do not push, merge, deploy, launch another review agent or begin Slice 02 without explicit instruction.
