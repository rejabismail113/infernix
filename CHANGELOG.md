# Changelog — Infernix Workspace

All notable changes. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow the build
phases of INF-SRS-001 (P1 … P4). Requirement IDs refer to docs/SRS.md; decisions to docs/DECISIONS.md.

## [Unreleased]
### Changed
- Product renamed **Infernix Workspace**: redesigned sign-in screens (frosted card, orange waves, phoenix logo), new
  sidebar logo, favicon, page titles, emails and MFA issuer. Company documents keep the Infernix Evolv Hub name.
- Quotations, invoices, letters and certificates issued from now on carry the phoenix logo above the company name;
  documents already issued are unchanged.

## [1.0.0-rc1] — 2026-11-06 — Phase P4 (Prompt 6): hardening, integrations, handover

Release candidate: every P0 requirement is done with tests (docs/TRACEABILITY.md); P1/P2 items still open are listed
there with reasons. UAT evidence: docs/UAT_EVIDENCE.md.

### Added
- **Bank reconciliation (M01-07, WF-08)**: import profiles per bank (generic, Maybank, CIMB — PLACEHOLDER layouts),
  prioritised auto-match rules (unique candidate only), one-to-many and many-to-one matching capped by a DB trigger,
  unmatched ageing report, and a period reconciliation report with a balance proof.
- **Reporting (M11-03, M11-06)**: read-only `reporting` schema with a SELECT-only role for Power BI
  (docs/POWER_BI.md), project dashboard, scheduled report emails executed with each recipient's own permissions.
- **MyInvois e-Invoice (§6.2)**: `EInvoiceGateway` stub behind `FEATURE_MYINVOIS` (off), replay-safe outbox keyed by
  invoice, exponential backoff, rate limiting, append-only event log, and a banner requiring accountant verification.
- **Platform hardening (NFR-01, -04, -05, -06, -09, -10, -12)**: CSP with nonce and full security headers,
  encrypted 60-minute sessions, request IDs on every log line / audit row / error page, JSON logs, Sentry hook,
  `/health/live` + `/health/ready`, nightly encrypted backups with retention, quarterly restore test and a Backups
  page, `perf:benchmark` (50k rows, all p95 far under 3 s — docs/PERFORMANCE.md), WCAG AA colour palette (axe: 0
  serious issues), EN / BM language toggle.
- **Data migration (M12)**: CSV importers for opening balances, clients, vendors, staff and assets — template,
  validation report, all-or-nothing, dry run (UI + `php artisan import:csv`).
- **Security suite (NFR-02, UAT-01, UAT-08, UAT-10)**: route × role matrix for all 391 routes and 7 roles, generated
  from and checked against docs/PERMISSION_MATRIX.md (fails on unlisted routes or drift); IDOR test on every `{id}`
  route; secret-sentinel sweep across every DB row, file, log, mail and the search index.
- Approval matrix editor (M12-03): effective-dated, audited versions per document type with coverage validation.
- Global search now also covers projects, clients, staff (no identity data) and client / vendor documents (G-11).
- Handover: docs/DEPLOYMENT.md, docs/API.md + generated docs/openapi.json (`php artisan api:openapi`),
  docs/ADMIN_MANUAL.md, docs/UAT_EVIDENCE.md with screenshots (`make demo`, `make uat-screens`).

### Fixed
- **Security**: a staff member could overwrite another staff member's bank details (IDOR). Company-wide gates now
  require an `all` grant (`PermissionService::canOnAll`).
- Granting access to a credential without a vault failed (`vault_sync` column too short).
- Payment batch page crashed in non-production (strict mode).
- Secret redaction could break already-encoded JSON; pasted secrets in any free-text field are now stored redacted.

### Security
- Horizon dashboard restricted to an active Owner through the full app middleware stack.

## [0.3.0-p3] — 2026-11-06 — Phase P3 (Prompt 5)
### Added
- Assets (M05) from purchases without double counting, depreciation events, maintenance, disposal.
- Activities & travel (M07), CSR programmes with evidence and in-kind contributions (M08), certifications &
  memberships with 90/60/30 renewals (M09), purchase requests, GRN, subscriptions and renewal reviews (M10).
- Credentials register (M13): metadata + vault reference only, Bitwarden / 1Password adapters, entitlements, audited
  reveal, joiner / mover / leaver checklists; secret scrubbing on logs, notifications and search.

## [0.2.0-p2] — 2026-11-06 — Phase P2 (Prompt 4)
### Added
- Projects & training (M02): leads, milestones, tasks, team, sessions, close-out.
- HR & part-time staff (M04): encrypted identity / bank data with audited reveal, versioned rate agreements,
  timesheets, payment batches with maker-checker and payroll hand-off.
- Commercial generators (letters, memos, appointment letters, certificates) and vendor / client POs (M03).

## [0.1.0-p1] — 2026-11-06 — Phase P1 (Prompts 0–3)
### Added
- Identity, MFA, invitations, permission matrix, delegations, master data, gap-free numbering, append-only audit
  trail (M12, G-01…G-12).
- Document Centre (M06): private storage, signed downloads, versions, legal hold, row-filtered search.
- Application shell and shared UI components.
- Finance core (M01): double-entry ledger with DB-enforced balance and immutability, registers, claims with
  mileage, bills, payments with partial settlement and idempotency, bank import, period locking, tax pack.
- Commercial (M03): quotations, invoices, receipts, credit notes, payment vouchers with PDF templates; dashboards
  and Export Centre (M11).
