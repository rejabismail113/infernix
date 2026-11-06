# HTTP interface — Infernix One `v1.0.0-rc1`

The full machine-readable reference is **[openapi.json](openapi.json)** (OpenAPI 3.1, 391 operations), generated
from the live route table and the route × role matrix in [PERMISSION_MATRIX.md](PERMISSION_MATRIX.md):

```bash
php artisan api:openapi          # regenerate (make openapi)
php artisan api:openapi --check  # exit 1 if the committed file is stale — enforced by tests/Feature/Platform/OpenApiTest.php
```

Each operation carries `x-roles` (roles that reach the action), `x-route-matrix` (the A/V/R/D/N outcome per
role), and where relevant `x-requires-recent-auth`, `x-signed-url`, `x-rate-limit` and the `Idempotency-Key`
header. Open it in any OpenAPI viewer (Swagger UI, Redoc, Stoplight, or the VS Code OpenAPI extension).

## 1. Nature of the interface

Infernix One is a server-rendered **Inertia.js** application, not a public REST API. There are no API tokens and
no third-party clients in v1. Every route sits behind the same session, MFA and authorization stack:

| Concern | Behaviour |
|---|---|
| Authentication | Session cookie after login. OWN/FIN need TOTP MFA (`/mfa/challenge`). Sensitive actions (reveals, data import, manual backup, permission changes and the other routes marked in openapi.json) need re-authentication within the last 10 minutes (`infernix.security.recent_auth_minutes`) (`x-requires-recent-auth`). |
| CSRF | All non-GET requests need the `X-XSRF-TOKEN` header (Inertia / axios send it automatically) or a `_token` field. |
| Authorization | Deny by default: a Policy + the permission matrix (role × resource × action × record scope) on every route, download and export; a record outside the caller's scope returns 403 or 404. UI hiding is never the control. |
| Responses | GET pages: HTML on first load, Inertia JSON (`{component, props, url, version}`) with `X-Inertia: true`. Writes: `302` back with a `success` / `error` flash, `422` with an error bag, `403` / `404`. |
| Idempotency | Money-moving and issuing endpoints — `POST /finance/payments`, `POST /finance/payments/{payment}/post`, `POST /commercial/receipts`, `POST /commercial/{quotations,invoices,credit-notes,vendor-pos}/{document}/issue`, `POST /hr/batches/{batch}/pay` — accept `Idempotency-Key: <uuid>` (the UI always sends one). A replay with the same key returns the stored first response and never repeats the effect; a concurrent duplicate gets `409` (§5.1). |
| Rate limits | Per route (`x-rate-limit` in openapi.json): login, MFA, re-auth and invitation accept 10/min; password reset 5/min; credential reveal 10/min, staff reveals 20/min; exports 20/min; imports 10/min; manual backups 5/min; search 60/min; uploads 30/min. Excess → `429` with `Retry-After`. |
| Tracing | Send `X-Request-Id: <uuid>` to correlate; otherwise one is minted. It is echoed in the response, written on every log line and stored on audit events. |
| Files | Downloads are signed, time-limited URLs (`x-signed-url`) that are still policy-checked; nothing is served from a public path. |
| Security headers | CSP with a per-request nonce, `X-Frame-Options: DENY`, `nosniff`, strict Referrer-Policy, Permissions-Policy, COOP, HSTS (production), `Cache-Control: no-store` for signed-in pages. |

## 2. JSON endpoints

These return JSON to in-app widgets and the health monitor. They follow the same auth and authorization rules.

| Method & path | Name | Purpose | Notes |
|---|---|---|---|
| `GET /health/live` | `health.live` | process is up | public, no data |
| `GET /health/ready` | `health.ready` | DB, cache, Redis, storage, backup freshness | public; status booleans only, 503 if a critical check fails |
| `GET /search?q=` | `search` | global search | every hit is re-checked against its record policy; snippets are redacted |
| `GET /finance/records/search` | `finance.records.search` | picker for linking finance records (CSR, activities, certifications) | finance view permission |
| `GET /finance/bank/{bank}/ledger-lines` | `finance.bank.ledger-lines` | open ledger lines for manual / group matching | bank reconcile permission |
| `GET /activities/{activity}/cost-candidates` | `activities.activities.cost-candidates` | finance records that may be linked as trip costs | link only, never posts |
| `GET /documents/link-targets` | `documents.link-targets` | entities a document may be linked to | scoped to the caller |
| `POST /credentials/{credential}/reveal` | `credentials.credentials.reveal` | one-time secret from the vault | recent auth + reason + entitlement; `Cache-Control: no-store`; audited without the value |
| `POST /hr/staff/{staff}/reveal-id` · `reveal-bank` | `hr.staff.reveal-*` | unmasked NRIC / bank account | recent auth + reason; audited |

## 3. Integrations

| Integration | Interface | Status |
|---|---|---|
| Power BI / Excel | read-only Postgres schema `reporting` via a SELECT-only login | available — docs/POWER_BI.md |
| MyInvois (LHDN e-Invoice) | `App\Modules\Commercial\EInvoice\EInvoiceGateway` + outbox | stub only, `FEATURE_MYINVOIS=false` until the accountant confirms obligations (A-82) |
| Password vault | `App\Kernel\Secrets\Contracts\VaultClient` (Bitwarden Secrets Manager, 1Password Connect) | configured per deployment |
| Bank statements | CSV import profiles per bank (Finance → Bank → Settings) | available |
| Data migration | CSV importers (Admin → Data import, `php artisan import:csv`) | available, dry-run first |

## 4. Console commands

| Command | Purpose |
|---|---|
| `php artisan backup:run {backup\|restore-test\|prune}` | encrypted backup, restore verification, retention |
| `php artisan import:csv {type} {file} --dry-run --as=<email> [--date=]` | clients, vendors, staff, assets, opening balances (`--template` prints the header) |
| `php artisan perf:benchmark --rows=50000 [--write]` | p95 benchmark on a rolled-back 50k-row dataset |
| `php artisan api:openapi [--check]` | this document's OpenAPI file |
