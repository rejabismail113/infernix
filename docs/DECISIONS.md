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

| D-06 | Permission evaluation | A single `PermissionService::can(user, action, resource|model)`; widest scope across a user's roles wins; AUD may never hold write actions even if a matrix row says so; `Gate::before` denies inactive/out-of-window users and leaves undefined abilities to Laravel's default deny | Prompt 1a | Keeps every policy a one-liner over the matrix; the Prompt 6 route matrix test will verify it per route. |
| D-07 | Assigned scope for M12 objects | `assigned` currently means: delegation → delegate; everything else → not assigned. Project membership / explicit shares populate `user_scopes` from Prompt 1b/4 | Prompt 1a | `UserScope` table exists so no migration churn later. |
| D-08 | Re-authentication | Session-stamped window of 10 minutes (`infernix.security.recent_auth_minutes`); mutations without a recent stamp are redirected to `/reauth` and never replayed automatically | Prompt 1a | SRS asks for a prompt, not a replay. |
| D-09 | Seeded Owner & MFA | The seeded Owner must enrol in TOTP at first login (OWN requires MFA). Dev secret is never pre-seeded. | Prompt 1a | Tests enrol directly via a helper. |

| D-10 | DB session timezone | The PostgreSQL connection runs `SET TIME ZONE 'Asia/Kuala_Lumpur'` (`config/database.php`) | Prompt 1b | Laravel writes datetimes without an offset; with a UTC session every `timestamptz` was stored 8 h in the future and PHP vs SQL comparisons disagreed. Regression test in `tests/Feature/PlatformConfigTest.php`. |
| D-11 | Signed downloads | S3-compatible disks get presigned object URLs (5 min, attachment disposition); any other disk gets a signed, user-bound app route that re-checks the policy and verifies the SHA-256 before streaming. URL signatures are validated before route-model binding. | Prompt 1b | No URL ever contains a fetchable storage path; tampered links are 403, never 404. |
| D-12 | Linkable entity registry | `Kernel\Documents\DocumentLinkRegistry` is the validated morph map; each module registers its record types, allowed link types, an `accessibleIds` resolver and an evidence-subject resolver | Prompt 1b | Keeps document search permission filtering in SQL and module-agnostic. |

## SRS §9.1 open decisions — defaults assumed

| ID | SRS decision | Default assumed for the build | Configurable where | Flag |
|---|---|---|---|---|
| D-10 | Finance policy: accounting basis | **Accrual-capable double-entry ledger** (invoices post AR/revenue at issue; bills post AP/expense at approval) with a **cash-basis toggle** on P&L and tax-pack reports. | `settings: finance.reporting_basis` (accrual\|cash) | pending accountant |
| D-11 | Chart of accounts | Seed a Malaysian sole-proprietor starter COA (Bank, Cash, AR, AP, Staff Claims Payable, Owner Capital, Owner Drawings, Owner Current Account, Project/Training Revenue, Grants, Other Income, expense accounts per seeded category, Fixed Assets, Accumulated Depreciation, Low-value Equipment, Consumables, Suspense). | `chart_accounts` (editable, `notes='PLACEHOLDER'`) | pending accountant |
| D-12 | Owner capital / drawings | Owner-paid business expenses credit **Owner Current Account** (liability to owner); items classed `OWNER_DRAWING` debit **Owner Drawings** (equity) and are excluded from P&L and the expense-by-category report. Capital injections credit Owner Capital. | posting recipes + COA mapping in `settings` | pending accountant |
| D-13 | Depreciation | Straight-line schedule table generated per asset; **posting of depreciation journals is behind `FEATURE_DEPRECIATION_POSTING=false`**. Capitalisation threshold default RM 2,000 (PLACEHOLDER); low-value equipment RM 200–2,000; below = consumable. | `settings: assets.capitalization_threshold`, `assets.low_value_threshold` | pending accountant |
| D-14 | Tax treatment | Every expense defaults to `tax_treatment = pending_review`; only FIN/OWN/AUD may set deductible / non_deductible / capital. Tax pack lists all `pending_review` items as review flags. SST not applied by default (tax codes seeded: `NONE`, `SST-8`, `SST-6` as PLACEHOLDER, inactive). | `dictionary: tax_code` | pending accountant |
| D-15 | Claim limits & rates | Mileage **RM 0.60/km**, per-diem **RM 50/day**, medical cap **RM 500/yr** — all PLACEHOLDER; receipt mandatory for every claim type except mileage; approval thresholds (as seeded in Prompt 2): ≤RM 5,000 one approver (FIN or OWN); above RM 5,000 a second, different approver at level 2 (OWN). Same matrix shape for claims, bills, payments and adjusting journals; delegation lets an APP approve within a ceiling. | `claim_types`, `claim_type_rates`, `approval_matrices` | pending owner |
| D-16 | Roles | Exactly the 7 roles OWN/FIN/PM/HR/STF/AUD/APP; per-action permissions seeded from `docs/PERMISSION_MATRIX.md`; Owner exception = maker-checker override with mandatory reason, audited. | `permissions`, `approval_matrices` | pending owner |
| D-17 | Source of truth / infra | PostgreSQL 16 + an S3-compatible object store in dev — **RustFS** (`rustfs/rustfs:1.0.4`, pinned, Apache-2.0) since MinIO's `minio/minio` and `minio/mc` images were removed from Docker Hub; the private bucket is created by `php artisan storage:ensure-bucket`, so no vendor CLI is required. The app talks plain S3 API only, so any S3-compatible store can replace it; production = managed Postgres + S3-compatible bucket in the `ap-southeast-1` (Singapore) region or a Malaysian provider, single VPS Docker Compose as the baseline deployment (docs/DEPLOYMENT.md, Prompt 6). | `.env` | pending owner |
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

## Prompt 1a assumptions (SRS gaps filled)

| ID | Gap | Assumption |
|---|---|---|
| A-01 | SRS does not say who may invite users | OWN and HR may invite/edit users; only OWN may deactivate (`users.delete`). |
| A-02 | AUD window granularity | Stored per user as `access_from`/`access_until` timestamps, set when the AUD role is granted; outside the window login is refused and live sessions are ended. |
| A-03 | APP delegations | One row per (delegator, delegate, entity_type) with optional MYR ceiling and mandatory date window + reason; edits create a new version (DB trigger blocks in-place edits); revocation is a stamped column. |
| A-04 | Password policy | Min 12 characters with letters and numbers; invitations expire in 7 days; reset links in 60 minutes. |
| A-05 | Fiscal calendar | Calendar years 2026 and 2027 seeded with 12 monthly periods each (D-24). |
| A-06 | Company bank account numbers | Encrypted at rest, masked to last 4 in every payload; full number only used server-side when rendering issued documents (Prompt 3). |
| A-07 | Audit `actor_role` | Comma-joined role codes at the time of the event (a user may hold several roles). |
| A-08 | Tax codes | SST 6%/8% items seeded inactive as placeholders; `NONE` is the only active tax code until the accountant confirms. |

## Prompt 1b assumptions (Document Centre)

| ID | Gap | Assumption |
|---|---|---|
| A-09 | Who sees which classification | `public_internal`, `confidential`: any role with document scope; `restricted`: OWN, FIN, HR, PM, AUD; `medical`: OWN, HR. The uploader and explicit share recipients always see the file (`config/infernix.php` → `documents.classification_access`). |
| A-10 | Meaning of "assigned" for documents | Owner (uploader), an active explicit share, or permission to VIEW a linked record (inheritance, SRS §6.3). Editing additionally requires permission to UPDATE a linked record; shares are view-only. |
| A-11 | Attaching to a record | Requires permission to UPDATE that record (adding evidence changes it), plus `documents.create`. |
| A-12 | Retention | Every upload gets `general_7y` unless another rule is chosen; `none` means no retention period. Hard delete is refused inside the retention period, under legal hold, or for frozen (issued) documents; archive is the normal way to retire a file. |
| A-13 | Hard delete | OWN only, with a reason and recent re-authentication. Stored objects are removed; document and version metadata stay as an audited tombstone (versions are immutable rows). |
| A-14 | Malware scanning | Fail closed: `infected` and `error` are refused; `skipped` (NullScanner) is allowed so dev works without ClamAV. Production should set `DOCUMENT_SCANNER=clamav`. |
| A-15 | File types | pdf, jpg/jpeg, png, xlsx, docx, csv, checked by content; macro-enabled Office files and SVG are refused; default max 25 MB (`DOCUMENT_MAX_SIZE_KB`). |
| A-16 | Staff document folders | Linked to the `user` record until M04 registers `staff_profile` (Prompt 4). |
| A-17 | Freezing | Linking with `issued_document` (used by M03 in Prompt 3) or calling `DocumentStore::freeze()` freezes a document; a DB trigger then refuses new versions. |
| A-18 | Evidence rules for future modules | Seeded now (claim lines, expenses, bills, assets) and shown as "module not installed yet" until those modules register their entity types. |

## Prompt 1c assumptions (UI shell)

| ID | Gap | Assumption |
|---|---|---|
| A-19 | Reference images | `docs/reference/*.jpg` were shared in chat, not as files. The shell follows the layout described in `docs/reference/README.md` (sidebar with icons and orange active item, breadcrumb, title + status chip, Columns / Density / Export grid toolbar, tabbed picker modal, stepper). The logo stays a PLACEHOLDER SVG until the real file is added. |
| A-20 | Component library | Shared grid, picker, modal, stepper and inputs are small in-house Vue components on Tailwind (fully keyboard-tested), rather than PrimeVue widgets, so the reference look and the money rules are under our control. PrimeVue remains installed for later complex widgets (e.g. date range, charts). |
| A-21 | Fonts | No external font CDN: the system font stack is used (Inter when installed locally). Avoids third-party requests from an internal finance system and works offline. |
| A-22 | Table preferences | Column visibility and density are remembered per table in the viewer's browser (`localStorage`) — a convenience only; sorting, filtering and paging are always done by the server for paged lists. |
| A-23 | Error pages | Browser requests get an Inertia error page (403, 404, 429, 500/503 when debug is off) with the original status code; JSON clients keep JSON errors. |

## Prompt 2 assumptions (Finance core)

| ID | Gap | Assumption |
|---|---|---|
| A-24 | Staff-paid expenses | `STAFF_PAID_REIMBURSABLE` cannot be recorded in the expense register; it must go through a claim, so a staff cost hits the ledger exactly once (at claim approval). |
| A-25 | Owner-paid / drawings | Owner-paid business costs credit **Owner Current Account**. `OWNER_DRAWING` items must be paid by the company (`DIRECT_COMPANY`) and debit Owner Drawings; a DB `CHECK` enforces it. Drawings never appear in P&L or expenses-by-category. |
| A-26 | Capital purchases | `capitalization_class = capital_asset` posts Dr Fixed Assets with no expense line and emits `AssetQualifyingPurchaseRecorded` for M05 (Prompt 5) to create the asset by reference. |
| A-27 | Non-sales inflows | Capital injections, loans and refunds post to balance-sheet accounts (Owner Capital, Loans Payable, the original expense account) and are excluded from revenue. |
| A-28 | Adjusting journals | Manual journals are drafts with a temporary `DRAFT-…` number; the gap-free `JV-` number is allocated only when the draft is approved and posted (maker-checker via the `journal` matrix). |
| A-29 | Bank matching | P1 matching is 1:1 between a statement line and a bank journal line (unique on both sides in `bank_matches`); suggestions are same amount within ±3 days. Split matching is P4. |
| A-30 | Cash-basis P&L | Indicative only: direct bank-settled journals plus payables (bills/claims) pro-rated by the amount actually paid in the range. The accountant's tax computation remains authoritative. |
| A-31 | AR ageing | Shown empty until M03 invoices exist (Prompt 3); the report checks for the table rather than failing. |
| A-32 | Period lock strength | Locking/unlocking a period requires re-confirmation with the authenticator (TOTP), not just a password. |
| A-33 | Auditor tax review | AUD is read-only except for setting `tax_treatment` (config `read_only_role_write_exceptions = ['tax_review']`), so the external accountant can classify items without other write access. |
| A-34 | Payment voucher | A basic DomPDF voucher is generated on posting, stored in the Document Centre as the payment's `issued_document` and frozen. Template styling and signatory blocks come with M03 templates (Prompt 3). |
| A-35 | Vendors | A minimal vendor master (name, SSM/tax no., contact, encrypted + masked bank reference) is created now so bills can reference it; the full procurement flow is Prompt 5. Duplicate vendor invoice numbers are blocked by a unique index (M10-03). |

## Prompt 3 assumptions (Commercial documents, dashboards, exports)

| ID | Gap | Assumption |
|---|---|---|
| A-36 | Template editing | `document_template_versions` reference a Blade view **shipped in code** (`commercial.templates.standard-v1`); only settings (title, accent colour, footer, default terms, bank block) are edited in the app. Letting users edit Blade source would allow server-side code execution. New layouts arrive as reviewed code + a new version row; versions are append-only (DB trigger). |
| A-37 | Auditor exports | AUD is read-only, but an export is an audited `exports` record, so `exports` joins `tax_review` in `read_only_role_write_exceptions`. AUD still needs `export` on each report's own resource. |
| A-38 | When numbers are allocated | QT / INV / CN numbers are allocated at **issue**, not at draft creation, inside the issuing transaction (posting + snapshot + number together); drafts are "Draft #id". A revision keeps the quotation number and increments `revision_no`; issuing it supersedes the previous revision. |
| A-39 | Receipts | There is no draft receipt: recording a receipt IS the cash posting (Dr Bank / Cr AR, remainder Cr Unapplied receipts). The row cannot exist without `journal_id` (NOT NULL) and the PDF is refused for anything but a posted receipt. Voiding reverses the journal, releases the allocations, keeps the RC number. |
| A-40 | Voiding invoices | An invoice can be voided only while nothing is settled; otherwise void the receipts or issue a credit note. Void reverses the AR/revenue journal and stores a separate VOID-watermarked copy — the original PDF stays frozen. Credit notes need approval (matrix `credit_note`), may not exceed the outstanding balance, and post the mirror of revenue (and output tax). |
| A-41 | Milestone invoicing | Prompt 3 invoices from accepted quotations, from a project (contract value prefilled) or ad-hoc lines. Billing by milestone needs M02 milestones (Prompt 4); `document_lines.milestone_id` is reserved for it. |
| A-42 | Invoice approval | Invoices and quotations follow the same matrix as payables (PLACEHOLDER D-15: ≤ RM 5,000 one approver, above that a second approver who is the Owner). The preparer may not approve (UAT-11); the Owner may with a recorded reason. Issuing requires `edit` on the document, so the preparer can issue once someone else approved. |
| A-43 | Tax | Tax codes come from the `tax_code` dictionary; SST codes stay inactive until the business registers for SST (D-14). Line tax = round(net × rate, 2, half-up); output tax posts to the new PLACEHOLDER account 2070 SST payable. |
| A-44 | Client bank details on PDFs | The company's own receiving account (bank, account name, full account number) is printed on invoices when the template's bank block is on, and stored in the issuer snapshot. It is not a secret; staff/vendor bank numbers remain encrypted and masked. |
| A-45 | PDF rendering | Browsershot (Chromium) by default; if Chromium is missing the renderer logs a warning and falls back to DomPDF so issuing never fails for want of a browser. Tests use `PDF_DRIVER=dompdf`. PDFs render after the issuing transaction commits; a failure leaves the document issued with a "Generate PDF" action that re-renders the same snapshot. |
| A-46 | Dashboard definitions | Revenue = P&L income (accrual) for the period; cash received/paid = bank inflows/outflows (book); costs = P&L expenses; outstanding = receivables ageing. All are registered in `report_metrics` (PLACEHOLDER definitions). "Top projects" is ledger revenue − cost of project-tagged lines until M02 costing (P2). Charts plot integer sen and format labels back to RM — no float money even in chart data. |
| A-47 | Export storage | Export Centre files are Document Centre documents (classification confidential, retention `short_1y`), linked to the export record and visible only to the requester; cells are secret-scrubbed and guarded against spreadsheet formula injection. XLSX is written with ZipArchive (no extra dependency). |
| A-48 | Notification cadence | Overdue reminders fire once per rule per invoice at the configured lead days (PLACEHOLDER 1 / 14 / 30 days overdue); approval reminders go daily to the roles that may approve the item's current level, never to its preparer. Scheduler: 08:10 and 08:20 Asia/Kuala_Lumpur. |
| A-49 | Clients & projects ownership | The client master and project stub live in M02 (Projects/CRM); M03 reads them only through kernel contracts (`ClientDirectory`, `ProjectDirectory`) and keeps its own snapshot on each document. M02 updates `projects.status_payment` from the `ProjectBillingChanged` event. |
| A-50 | Payment voucher ownership | M01 keeps the payment and its PV number; the voucher PDF is rendered by M03's template engine through the kernel `IssuedDocumentRenderer` contract and registered in `commercial_documents` (type `payment_voucher`) under the same number. |

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
