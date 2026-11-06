# Deployment — Infernix Workspace `v1.0.0-rc1`

Single-company, single-host deployment (SRS §6: ~10–30 users). Everything below is the *minimum* for production;
items marked **PLACEHOLDER** are business or hosting choices still to be confirmed.

## 1. Topology

```
Internet ──TLS 1.2+──▶ reverse proxy (nginx / Caddy, HSTS) ──▶ php-fpm (app image)
                                                          ├─▶ PostgreSQL 16 (private network only)
                                                          ├─▶ Redis 7 (password, private network only)
                                                          └─▶ S3-compatible object storage (private buckets: documents, backups)
workers: horizon (queues default, notifications, pdf, exports, imports) · scheduler (php artisan schedule:work)
```

| Component | Production setting |
|---|---|
| Web | **php-fpm 8.3 + nginx** built from `docker/php/Dockerfile` (same extensions + Chromium). The image's default `php artisan serve` CMD is for development only — override it with `php-fpm` and put nginx in front. |
| Database | PostgreSQL 16, `DB_*` app user = owner of the schema; nothing else connects except the optional read-only BI login (docs/POWER_BI.md). Encrypted disk / managed service. |
| Queue / cache / session store | Redis with `REDIS_PASSWORD`; Horizon dashboard is owner-only (A-91). |
| Files | `FILESYSTEM_DISK`/`documents` = S3 private bucket (versioning on, public access blocked). **No** public disk; every download is a signed, policy-checked route. |
| Mail | SMTP relay with SPF/DKIM for the sending domain (**PLACEHOLDER** provider). |
| Region | Malaysian / regional data residency per PDPA review (**PLACEHOLDER**, see DECISIONS "Pending legal review"). |

## 2. Required environment

Start from `.env.example`; in production:

```
APP_ENV=production   APP_DEBUG=false   APP_URL=https://<host>   APP_KEY=<php artisan key:generate --show>
APP_TIMEZONE=Asia/Kuala_Lumpur   APP_RELEASE=v1.0.0-rc1
SESSION_DRIVER=redis   SESSION_ENCRYPT=true   SESSION_SECURE_COOKIE=true   SESSION_LIFETIME=60
QUEUE_CONNECTION=redis   CACHE_STORE=redis
LOG_CHANNEL=json   LOG_LEVEL=info
BACKUP_DISK=backups   BACKUP_BUCKET=<private bucket>   BACKUP_PASSPHRASE=<from the secret store>
VAULT_DRIVER=bitwarden|onepassword   (+ that adapter's token, from the secret store)
SENTRY_DSN=<optional>   OTEL_EXPORTER_OTLP_ENDPOINT=<optional>
FEATURE_MYINVOIS=false   (until the accountant confirms obligations, A-82)
SEED_OWNER_EMAIL / SEED_OWNER_PASSWORD   (first seed only; the seeder refuses the default password)
```

Secrets (`APP_KEY`, DB / Redis / S3 / SMTP credentials, `BACKUP_PASSPHRASE`, vault token) come from the host's
secret store or orchestrator — never committed, never in the database (rule 7). `SECRET_SENTINEL` must be **unset**
in production (dev / test only). `UatDemoSeeder` and `FakeVault` refuse to run in production.

## 3. First install

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate            # once; store APP_KEY in the secret store
php artisan migrate --force
php artisan db:seed --force         # roles, permission matrix, COA, dictionaries, numbering, owner (PLACEHOLDER data flagged in notes)
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

Do **not** run `php artisan storage:link`: there is no public disk, and files must only be served by the signed,
policy-checked download routes.

Then sign in as the owner, enrol MFA, and follow docs/ADMIN_MANUAL.md §1 (company profile, bank accounts, fiscal
year, users). Migrate legacy data with Admin → Data import (validate first) or `php artisan import:csv … --dry-run`.

Processes to supervise (systemd / compose `restart: unless-stopped`): php-fpm, nginx, `php artisan horizon`,
`php artisan schedule:work`.

## 4. Upgrades / release procedure (NFR-11)

1. CI green on the release commit (`make ci`: Pint, Larastan L6, Pest on Postgres incl. the route matrix and
   sentinel sweep, Vitest, ESLint, vue-tsc, build) and `php artisan api:openapi --check`.
2. Take an on-demand backup: Admin → Backups → *Run backup now* (or `php artisan backup:run backup`).
3. `php artisan down --render="errors::503"` → deploy code → `composer install --no-dev -o` → `npm ci && npm run build`
   → `php artisan migrate --force` → cache commands from §3 → `php artisan horizon:terminate` → `php artisan up`.
4. Smoke: `GET /health/ready` = 200, sign in, open the owner dashboard; optionally `k6 run scripts/k6/smoke.js`.
5. Rollback: migrations are additive (never edited once committed); roll back code to the previous tag; restore the
   pre-deploy backup only if data was damaged (runbook §6).

## 5. Monitoring (NFR-04, NFR-10)

| Signal | Where |
|---|---|
| Liveness / readiness | `GET /health/live` (process up), `GET /health/ready` (DB, cache, Redis, storage; 503 when a critical check fails; reports backup freshness ≤ 26 h) — point the uptime monitor at `/health/ready`. Target availability ≥ 99.5 % monthly. |
| Errors | JSON logs (`storage/logs/infernix.json*.log` → log shipper), Sentry when `SENTRY_DSN` is set; every line carries `request_id` (also returned as `X-Request-Id`). |
| Queues | Horizon (owner only): failed jobs, wait times. |
| Backups | Admin → Backups: last run, size, last restore test; scheduler runs the backup nightly at 01:30 and a restore test quarterly. |
| Security events | Audit log (Admin → Audit log): logins, failed MFA, reveals, exports, permission changes. |

## 6. Backup & restore runbook (NFR-06, M12-06)

- **What**: `pg_dump -Fc` of the database, encrypted with AES-256-CBC (`openssl -pbkdf2 -iter 200000`, `BACKUP_PASSPHRASE`), uploaded to
  the private `backups` disk; documents live in the versioned S3 bucket (enable bucket versioning + replication).
- **Retention** (PLACEHOLDER): 14 daily, 8 weekly (Sundays), 12 monthly (1st) — `php artisan backup:run prune`.
- **RPO ≤ 24 h, RTO ≤ 8 h** targets.
- **Restore test** (quarterly, or `make restore-test`): restores the newest backup into `infernix_restore_check` and
  checks that journals balance and row counts are sane; the result is recorded in `backup_runs` and shown on the
  Backups page.
- **Real restore**:
  1. `php artisan down`; stop horizon and the scheduler.
  2. Download the chosen `*.dump.enc` from the backups bucket.
  3. `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in file.dump.enc -out file.dump`
  4. `pg_restore --clean --if-exists --no-owner -d "$DB_DATABASE" file.dump`
  5. `php artisan migrate --force` (no-op when same version) → `php artisan up` → check `/health/ready`, trial
     balance and the audit log. Record the restore (who, why, which file) in the audit / incident log.

## 7. Hardening checklist

- [ ] TLS with HSTS at the proxy; HTTP → HTTPS redirect; `APP_URL` is https.
- [ ] `APP_DEBUG=false`; `/horizon` reachable only for the owner; Telescope / Ignition not installed in production.
- [ ] DB and Redis not exposed publicly; Redis password set.
- [ ] Buckets private, public-access block on, versioning on (documents) and lifecycle rules (backups).
- [ ] Owner and FIN users enrolled in MFA (enforced); default seed password changed.
- [ ] Vault adapter configured (credentials reveal) or left disabled (NullVault: deep links only).
- [ ] `BACKUP_PASSPHRASE` stored in two places outside the server (owner + accountant / escrow).
- [ ] Restore test passed after go-live and recorded.
