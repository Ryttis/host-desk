# HostDesk

Work only in this repository. CoreHost is a functional reference, never a code source.

Symfony 7.4, Twig/EasyAdmin/AssetMapper, Doctrine/PostgreSQL, Symfony Security and Scheb TOTP. Keep implementations explicit and small. Never substitute SQLite.

Slice 01 covers authenticated inventory only. No remote connections, operations, observations, external APIs, deployments or speculative tables. All roles require confirmed MFA. Inventory mutations require Admin and atomic safe audit entries. Archive rather than delete.

Preserve unrelated changes. Never commit credentials, keys, passwords, TOTP data or runtime files. Use isolated local/test PostgreSQL databases. Run focused meaningful checks; report implementation validation honestly, never as independent review.

Stop at a committed candidate on feat/inventory-access for separate Astra xhigh review. Do not push, merge, deploy, launch another review agent or begin Slice 02 without explicit instruction.
