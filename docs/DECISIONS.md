# Decisions log

Open decisions from SRS §9.1 (all "Pending" owner sign-off) and the DEFAULT assumed so the build can
proceed. Each default is reversible and, where it touches business policy, is implemented as configuration
flagged `PLACEHOLDER` / "pending accountant review" rather than hard-coded. Numbered `D-xx`; engineering
deviations are `D-0x`.

## Engineering deviations from the build prompt

| ID | Decision | Default taken | Why / how to reverse |
|---|---|---|---|
| D-00 | Laravel version | **Laravel 12.x** (framework ^12.69) instead of the prompt's "Laravel 11" | At bootstrap time (2026-11-05) `composer audit` reported a **critical** unpatched advisory for Laravel 11 (CVE-2026-114509, TokenGuard auth bypass; fixed only in ≥12.68) plus four others. Laravel 11 is out of security support. Laravel 12 keeps the identical modular-monolith structure and `bootstrap/providers.php` convention, so nothing else in the prompt changes. Reverse: `composer require laravel/framework:^11` — not recommended. |
| D-01 | Component library | PrimeVue 4 (Aura preset re-themed to Infernix orange) | Mature DataTable (server-side paging/sort/filter, column chooser), Dialog, Steps match the reference screenshots; shadcn-vue would require hand-building the grid. |
| D-02 | Testing database | Pest runs against a real PostgreSQL 16 (`infernix_test`), never SQLite | DB triggers/constraints are part of the rules (journals, audit append-only, allocation ceilings); SQLite cannot prove them. CI starts a Postgres service. |
| D-03 | Module structure | Plain PSR-4 under `app/Modules/<Name>` + `app/Kernel`; one provider each in `bootstrap/providers.php` | Per prompt; no module package. The shared kernel is `App\Kernel` (not a 14th module) so the "kernel never depends on modules" rule is checkable by namespace. |
| D-04 | Logo asset | `public/images/infernix-logo.svg` is a **PLACEHOLDER** wordmark | The real `docs/reference/infernix-logo.jpg` and the 3 UI screenshots were shared inline in chat, not as files, so they could not be copied into the repo. Drop them into `docs/reference/` (see its README) and Prompt 1c will wire the real file. |
| D-05 | phpstan/phpstan install | Standard composer.lock; in restricted-network environments the archive-only `phpstan/phpstan` package is installed from its git tag | Only affects sandboxes without GitHub API access; `composer install` is standard elsewhere. |

## SRS §9.1 open decisions — defaults assumed

| ID | SRS decision | Default assumed for the build | Configurable where | Flag |
|---|---|---|---|---|
| D-10 | Finance policy: accounting basis | **Accrual-capable double-entry ledger** (invoices post AR/revenue at issue; bills post AP/expense at approval) with a **cash-basis toggle** on P&L and tax-pack reports. | `settings: finance.reporting_basis` (accrual\|cash) | pending accountant |
| D-11 | Chart of accounts | Seed a Malaysian sole-proprietor starter COA (Bank, Cash, AR, AP, Staff Claims Payable, Owner Capital, Owner Drawings, Owner Current Account, Project/Training Revenue, Grants, Other Income, expense accounts per seeded category, Fixed Assets, Accumulated Depreciation, Low-value Equipment, Consumables, Suspense). | `chart_accounts` (editable, `notes='PLACEHOLDER'`) | pending accountant |
| D-12 | Owner capital / drawings | Owner-paid business expenses credit **Owner Current Account** (liability to owner); items classed `OWNER_DRAWING` debit **Owner Drawings** (equity) and are excluded from P&L and the expense-by-category report. Capital injections credit Owner Capital. | posting recipes + COA mapping in `settings` | pending accountant |
| D-13 | Depreciation | Straight-line schedule table generated per asset; **posting of depreciation journals is behind `FEATURE_DEPRECIATION_POSTING=false`**. Capitalisation threshold default RM 2,000 (PLACEHOLDER); low-value equipment RM 200–2,000; below = consumable. | `settings: assets.capitalization_threshold`, `assets.low_value_threshold` | pending accountant |
| D-14 | Tax treatment | Every expense defaults to `tax_treatment = pending_review`; only FIN/OWN/AUD may set deductible / non_deductible / capital. Tax pack lists all `pending_review` items as review flags. SST not applied by default (tax codes seeded: `NONE`, `SST-8`, `SST-6` as PLACEHOLDER, inactive). | `dictionary: tax_code` | pending accountant |
| D-15 | Claim limits & rates | Mileage **RM 0.60/km**, per-diem **RM 50/day**, medical cap **RM 500/yr** — all PLACEHOLDER; receipt mandatory for every claim type except mileage; approval thresholds: ≤RM 500 PM/HR, ≤RM 5,000 FIN, above FIN + OWN. | `claim_types`, `claim_type_rates`, `approval_matrices` | pending owner |
| D-16 | Roles | Exactly the 7 roles OWN/FIN/PM/HR/STF/AUD/APP; per-action permissions seeded from `docs/PERMISSION_MATRIX.md`; Owner exception = maker-checker override with mandatory reason, audited. | `permissions`, `approval_matrices` | pending owner |
| D-17 | Source of truth / infra | PostgreSQL 16 + MinIO (S3 API) in dev; production = managed Postgres + S3-compatible bucket in the `ap-southeast-1` (Singapore) region or a Malaysian provider, single VPS Docker Compose as the baseline deployment (docs/DEPLOYMENT.md, Prompt 6). | `.env` | pending owner |
| D-18 | Vault product | `VaultClient` interface with `NullVault` (dev: shows vault reference + deep link only). Adapters for **Bitwarden Secrets Manager** and **1Password Connect** behind `VAULT_DRIVER`. No in-house secret storage, ever. | `VAULT_DRIVER` | pending owner |
| D-19 | Templates | Blade/HTML templates rendered by Browsershot; issuer profile (logo, SSM no, address, bank, signatory) is snapshotted at issue. Placeholder legal wording marked `[PLACEHOLDER — legal review]`. Signature image optional. | `document_template_versions`, company profile | pending owner / legal |
| D-20 | Deployment, backup, DR | Nightly `pg_dump` encrypted (age/GPG) to a separate bucket, 30 daily + 12 monthly retention; quarterly `make restore-test`; RPO 24h / RTO 8h as SRS NFR-06. | `docker-compose.prod.yml`, scheduler | pending owner |
| D-21 | Statutory configuration | No automatic LHDN/SST/e-Invoice submission. MyInvois adapter behind `FEATURE_MYINVOIS=false` with a settings banner "requires accountant verification". Payroll statutory (EPF/SOCSO/EIS) is **out of scope**; payment batches hand off a CSV to the payroll process. | feature flags | pending accountant |
| D-22 | Notifications | Mailpit in dev; SMTP provider via env in prod (`MAIL_*`); in-app bell always on; reminder lead days 90/60/30 for renewals, overdue invoices at +1/+14/+30 days (PLACEHOLDER). | `notification_rules` | pending owner |
| D-23 | Data migration | CSV importers (Prompt 6) for opening balances, clients, vendors, staff, assets with dry-run + validation report; opening balances post one `JV` journal into period 1 of the first fiscal year. | importer commands | pending owner |
| D-24 | Fiscal year | Calendar year (1 Jan – 31 Dec), monthly periods; first fiscal year = 2026. | `fiscal_years` | pending accountant |
| D-25 | Multi-currency | MYR only; `currency` columns exist (CHECK = 'MYR') to allow a later phase. | — | — |
| D-26 | Receipt before cash | Receipts require a bank account + date + amount and are posted (Dr Bank / Cr AR) before a PDF can be generated (SRS M03-04). Cash received without an invoice posts to "Unapplied Receipts" (liability) until allocated. | COA mapping | pending accountant |
| D-27 | Document numbering | Formats `QT-{YYYY}-{0001}`, `INV-`, `RC-`, `PV-`, `CN-`, `PO-`, `JV-`, `CL-` (claims), `BL-` (bills), `PR-`; fiscal-year reset; void retains the number. | `numbering_sequences` | pending owner |
| D-28 | Session / MFA | TOTP (RFC 6238) via `pragmarx/google2fa`; enforced for OWN and FIN at first login; optional otherwise; recovery codes; recent-auth window 10 minutes for sensitive actions. | `settings: security.*` | — |
| D-29 | Retention defaults | Finance documents 7 years after fiscal year end; HR documents 7 years after end of engagement; CSR photos 3 years unless legal hold; audit events never purged. | `retention rules` | pending legal (PDPA) |

## Pending legal review (PDPA 2010 / Amendment 2024)

Places where personal data is stored, with the retention/consent default assumed. All are flagged for
review by the business owner before production.

| Data | Table / column | Classification | Who can see plaintext | Retention default | Consent / lawful basis assumed |
|---|---|---|---|---|---|
| Staff NRIC / passport number | `staff_profiles.id_number` (encrypted cast) | restricted | HR, OWN via audited `reveal`; masked elsewhere | 7 years after engagement end | Employment / contract necessity; stored for statutory and payment identification |
| Staff contact details, address | `staff_profiles.contact` | confidential | HR, OWN, self; PM sees name + role only | 7 years after engagement end | Contract necessity |
| Staff bank details | `staff_bank_details` (encrypted) | restricted | HR, OWN; FIN only during a payment run (audited) | 7 years after last payment | Payment necessity |
| Medical claim clinical notes / receipts | `claim_lines.clinical_note`, linked documents `classification=medical` | medical (sensitive personal data) | HR, OWN, claimant; FIN sees amount + receipt only | 7 years (finance evidence) — clinical note purged with the claim after retention | Explicit consent at claim submission (checkbox text PLACEHOLDER) |
| Training participant lists (name, organisation, email, attendance) | `training_participants` | confidential | PM (assigned), OWN, HR | 3 years after session, or until certificate validity ends | Consent flag per participant (`consent_given`); public verify endpoint reveals validity only, no PII |
| CSR photos & volunteer lists | `csr_evidence` documents, `csr_volunteers` | restricted | OWN, CSR PM, HR | 3 years, legal hold capable | Photo consent flag required before upload is marked publishable |
| Client / vendor contacts | `clients.contacts`, `vendors.contacts` | confidential | OWN, FIN, PM (assigned) | life of relationship + 7 years for finance evidence | Contract necessity |
| User accounts (email, login IP, user agent) | `users`, `audit_events.ip/user_agent` | confidential | OWN, AUD | audit events never purged; login history 2 years | Legitimate interest (security) |
| Credential ownership (who owns which system account) | `credentials_metadata.owner_id`, `credential_access_events` | restricted | OWN, resource owner, AUD (inventory only) | life of system + 2 years | Legitimate interest (security); no secrets stored |
| Approval / override reasons | `claim_approvals.reason`, `payments.override_reason` | confidential | OWN, FIN, AUD | 7 years | Finance evidence |

Required before go-live: privacy notice text for staff and training participants, consent wording for medical
claims and CSR photos, confirmation of retention periods with the accountant (finance) and legal (HR/PDPA),
and a data-subject access/erasure SOP (ADMIN_MANUAL.md, Prompt 6).

## Ambiguities found in the SRS (for owner clarification)

1. **Claims payee vs staff identity** — `claims.staff_id` is assumed to reference `staff_profiles`; users without a staff profile (e.g. Owner) submit owner-paid expenses through WF-03, not claims.
2. **"Receipt" is used for two things** — a cash receipt document (RC-, M03-04) and an uploaded purchase receipt (evidence). The data model names the first `receipts` and the second a Document Centre link with `link_type=receipt`.
3. **AUD export window** — the SRS says "time-limited"; assumed `access_from/until` on the user plus every export audited. No minimum/maximum window specified; default 90 days.
4. **APP role limits** — "scoped to entity/type/limit": assumed a delegation row per entity type with an amount ceiling and date window; approving above the ceiling escalates to the delegator.
5. **Project status_payment** — derived from invoice/receipt links (SRS M02-04) rather than user-set; assumed derived nightly + on event.
6. **Owner "view-all"** — SRS §2 says configurable exceptions for secrets; assumed Owner still needs vault entitlement + re-auth for reveals (SRS §2 note) and cannot see other users' MFA secrets.
7. **Training as project vs session** — assumed every training job is a `project` with `engagement_type=training` and 1..n `training_sessions`.
8. **e-Invoice eligibility** — unknown; adapter stays disabled (D-21).
9. **In-kind CSR valuation** — stored as estimates, never posted; whether to disclose in the CSR annual report is a reporting toggle.
10. **Part-time "payment voucher / payroll handoff"** — assumed payment batches create one Finance payment per staff (PV each) OR one CSV handoff; choice per batch.
