# Infernix One — Architecture

Laravel 12 modular monolith, one deployable, one PostgreSQL database, Redis queues, private S3 storage.
Modules are plain PSR-4 namespaces (no module package) so Larastan, IDEs and Pest groups work unchanged.

## 1. Module map (SRS §4 → namespaces)

| SRS | Module | Namespace | Phase | Owns |
|---|---|---|---|---|
| M01 | Finance & Accounting | `App\Modules\Finance` | P1 | chart_accounts, journals, journal_lines, fiscal posting, transactions (income/expense), claims, bills, payments, payment_allocations, bank_accounts, bank_transactions, bank_matches, period close, finance reports, tax pack |
| M02 | Projects & Training | `App\Modules\Projects` | P2 (stub table in P1) | clients, leads, projects, milestones, tasks, project_members, trainings, training_participants |
| M03 | Quotation, Billing & Document Generation | `App\Modules\Commercial` | P1 basic / P2 expanded | document_templates, commercial_documents (+lines), quotations, invoices, credit_notes, receipts, receipt_allocations, payment vouchers, client/vendor POs, memos, letters, certificates |
| M04 | HR & Part-time Staff | `App\Modules\HumanResources` | P2 | staff_profiles, engagements (rate versions), staff_bank_details (restricted), timesheets, timesheet_entries, payment_batches |
| M05 | Asset Management | `App\Modules\Assets` | P3 | assets, asset_assignments, asset_maintenance, asset_disposals, depreciation_schedules |
| M06 | Document Centre | `App\Modules\DocumentCentre` | P1 | documents, document_versions, entity_document_links, required_evidence_rules, retention rules |
| M07 | Corporate Activities & Travel | `App\Modules\Activities` | P3 | activities, activity_attendees, itinerary_items, activity_budgets |
| M08 | CSR | `App\Modules\Csr` | P3 | csr_programmes, csr_volunteers, csr_in_kind_contributions, csr_evidence |
| M09 | Certification & Membership | `App\Modules\Certifications` | P3 | certifications, memberships, renewal_schedules |
| M10 | Procurement & Vendors | `App\Modules\Procurement` | P3 (vendors/bills basic in P1) | vendors, purchase_requests, vendor POs (via M03), goods_receipts, subscriptions |
| M11 | Reports & Analytics | `App\Modules\Reporting` | P1 basic / P4 | report_metrics, dashboards, export centre, `reporting` schema views (Power BI) |
| M12 | Administration, Master Data & Security | `App\Modules\Administration` | P1 | admin UI over the kernel: users, roles, permissions, company settings, fiscal periods, numbering, dictionaries, approval matrix, notification rules, audit log, backup status |
| M13 | Credential & Secrets | `App\Modules\Credentials` | P3 | credentials_metadata, access_requests, entitlements, rotation schedule, VaultClient adapters |

## 2. Shared kernel — `App\Kernel`

The kernel is the only code every module may depend on. It never depends on a module
(`tests/Architecture/ArchitectureTest.php`).

| Package | Responsibility | Key classes (planned) |
|---|---|---|
| `Kernel\Identity` | users, roles (OWN/FIN/PM/HR/STF/AUD/APP), permission matrix, record scope, MFA, recent-auth, delegation | `User`, `Role`, `Permission`, `PermissionService::can()`, `RequireRecentAuth` middleware, `ApprovalDelegation` |
| `Kernel\Audit` | append-only `audit_events`, `Auditable` trait, redaction config, `Audit::log()` | `AuditEvent`, `Auditable`, `Redactor`, `AuditLogger` |
| `Kernel\Documents` | contracts for attaching/linking files and for signed downloads; M06 implements them | `AttachesDocuments`, `DocumentLinkRegistry` (validated morph map), `DownloadAuthorizer` |
| `Kernel\Numbering` | gap-free `numbering_sequences` allocation with `SELECT … FOR UPDATE` inside the caller's transaction | `NumberingService::allocate(type, fiscalYear)` |
| `Kernel\Money` | brick/money wrappers, `MoneyCast`, NUMERIC(18,4) serialisation | `Money`, `MoneyCast` |
| `Kernel\Approval` | approval matrix (thresholds → approver role), maker-checker, Owner override with reason, delegation resolution | `ApprovalMatrix`, `ApprovalService`, `MakerCheckerViolation` |
| `Kernel\Notifications` | notification rules (event → roles/users → channel → lead days), in-app bell, mail | `NotificationRule`, `NotificationDispatcher` |
| `Kernel\Secrets` | `SecretRedactor` applied to logs, exports, notifications, search payloads; `VaultClient` interface | `SecretRedactor`, `VaultClient`, `NullVault` |
| `Kernel\StateMachine` | enum + transition table helper, `IllegalTransition` exception | `TransitionTable`, `HasStateMachine` |

## 3. Bounded-context dependency rules

```
                 ┌──────────────────────────── App\Kernel ────────────────────────────┐
                 │ Identity · Audit · Documents · Numbering · Money · Approval · Notif │
                 └───────────────▲──────────────▲──────────────▲──────────────▲───────┘
   M12 Administration (UI) ──────┘              │              │              │
   M06 DocumentCentre (implements Documents) ───┘              │              │
   M01 Finance  ◄──── events / IDs ──── M02 M03 M04 M05 M07 M08 M09 M10 ──────┘
   M11 Reporting ──── read-only queries over all modules (policy-filtered) ───────────┘
   M13 Credentials ── Kernel\Secrets + Identity only
```

1. **Finance owns the ledger.** `PostingService` is the only code that inserts into `journals`/`journal_lines`.
   Other modules never write journals; they either call a typed posting recipe exposed by Finance
   (`ClaimApprovalPosting`, `InvoiceIssuePosting`, …) from inside Finance-owned flows, or emit a domain event
   that a Finance listener turns into a posting. Reverse direction: Finance emits events, never calls modules.
2. **Operational modules link, never duplicate.** Assets, Activities, CSR, Certifications, Procurement reference
   finance rows by ID (`purchase_transaction_id`, `expense_line_id`, `bill_id`, `payment_id`) and query them via
   read models. They may not import `App\Modules\Finance\Services` or `...\Models` for writes
   (architecture test).
3. **Commercial (M03) ↔ Finance (M01).** Issuing an invoice / posting a receipt / approving a credit note calls
   Finance posting recipes synchronously inside one DB transaction (number allocation + posting must be atomic);
   payment vouchers are generated by M03 from M01 payments via `PaymentPosted`.
4. **Projects (M02)** owns clients/projects; M03 stores a `client_id`/`project_id` and a counterparty snapshot.
   Project margin is computed by Reporting from links, not stored.
5. **HR (M04)** owns staff + rate versions; timesheet payment batches produce Finance payments through a
   `PaymentBatchApproved` event (maker-checker in Kernel\Approval).
6. **Document Centre (M06)** is the only module that touches object storage. Everything else attaches files via
   `Kernel\Documents` contracts and the validated `entity_document_links` morph map.
7. **Reporting (M11)** is read-only and applies the same policy filters as list pages. Dashboard totals must equal
   the underlying report queries (consistency tests).
8. **Credentials (M13)** never stores secrets; reveal goes vault → client, never through the DB, logs or cache.
9. **Administration (M12)** is a UI/config module over the kernel; it holds no domain logic of its own.
10. Cross-module reads use Eloquent relations only on IDs that are explicitly part of the public contract
    (`docs/DATA_MODEL.md` marks cross-module FKs).

## 4. Domain events (past tense, immutable payloads of IDs + money strings)

| Event | Emitted by | Consumed by |
|---|---|---|
| `UserInvited`, `UserDeactivated`, `PermissionChanged`, `UserLoggedIn`, `RecentAuthConfirmed` | Kernel\Identity | Audit, Notifications, M13 (JML checklist) |
| `FiscalPeriodLocked`, `FiscalPeriodUnlocked` | M12/Kernel | Audit, Finance |
| `DocumentUploaded`, `DocumentVersioned`, `DocumentDownloaded`, `DocumentArchived`, `DocumentHardDeleted` | M06 | Audit, search index |
| `ExpenseRecorded`, `AssetQualifyingPurchaseRecorded` | M01 | M05 (offer "Register asset") |
| `ClaimSubmitted`, `ClaimApproved`, `ClaimRejected`, `ClaimPaid` | M01 | Notifications, M02 (project cost links), Audit |
| `JournalPosted`, `JournalReversed` | M01 PostingService | Audit, Reporting cache invalidation |
| `PaymentPosted`, `PaymentAllocated` | M01 | M03 (payment voucher), M10 (bill status), M04 (batch status) |
| `ReceiptPosted` | M01/M03 | M03 (invoice outstanding), M02 (status_payment) |
| `BillRecorded`, `BillApproved` | M01/M10 | Notifications |
| `BankTransactionsImported`, `BankTransactionMatched` | M01 | Reporting |
| `QuotationIssued`, `QuotationAccepted`, `QuotationConvertedToProject`, `QuotationConvertedToInvoice` | M03 | M02, Audit |
| `InvoiceIssued`, `InvoiceVoided`, `CreditNoteIssued` | M03 | M01 posting, Notifications (overdue scheduler) |
| `CommercialDocumentIssued` (any PDF snapshot) | M03 | M06 (frozen PDF), Audit |
| `ProjectCreated`, `ProjectStatusChanged`, `MilestoneOverdue`, `TrainingSessionCompleted` | M02 | Notifications, M03 (certificates) |
| `StaffStatusChanged` (joiner/mover/leaver) | M04 | M13 (revocation checklist), Identity (deactivate) |
| `TimesheetVerified`, `PaymentBatchApproved` | M04 | M01 (payment creation) |
| `AssetRegistered`, `AssetAssigned`, `AssetReturned`, `AssetDisposed`, `WarrantyExpiring` | M05 | M01 (disposal journal via PostingService), Notifications |
| `ActivityCreated`, `ActivityClosed` | M07 | Reporting |
| `CsrProgrammeClosed` | M08 | Reporting |
| `CertificationExpiring`, `MembershipRenewalDue` | M09 | Notifications |
| `PurchaseRequestApproved`, `VendorPoIssued`, `GoodsReceived`, `SubscriptionRenewalDue` | M10 | M03 (vendor PO doc), Notifications (review task, never a finance txn) |
| `CredentialRevealed`, `AccessRequested`, `AccessGranted`, `AccessRevoked`, `RotationDue` | M13 | Audit, Notifications |
| `ReportExported` | M11/any | Audit |
| `BackupCompleted`, `BackupFailed` | ops | M12 backup status, alerts |

## 5. Request lifecycle & cross-cutting

- **Auth**: session (web) + MFA (TOTP) enforced for OWN/FIN; `RequireRecentAuth` on sensitive actions
  (period lock, reveal, permission changes, payments above threshold).
- **Authorization**: `Gate::before` fails closed; every controller action uses a Policy that calls
  `PermissionService::can($user, $action, $resource)`; list queries use policy scopes (`forUser()`); downloads
  and exports are routes with the same checks and are audited.
- **Transactions**: services wrap state changes in `DB::transaction()`; numbering allocation and journal posting
  happen inside the same transaction; PDF rendering and mail happen after commit (queued).
- **Idempotency**: `Idempotency-Key` header → `idempotency_keys` table (key, user, route, request hash,
  response) for payment/receipt/invoice-issue endpoints.
- **Audit**: the `Auditable` trait records before/after on domain models; the `Redactor` strips
  PII/secret fields per config; exports/reveals/logins are logged explicitly.
- **Secrets**: all log channels, exporters, notification payload builders and the search indexer pass through
  `SecretRedactor`; the dev sentinel test (UAT-08) sweeps them.
- **Jobs**: Horizon queues `default`, `notifications`, `pdf`, `exports`, `imports`; scheduler for overdue
  invoices, renewal reminders (90/60/30), backups, rotation reminders.
- **Observability**: request ID on every log line, JSON logs, Sentry/OTel hooks (P4), `/up` health endpoint.

## 6. Frontend structure

```
resources/js/
  app.ts                  Inertia + PrimeVue (Infernix preset) + Ziggy
  Layouts/                AppLayout (sidebar, breadcrumb, header), GuestLayout
  Components/             DataTable, StatusChip, PickerModal, Stepper, MoneyInput, FileDropzone, … (Prompt 1c)
  Pages/<Module>/         one folder per module (Finance/, Projects/, Documents/, Admin/, …)
  lib/                    money.ts (decimal strings / minor units only), http, formatting
  types/                  shared Inertia prop types
```

Money travels as decimal strings; the server returns `"7000.00"`, never `7000.0`. `MoneyInput` emits decimal
strings or integer minor units (Vitest-tested).
