# Requirement traceability — INF-SRS-001 v1.0

Columns: ID · summary · SRS priority · phase · build prompt · status · implementing module · test IDs (Pest `--group`) / evidence.
Status values: `todo`, `partial`, `done`, `deferred (reason)`. Updated in the same commit as the code (CLAUDE.md).

| ID | Summary | Prio | Phase | Prompt | Status | Module | Tests / evidence |
|---|---|---|---|---|---|---|---|
| G-01 | Login, MFA for privileged users, invitation and user deactivation | P0 | P1 | 1a | done | Kernel / M12 Administration | tests/Feature/Identity/{LoginTest,MfaTest,InvitationTest}.php |
| G-02 | Configurable permission matrix by role/action/resource/record | P0 | P1 | 1a | done | Kernel / M12 Administration | tests/Feature/Identity/AuthorizationTest.php, tests/Feature/Audit/AuditTrailTest.php (policy versions) |
| G-03 | Company profile, bank accounts, fiscal P0 year and numbering sequences |  | P1 | 1a | done | Kernel / M12 Administration | tests/Feature/Administration/{MasterDataTest,NumberingTest}.php, tests/Concurrency/NumberingConcurrencyTest.php |
| G-04 | Central searchable documents with version history and access checks | P0 | P1 | 1a | done | Kernel / M12 Administration | tests/Feature/Documents/{SearchSecurityTest,VersioningTest,ReportsTest}.php |
| G-05 | Notifications: assigned approvals, overdue invoices, renewal alerts | P1 | P1 (Prompt 3) | 1a | partial (rules table + seed; dispatch in Prompt 3) | Kernel / M12 Administration | tests/Feature/Administration/MasterDataTest.php |
| G-06 | Company-wide audit trail incl. create/update/export/approval/reveal | P0 | P1 | 1a | done | Kernel / M12 Administration | tests/Feature/Audit/AuditTrailTest.php, tests/Feature/Identity/LoginTest.php |
| G-07 | Attachment validation, allowed MIME, malware scanning where available | P0 | P1 | 1a | done (NullScanner dev; ClamAV adapter) | Kernel / M12 Administration | tests/Feature/Documents/UploadValidationTest.php |
| G-08 | CSV/XLSX export per authorization; PDF P0 official documents |  | P1 | 1a | partial (CSV exports audited for audit log, access reports, document inventory; XLSX/PDF in Prompt 3) | Kernel / M12 Administration | tests/Feature/Audit/AuditTrailTest.php, tests/Feature/Documents/ReportsTest.php |
| G-09 | Configurable dictionaries: categories, P0 statuses, tax codes, project types |  | P1 | 1a | done | Kernel / M12 Administration | tests/Feature/Administration/MasterDataTest.php |
| G-10 | Soft archive / immutable posted financial records | P0 | P1 | 1a | partial (document soft archive + tombstones; immutable posted financials in Prompt 2) | Kernel / M12 Administration | tests/Feature/Documents/LifecycleTest.php |
| G-11 | Global search respecting record-level access | P1 | P1 (Prompt 1b) | 1a | partial (document search + permission-filtered record picker; global search later) | Kernel / M12 Administration | tests/Feature/Documents/SearchSecurityTest.php, tests/Feature/Shell/ShellTest.php |
| G-12 | Timezone Asia/Kuala_Lumpur, MYR display and date consistency | P0 | P1 | 1a | done | Kernel / M12 Administration | tests/Feature/PlatformConfigTest.php (incl. DB session timezone regression) |
| M01-01 | Record project/training revenue, grants and other inflows; separate capital/loans/refunds from sales. | P0 | P1 | 2 | todo | Finance |  |
| M01-02 | Expenses distinguish DIRECT_COMPANY, STAFF_PAID_REIMBURSABLE and OWNER_PAID. | P0 | P1 | 2 | todo | Finance |  |
| M01-03 | Claims allow multiple lines, receipts, category, cost centre, purpose and approval chain. | P0 | P1 | 2 | todo | Finance |  |
| M01-04 | Mileage captures origin/destination, distance, approved rate and project/trip. | P1 | P1 | 2 | partial (claim types + versioned rates) | Finance | tests/Feature/Administration/MasterDataTest.php |
| M01-05 | Payments support partial settlement and match to payable/claim/invoice. | P0 | P1 | 2 | todo | Finance |  |
| M01-06 | Use balanced journal entries and configurable chart of accounts; block invalid posting. | P0 | P1 | 2 | partial (foundation) | Finance | tests/Unit/MoneyTest.php, tests/Architecture/MoneyNeverFloatTest.php |
| M01-07 | Reconcile imported/manual P1 bank transactions and prevent duplicate matching. |  | P1 basic / P4 | 2 | todo | Finance |  |
| M01-08 | Lock closed periods; corrections use reversal/adjustment with traceability. | P1 | P1 | 2 | partial (period lock/unlock + audit; posting guard in Prompt 2) | Finance | tests/Feature/Audit/AuditTrailTest.php |
| M01-09 | Attach tax evidence, export accountant pack; tax treatment flag requires accountant review. | P0 | P1 | 2 | todo | Finance |  |
| M01-10 | Distinguish capital assets, consumables and owner drawings from tax deductible expenses. | P0 | P1 | 2 | todo | Finance |  |
| M02-01 | Create project or training records independently from invoice creation. | P0 | P2 | 4 | todo | Projects |  |
| M02-02 | Link lead, quotation, PO/LOA, contract, project, invoice and receipts. | P0 | P2 | 4 | todo | Projects |  |
| M02-03 | Track deliverables, due P1 dates, completion and blockers. |  | P2 | 4 | todo | Projects |  |
| M02-04 | Separate delivery status from billing/payment status. | P0 | P2 | 4 | todo | Projects |  |
| M02-05 | Link direct costs, trips, claims and third-party expenses to project margin. | P0 | P2 | 4 | todo | Projects |  |
| M02-06 | Track training session date, trainer, venue, participants and feedback. | P1 | P2 | 4 | todo | Projects |  |
| M02-07 | Restrict PM access to assigned projects and explicitly shared documents. | P0 | P2 | 4 | partial (explicit per-document sharing with expiry) | Projects | tests/Feature/Documents/{LifecycleTest,SearchSecurityTest}.php |
| M03-01 | Issue PDF quotations with scope lines, quantities, price, total, validity and conditions. | P0 | P1 basic / P2 | 3 (+4) | todo | Commercial |  |
| M03-02 | Convert accepted quotation to project and/or invoice without re-keying. | P0 | P1 basic / P2 | 3 (+4) | todo | Commercial |  |
| M03-03 | Generate invoices by milestone or items; track part payments and balance. | P0 | P1 basic / P2 | 3 (+4) | todo | Commercial |  |
| M03-04 | Generate receipt only against actual receipt posting; no duplicate number. | P0 | P1 basic / P2 | 3 (+4) | todo | Commercial |  |
| M03-05 | Separate incoming client PO from outgoing vendor PO. | P0 | P1 basic / P2 | 3 (+4) | todo | Commercial |  |
| M03-06 | Generate payment voucher, staff memo, appointment and training certificates. | P1 | P1 basic / P2 | 3 (+4) | todo | Commercial |  |
| M03-07 | Maintain immutable issued snapshots, revision history and controlled void/credit. | P0 | P1 basic / P2 | 3 (+4) | partial (frozen issued-document files at DB level; PDF snapshots in Prompt 3) | Commercial | tests/Feature/Documents/VersioningTest.php |
| M03-08 | Use configurable numbering, logo, bank details, contact and authorized signatory. | P0 | P1 basic / P2 | 3 (+4) | partial (numbering + company profile/signatory; templates in Prompt 3) | Commercial | tests/Feature/Administration/NumberingTest.php, tests/Concurrency/NumberingConcurrencyTest.php |
| M04-01 | Maintain active/inactive staff and staff document folders. | P0 | P2 | 4 | todo | HumanResources |  |
| M04-02 | Staff can submit and see own timesheets and claims only. | P0 | P2 | 4 | todo | HumanResources |  |
| M04-03 | PM verifies timesheet; authorized finance calculates payment from approved rates. | P1 | P2 | 4 | todo | HumanResources |  |
| M04-04 | Generate voucher or payroll handoff from approved payment batch. | P1 | P2 | 4 | todo | HumanResources |  |
| M04-05 | Distinguish employee obligations from contractor invoicing after classification review. | P0 | P2 | 4 | todo | HumanResources |  |
| M04-06 | Mask sensitive identifiers/bank information outside authorized roles. | P0 | P2 | 4 | partial (masking pattern on company bank accounts) | HumanResources | tests/Feature/Administration/MasterDataTest.php |
| M05-01 | Create asset from qualifying expense/purchase without a second expense entry. | P1 | P3 | 5 | todo | Assets |  |
| M05-02 | Support asset vs low-value equipment vs consumable policy. | P1 | P3 | 5 | todo | Assets |  |
| M05-03 | Track assignment, handover, return and repair. | P1 | P3 | 5 | todo | Assets |  |
| M05-04 | Track disposals and audit complete financial impacts through ledger process. | P2 | P3 | 5 | todo | Assets |  |
| M05-05 | Store receipts and warranty documents. | P1 | P3 | 5 | todo | Assets |  |
| M06-01 | Allow files linked to project, finance, staff, asset, CSR and company. | P0 | P1 | 1b | done (user + company linkable; other modules register their types) | DocumentCentre | tests/Feature/Documents/{LifecycleTest,UploadValidationTest}.php |
| M06-02 | Prevent direct public access; download via authorized time-limited links. | P0 | P1 | 1b | done | DocumentCentre | tests/Feature/Documents/DownloadSecurityTest.php |
| M06-03 | Version and audit files; no silent overwrite of issued documents. | P0 | P1 | 1b | done | DocumentCentre | tests/Feature/Documents/{VersioningTest,UploadValidationTest}.php |
| M06-04 | Support metadata search P0 but enforce row permissions. |  | P1 | 1b | done | DocumentCentre | tests/Feature/Documents/{SearchSecurityTest,ReportsTest}.php |
| M06-05 | Configurable retention rules and legal hold; no permanent delete without authority. | P1 | P1 | 1b | done | DocumentCentre | tests/Feature/Documents/{LifecycleTest,DownloadSecurityTest}.php |
| M07-01 | Create trip and group mileage, hotels, transport, meals and expenses. | P1 | P3 | 5 | todo | Activities |  |
| M07-02 | Link activity to client/project without duplicating costs. | P1 | P3 | 5 | todo | Activities |  |
| M07-03 | Track budget vs actual and attach programme photos/report. | P1 | P3 | 5 | todo | Activities |  |
| M08-01 | Capture programme objectives, partner, location and activities. | P1 | P3 | 5 | todo | Csr |  |
| M08-02 | Link finance transactions to CSR activity instead of creating duplicate expenses. | P1 | P3 | 5 | todo | Csr |  |
| M08-03 | Record in-kind items separately from actual cash payments. | P1 | P3 | 5 | todo | Csr |  |
| M08-04 | Attach photos with appropriate consent/privacy handling. | P1 | P3 | 5 | todo | Csr |  |
| M09-01 | Record certification type, owner, evidence and verification. | P1 | P3 | 5 | todo | Certifications |  |
| M09-02 | Configure renewal schedule and reminders at 90/60/30 days. | P1 | P3 | 5 | todo | Certifications |  |
| M09-03 | Support certificates with no expiry and annual memberships. | P1 | P3 | 5 | todo | Certifications |  |
| M09-04 | Link renewal expense to P1 finance, never duplicated. |  | P3 | 5 | todo | Certifications |  |
| M10-01 | Maintain vendor, contract, tax/registration and contact records. | P1 | P3 | 5 | todo | Procurement |  |
| M10-02 | PR to approval to vendor PO to receipt/bill/payment with audit. | P1 | P3 | 5 | todo | Procurement |  |
| M10-03 | Prevent vendor invoice from being paid twice. | P0 | P3 | 5 | todo | Procurement |  |
| M10-04 | Track SaaS subscriptions by vendor, license owner, price, billing cycle and auto-renewal. | P1 | P3 | 5 | todo | Procurement |  |
| M10-05 | Send expiry notifications; distinguish service renewal from payment posting. | P1 | P3 | 5 | todo | Procurement |  |
| M11-01 | Owner dashboard: revenue, costs, cash, outstanding invoices, claims, project contribution. | P0 | P1 basic / P4 | 3 (+6) | todo | Reporting |  |
| M11-02 | Finance dashboard: monthly P&L, categories, unpaid bills, collections, reconciliation. | P0 | P1 basic / P4 | 3 (+6) | todo | Reporting |  |
| M11-03 | Project dashboard: value, status, margin, due milestones and training pipeline. | P1 | P4 | 3 (+6) | todo | Reporting |  |
| M11-04 | Separate invoiced revenue, cash received, expense incurred and cash paid. | P0 | P1 basic / P4 | 3 (+6) | todo | Reporting |  |
| M11-05 | Apply backend security and source row permissions to all reports/exports. | P0 | P1 basic / P4 | 3 (+6) | partial (row permissions applied to document reports/exports) | Reporting | tests/Feature/Documents/ReportsTest.php |
| M11-06 | Power BI integration later via curated read-only reporting views. | P2 | P4 | 3 (+6) | todo | Reporting |  |
| M12-01 | Invite/deactivate users and enforce MFA for finance/owner. | P0 | P1 | 1a | done | Administration | tests/Feature/Identity/{MfaTest,InvitationTest,AuthorizationTest}.php |
| M12-02 | Role/action/resource/record permission configuration, deny-by-default. | P0 | P1 | 1a | done | Administration | tests/Feature/Identity/AuthorizationTest.php |
| M12-03 | Approval matrices and delegation are configurable and versioned. | P0 | P1 | 1a | partial (delegations done; approval matrix table in Prompt 2) | Administration | tests/Feature/Identity/AuthorizationTest.php (APP delegate) |
| M12-04 | Audit sensitive reads, exports, payment approval and privilege changes. | P0 | P1 | 1a | done | Administration | tests/Feature/Audit/AuditTrailTest.php, tests/Feature/Identity/AuthorizationTest.php (reauth) |
| M12-05 | Period lock, numbering and master lookup values under controlled editing. | P0 | P1 | 1a | done | Administration | tests/Feature/Administration/{MasterDataTest,NumberingTest}.php, tests/Feature/Audit/AuditTrailTest.php (period lock) |
| M12-06 | Backup monitoring and authorized restore exercises. | P0 | P1 | 1a | todo | Administration |  |
| M13-01 | Classify cloud, BI, database, development, AI, corporate, client and API credentials. | P1 | P3 | 5 | todo | Credentials |  |
| M13-02 | Store metadata and vault reference only; no plaintext password/API key in standard database, logs or backups. | P0 | P3 | 5 | todo | Credentials |  |
| M13-03 | Vault access must be independently authorized; sensitive reveal requires re-authentication and audit. | P1 | P3 | 5 | todo | Credentials |  |
| M13-04 | Track request/grant/revoke, joiner-mover-leaver process and rotation dates. | P1 | P3 | 5 | todo | Credentials |  |
| M13-05 | Do not expose passwords P0 in reports, exports, notifications or search indexes. |  | P3 | 5 | partial (SecretRedactor on audit + exports) | Credentials | tests/Feature/Audit/AuditTrailTest.php, tests/Unit/RedactorTest.php |
| M13-06 | Support client-specific P1 segregation and non-shared service account policy. |  | P3 | 5 | todo | Credentials |  |
| WF-01 | Staff reimbursement: Draft → Submitted → Review → Approved/Rejected → Finance Payable → Payment Posted → Closed; rejected lines correctable; receipt mandatory by policy | P0 | P1 | 2 | todo | Workflows |  |
| WF-02 | Company direct purchase: PR (optional) → Vendor PO → Bill/Receipt → Payment → Ledger → Asset if qualifying; asset event never a second expense | P0 | P3 | 5 | todo | Workflows |  |
| WF-03 | Owner-paid expenses: Owner payment → assessment → reimbursement OR owner funding classification → ledger; drawings separate from deductible expenses | P0 | P1 | 2 | todo | Workflows |  |
| WF-04 | Sales to cash: Lead → Quotation → Accepted → Project → Invoice → partial/full receipts → close; invoice may stay unpaid after completion | P0 | P1/P2 | 3, 4 | todo | Workflows |  |
| WF-05 | Part-time staffing: onboard → assign → timesheet → verify → calculate → approve → payment voucher; rates locked to agreement version | P0 | P2 | 4 | todo | Workflows |  |
| WF-06 | Subscription renewal: 90/60/30-day alert → review → renew/cancel → supplier invoice/payment; alert never auto-creates an expense | P0 | P3 | 5 | todo | Workflows |  |
| WF-07 | Credential access: request → resource-owner approval → vault entitlement → audit → revoke at offboarding; plaintext secret never returned | P0 | P3 | 5 | todo | Workflows |  |
| WF-08 | Monthly closing: record → bank match → reconcile → trial balance → lock period → adjust with approval; all journals balance | P0 | P1/P4 | 2, 6 | todo | Workflows |  |
| NFR-01 | Security: HTTPS/TLS, MFA for privileged roles, secure sessions, encryption at rest, secrets vault | — | P1 / P4 hardening | 6 | partial (MFA, session hardening in Prompt 6) | NFR | tests/Feature/Identity/MfaTest.php |
| NFR-02 | Authorization: every protected endpoint denies unauthorized roles incl. direct URL and file access | — | P1 / P4 hardening | 6 | partial (deny-by-default, route 403s + 403 page, file access, sidebar filtered server-side; full route matrix in Prompt 6) | NFR | tests/Feature/Identity/AuthorizationTest.php, tests/Feature/Documents/DownloadSecurityTest.php, tests/Feature/Shell/ShellTest.php |
| NFR-03 | Auditability: who/what/when/entity/before-after; redact secret/PII | — | P1 / P4 hardening | 6 | done | NFR | tests/Feature/Audit/AuditTrailTest.php, tests/Unit/RedactorTest.php |
| NFR-04 | Availability ≥99.5% monthly excl. approved maintenance | — | P1 / P4 hardening | 6 | todo | NFR |  |
| NFR-05 | Performance: p95 common list/detail < 3 s under agreed load | — | P1 / P4 hardening | 6 | todo | NFR |  |
| NFR-06 | Backup & restore: daily encrypted backup, RPO ≤24h, RTO ≤8h, quarterly restore test | — | P1 / P4 hardening | 6 | todo | NFR |  |
| NFR-07 | Data privacy: least privilege, minimised medical/identity access, retention and consent controls | — | P1 / P4 hardening | 6 | partial (classification access, medical restriction, masking) | NFR | tests/Feature/Documents/{SearchSecurityTest,LifecycleTest}.php |
| NFR-08 | Compatibility: responsive desktop/tablet, latest Chrome/Edge | — | P1 / P4 hardening | 6 | partial (responsive shell: desktop sidebar, tablet drawer; browser-checked at 1366 px and 820 px) | NFR | tests/Feature/Shell/ShellTest.php, resources/js/Components/Shell/Sidebar.test.ts |
| NFR-09 | Accessibility: keyboard navigation, labels, contrast, readable errors | — | P1 / P4 hardening | 6 | partial (skip link, visible focus rings, keyboard picker/modal/table, labelled controls, readable error pages; full a11y pass in Prompt 6) | NFR | resources/js/Components/*.test.ts, tests/Feature/Shell/ShellTest.php |
| NFR-10 | Observability: error logs, trace IDs, security alerts, backup-job monitoring | — | P1 / P4 hardening | 6 | todo | NFR |  |
| NFR-11 | Maintainability: versioned migrations, automated tests, documented API and release procedure | — | P1 / P4 hardening | 6 | partial (foundation) | NFR | tests/Architecture/*, CI workflow |
| NFR-12 | Localization: MYR, Malaysian addresses, Asia/Kuala_Lumpur, BM/EN labels | — | P1 / P4 hardening | 6 | partial (foundation) | NFR | tests/Feature/PlatformConfigTest.php |
| UAT-01 | Staff opens another staff claim via changed URL → 403, no data/attachment exposure | P0 | P1 | 1a, 2 | done (users, documents; claims in Prompt 2) | UAT | tests/Feature/Identity/AuthorizationTest.php, tests/Feature/Documents/DownloadSecurityTest.php |
| UAT-02 | Mileage claim → approval → payout: single accounting impact, payment linked, audit complete | P0 | P1 | 2 | todo | UAT |  |
| UAT-03 | Laptop purchase with company account → vendor payment + asset registration, no double counting | P0 | P3 | 5 | todo | UAT |  |
| UAT-04 | Invoice RM10,000 paid RM3,000 → outstanding RM7,000, receipt linked | P0 | P1 | 3 | todo | UAT |  |
| UAT-05 | Unbalanced journal rejected; no partial journal | P0 | P1 | 2 | todo | UAT |  |
| UAT-06 | Quotation → project → invoice retains client/scope, unique refs, audited | P0 | P1 | 3 | todo | UAT |  |
| UAT-07 | Time on unassigned project rejected unless role-based exception | P0 | P2 | 4 | todo | UAT |  |
| UAT-08 | Secret via report export → no password/API secret in export or log | P0 | P3/P4 | 5, 6 | partial (exports/audit swept; logs/notifications/search in Prompt 5) | UAT | tests/Feature/Audit/AuditTrailTest.php |
| UAT-09 | Edit posted txn in closed period blocked; authorized adjustment/reversal available | P0 | P1 | 2 | todo | UAT |  |
| UAT-10 | Document access without authorization denied even with copied direct file URL | P0 | P1 | 1b | done | UAT | tests/Feature/Documents/DownloadSecurityTest.php |
| UAT-11 | Approve own payment without delegated authority → rejected or flagged for Owner explicit override | P0 | P1 | 2 | todo | UAT |  |
| UAT-12 | Subscription renewal creates scheduled event; finance transaction only when bill/payment recorded | P0 | P3 | 5 | todo | UAT |  |

## Coverage summary

Total requirement IDs: 119. After Prompt 1c: done 20, partial 22, todo 77 (see rows).

## Prompt → phase map

| Prompt | Phase | Scope |
|---|---|---|
| 0 | — | bootstrap, CLAUDE.md, architecture docs |
| 1a | P1 | M12 + G-01…G-12 (identity, permissions, master data, audit) |
| 1b | P1 | M06 Document Centre |
| 1c | P1 | UI shell & shared components |
| 2 | P1 | M01 Finance core |
| 3 | P1 | M03 basic + M11 basic → tag v0.1.0-p1 |
| 4 | P2 | M02, M04, M03 expanded → v0.2.0-p2 |
| 5 | P3 | M05, M07, M08, M09, M10, M13 → v0.3.0-p3 |
| 6 | P4 | reconciliation, reporting views, MyInvois flag, hardening, handover → v1.0.0-rc1 |
