# CLAUDE.md — Infernix One

Internal Integrated Business & Operations Management System for **Infernix Evolv Hub** (Malaysian sole
proprietorship, owner: Julaiha). Requirements: `docs/SRS.md` (INF-SRS-001 v1.0). Read it before changing
behaviour. Every requirement ID (`G-xx`, `M01-xx … M13-xx`, `WF-xx`, `NFR-xx`, `UAT-xx`) must be traceable in
code, tests or docs (`docs/TRACEABILITY.md`).

## Stack (fixed — do not substitute)

| Layer | Choice |
|---|---|
| Backend | Laravel **12** modular monolith, PHP 8.3 (see D-00 in `docs/DECISIONS.md` for why not 11) |
| Modules | `app/Modules/<Name>/` plain PSR-4 (`App\Modules\Finance\…`), one `<Name>ServiceProvider` per module registered in `bootstrap/providers.php`. **No** nwidart/laravel-modules or similar. |
| Shared kernel | `app/Kernel/` (`App\Kernel\…`): Identity, Audit, Documents (contracts), Numbering, Money, Approval, Notifications |
| DB | PostgreSQL 16 — the single authoritative transactional DB. Tests run against real Postgres (triggers are part of the rules). |
| Queue/cache | Redis + Laravel Horizon; `schedule:work` container for the scheduler |
| Frontend | Inertia.js v2 + Vue 3 + TypeScript + Tailwind; PrimeVue 4 for data tables/dialogs/forms |
| Files | S3-compatible **private** object storage (RustFS in dev, see D-17), disk `documents`. Nothing under `/public`. |
| PDF | Browsershot (headless Chromium) rendering Blade/HTML; DomPDF fallback (`PDF_DRIVER`) |
| Money | `brick/money` in PHP, `NUMERIC(18,4)` in Postgres, decimal strings / integer minor units on the client |
| Quality | Pest 3, Larastan level 6, Pint (laravel preset + strict types), Vitest, ESLint, vue-tsc |
| Dev env | Docker Compose: app, horizon, scheduler, vite, postgres, redis, objectstore (RustFS), mailpit |
| Locale | Timezone `Asia/Kuala_Lumpur`, base currency MYR, EN labels with BM-ready i18n keys |

## The 12 non-negotiable engineering rules

1. **Money is never a float.** `NUMERIC(18,4)` columns; `App\Kernel\Money\Money` / `MoneyCast` (brick/money)
   in PHP; decimal strings or integer minor units in TS. `tests/Architecture/MoneyNeverFloatTest.php` fails the
   build on `->float()`, `->double()`, `'float'` casts or `(float) $amount`. (SRS §6.3)
2. **Double-entry, immutable journals.** Every posted journal has `sum(debit) == sum(credit)`, enforced by a
   DB-level deferred constraint trigger AND in `PostingService`. Posted journals are immutable: corrections are
   reversals only. The DB trigger that blocks UPDATE/DELETE on posted journals allows exactly one change:
   `status posted → reversed` together with `reversed_by_id`, nothing else on the row; `journal_lines` of a
   posted journal are never updatable. (M01-06, M01-08, UAT-05, UAT-09)
3. **Server-side, deny-by-default authorization everywhere** — every route, API, file download, report and
   export. Laravel Policies + the `permissions` matrix (role × resource × action × record_scope). UI hiding is
   never a control. No `GET /storage/...` access can exist; downloads go through a signed, time-limited,
   policy-checked controller. `Gate::before` fails closed. (M06-02, UAT-01, UAT-10, NFR-02)
4. **Maker-checker.** The creator of a payment/claim/invoice cannot approve it unless an Owner override with a
   reason is recorded in audit. (UAT-11)
5. **Audit trail.** Central append-only `audit_events` (actor, action, entity, before/after JSON with
   PII/secret redaction, IP, request ID, timestamp). Audited: create/update/approve/pay/export/reveal/permission
   change/login. A Postgres trigger RAISEs on UPDATE/DELETE of audit rows. (G-06, M12-04, NFR-03)
6. **Gap-free document numbering**: `company + document_type + fiscal_year + sequence` from
   `numbering_sequences` with `SELECT … FOR UPDATE`, allocated inside the SAME transaction that creates/issues
   the document (a failed create rolls the number back). Keep that transaction short — no PDF rendering or
   external calls while the lock is held. Cancelled numbers are retained and marked VOID. (§6.3, M03-04, M03-08)
7. **No secrets in the DB, ever.** `credentials_metadata` holds metadata + a vault item reference only. No
   column, log, backup, notification, search index or export may contain a plaintext password/API key. A
   dev/test-only **fake** sentinel (`SECRET_SENTINEL=SENTINEL-DO-NOT-LEAK-…`) is seeded and tests grep every
   export/log for it. (M13-02, M13-05, UAT-08)
8. **Period locking.** `fiscal_periods.locked_at`; any write to posted financials in a locked period is
   rejected at the service layer. (M01-08, UAT-09)
9. **Idempotency.** Payment, receipt and invoice-issuing endpoints accept `Idempotency-Key` and are replay-safe.
   (§5.1)
10. **Explicit state machines.** Enums + a transition table per entity (Draft, Submitted, PendingApproval,
    Approved, Rejected, Cancelled, Paid/Settled, Posted, Archived). Illegal transitions throw a domain exception;
    every transition runs in a DB transaction — no partial writes. (§5.1)
11. **Attachments.** MIME allow-list, size limit, SHA-256 `content_hash`, `storage_key`, version on re-upload,
    never overwrite issued documents. (G-07, M06-03)
12. **No duplicate economic events.** An asset created from a purchase references `purchase_transaction_id` and
    creates no second expense; CSR/activity/certification/subscription records LINK to finance transactions.
    (M05-01, WF-02, UAT-03, M07-02, M08-02, M09-04, WF-06, UAT-12)

## Module map

| ID | Namespace | ID | Namespace |
|---|---|---|---|
| M01 | `App\Modules\Finance` | M08 | `App\Modules\Csr` |
| M02 | `App\Modules\Projects` | M09 | `App\Modules\Certifications` |
| M03 | `App\Modules\Commercial` | M10 | `App\Modules\Procurement` |
| M04 | `App\Modules\HumanResources` | M11 | `App\Modules\Reporting` |
| M05 | `App\Modules\Assets` | M12 | `App\Modules\Administration` |
| M06 | `App\Modules\DocumentCentre` | M13 | `App\Modules\Credentials` |
| M07 | `App\Modules\Activities` | — | `App\Kernel` (shared kernel) |

Module folder convention (`app/Modules/<Name>/`):

```
<Name>ServiceProvider.php   Database/Migrations  Database/Seeders  Models  Policies  Services
Events  Listeners  Enums  Http/Controllers  Http/Requests  Http/Resources  Routes/web.php  Jobs  Reports
```

Dependency rule: modules talk to each other through **events and IDs only** (see `docs/ARCHITECTURE.md`).
Only `App\Modules\Finance\Services\PostingService` creates journals; other modules emit events or call typed
posting recipes. `tests/Architecture/ArchitectureTest.php` enforces the boundaries it can.

## Naming conventions

- Tables: `snake_case` plural (`journal_lines`, `claim_lines`, `entity_document_links`). PK `id` bigint; FKs
  `<table_singular>_id`. Money columns `NUMERIC(18,4)`; dates `date`, timestamps `timestamptz`.
- Enums: PHP backed enums in `Enums/` with `snake_case` string values mirrored by a Postgres `CHECK`.
- Document numbers: `QT-2026-0001`, `INV-`, `RC-`, `PV-`, `CN-`, `PO-`, `JV-` (configurable in
  `numbering_sequences`).
- Services: verbs (`PostingService::post()`, `NumberingService::allocate()`); posting recipes
  `<Event>Posting` (`ClaimApprovalPosting`). Policies: `<Model>Policy`. Events: past tense (`PaymentPosted`).
- Vue: `resources/js/Pages/<Module>/<Page>.vue`, shared components in `resources/js/Components/`. Props and
  Inertia payloads are typed in `resources/js/types/`. Money props are `string` (decimal) — never `number`
  except integer minor units named `*_minor`.
- Shared UI (Prompt 1c): every page uses `AppLayout` (title, breadcrumbs, status chip, `#actions`) and
  `<DataTable>` for lists — server mode via `useServerTable` for paged data (server whitelists `sort`/`per_page`).
  Use `<StatusChip>`, `<PickerModal>`, `<Stepper>`, `<MoneyInput>` (decimal string or `*_minor` integer only),
  `<FileDropzone>`, `<Modal>`; do not build one-off tables, pickers or money inputs.
- i18n: labels via keys (`finance.claims.status.approved`) with EN now, BM later (NFR-12).

## Requirement traceability

- Every Pest test is tagged: `->group('M01-05')` (multiple IDs allowed, e.g. `->group('UAT-04', 'M03-03')`).
- Every migration, service, policy and controller carries a `@see SRS M01-05` docblock naming the IDs it
  implements.
- `docs/TRACEABILITY.md` is updated in the same commit as the code: ID, phase, status, module, test IDs.
- Run a requirement's tests with `php artisan test --group=UAT-04`.

## Running things

```bash
docker compose up -d && make setup      # full dev stack, app at http://localhost:8010 (APP_PORT in .env)
make test        # Pest (Postgres) + Vitest
make lint        # Pint --test, Larastan L6, ESLint, vue-tsc
make fix         # Pint + eslint --fix
make ci          # everything CI runs
```

Without Docker (local Postgres on 127.0.0.1, Redis on 6379): `php artisan test`, `vendor/bin/pint --test`,
`vendor/bin/phpstan analyse`, `npm run test`, `npm run lint`, `npm run typecheck`, `npm run build`.

Seeded login: `SEED_OWNER_EMAIL` / `SEED_OWNER_PASSWORD` from `.env` (defaults `owner@infernix.local` /
`ChangeMe-Infernix-2026!`; the seeder refuses the default password in production).

## Working rules for Claude Code

- Larastan, Pint, Pest and Vitest must be green before a prompt is considered done; commit with conventional
  commits (`feat(finance): …`, `test(m01): …`, `docs: …`). Never rewrite history.
- Never edit an existing migration once committed; add a new one.
- Never add a float for money, a public disk, a raw secret column, a hard delete of posted financials, or a
  route without a policy check — if the SRS seems to require one of these, write the concern in
  `docs/DECISIONS.md` instead.
- Seed data that is a business assumption (rates, COA, thresholds) is marked `PLACEHOLDER` in a `notes` column.
- Keep the shared `docs/*.md` current in the same commit as the code they describe.
