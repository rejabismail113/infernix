# Administrator manual — Infernix One `v1.0.0-rc1`

For the Owner (Julaiha) and anyone given the OWN role. Menu paths refer to the left navigation; you only see items
your role allows, but **hiding is not the control** — the server checks every request (docs/PERMISSION_MATRIX.md).

## 1. First-day setup

1. **Sign in** with the seeded owner account and enrol MFA (any TOTP app: Google Authenticator, Microsoft
   Authenticator, 1Password). OWN and FIN users cannot work without MFA.
2. **Administration → Company**: legal name, registration / SST / TIN numbers, address, logo (used on PDFs).
3. **Finance → Bank** (bank accounts) and **Administration → Fiscal periods** (fiscal year, monthly periods).
4. **Administration → Numbering**: check the prefixes (`QT-`, `INV-`, `RC-`, `PV-`, `CN-`, `PO-`, `JV-`…).
   Numbers are gap-free per year; a cancelled number stays and is marked VOID.
5. **Administration → Dictionaries**: expense categories, project types, tax codes, mileage rate (versioned).
   Everything seeded as a business assumption is marked **PLACEHOLDER** in its notes — review each one with the
   accountant before go-live.
6. **Administration → Users → Invite**: one invitation per person with their role(s). Roles: OWN (owner), FIN
   (finance), PM (project manager), HR, STF (staff / trainer), AUD (auditor, read-only, can have an expiry date), APP
   (approver). Deactivate leavers here; the access checklist (Security → Access checklists) tracks their credentials.
7. **Legacy data**: **Administration → Data import** — download the template, fill it, run *Validate only* first.
   Nothing is written unless every row passes; opening balances post one balanced journal.

## 2. Permissions, approvals and delegation

- **Administration → Permissions**: the role × resource × action × scope matrix. Scopes: `all`, `assigned` (e.g. a
  PM's own projects), `own`. Every change is versioned and audited. After changing it, run the test suite — the
  route matrix test (docs/PERMISSION_MATRIX.md) will show exactly which routes changed for which roles.
- **Maker-checker** is always on: whoever prepares a payment, claim, bill or invoice cannot approve it. The Owner may
  override only by typing a reason, which is recorded in the audit log.
- **Administration → Approval matrix**: who approves what, per document type (claims, bills, payments, invoices,
  quotations, credit notes, POs, journals, payment batches, purchase requests, disposals). Seeded thresholds are
  PLACEHOLDER (≤ RM 5,000 one approval by FIN/OWN, above that a second approval by the Owner). *New version* →
  edit the ranges → effective date → reason → *Publish* (re-authentication). Level 1 must cover every amount from
  0.00 with the last range open-ended. Earlier versions are never edited; they simply end when the new one starts,
  so past approvals stay explainable. Auditors can read the matrix; only the Owner can change it.
- **Administration → Delegations**: let someone approve on your behalf for a period, an entity type and up to an
  amount. Revising a delegation creates a new version; revoking needs a reason.

## 3. Month-end close (WF-08)

1. **Finance → Bank → (account)**: import the bank CSV (profile per bank under *Settings*), run **Auto-match**
   (rules match only when exactly one candidate fits), then match the rest manually — one-to-many and many-to-one
   are allowed; nothing can be matched beyond its amount.
2. **Reconciliation** (same page): the period report must show **Proven** — ledger closing balance adjusted by
   unmatched items equals the bank statement. Investigate anything in the unmatched ageing (31–60, 61–90, 90+ days).
3. **Finance reports → Trial balance**: debits must equal credits (the database refuses unbalanced journals).
4. **Finance → Period close**: work through the checklist, then **Lock** the period with a reason (re-authentication
   required). Posted records in a locked period cannot be edited; corrections are reversals in an open period.
5. **Export Centre → Tax pack** for the accountant (evidence ZIP + journals; scrubbed of secrets).

## 4. Backups and restore (NFR-06, M12-06)

- **Administration → Backups** shows the last backup, its size, the last restore test and the retention policy.
  A backup runs nightly at 01:30 and a restore test runs quarterly; *Run backup now* is available (re-auth needed).
- If the page shows a backup older than 26 hours or a failed restore test, contact the system administrator —
  the restore runbook is in docs/DEPLOYMENT.md §6.
- Keep the backup passphrase in two safe places outside the server. Without it backups cannot be restored.

## 5. Security operations

| Task | Where |
|---|---|
| Who did what | **Administration → Audit log** (filter by user, action, entity; export is itself audited) |
| Who can see what | **Administration → Access reports** (users × roles, privileged access, recent reveals) |
| Leaver | Users → deactivate; Security → Access checklists → work through every credential; rotate shared secrets |
| Credentials | **Security → Credentials** holds metadata and the vault reference only. Revealing a secret needs an entitlement, a reason and re-authentication, and is audited without the value. Rotate when the reminder arrives. |
| Staff identity / bank data | masked everywhere; *Reveal* is HR / Owner only, with a reason, and audited |
| Background jobs | `/horizon` (Owner only): failed jobs can be retried there |

Never paste a password or API key into a note, description or comment: the system stores it as
`[SECRET-REDACTED]`, but the right place is the vault.

## 6. Reports, schedules and Power BI

- **Reports → Export Centre**: CSV / XLSX of any report you are allowed to see; every export is audited and stored
  privately. **Scheduled report emails** (same page): each recipient gets a link to an export made with *their own*
  permissions; anyone who lost access is skipped.
- **Reports → Project dashboard**: value, status, margin, due milestones and training pipeline (M11-03).
- **Power BI**: read-only database views — setup in docs/POWER_BI.md. No personal identity, bank or secret data is
  exposed there.

## 7. e-Invoice (MyInvois)

Off by default. **Sales → e-Invoice** shows the outbox and a banner: *eligibility and field mapping must be verified
by the accountant before use*. Only a stub gateway ships in rc1; switching to LHDN requires the accountant's
confirmation, the supplier TIN and an adapter configuration (decision A-82).

## 8. Language

Every user can switch **EN / BM** in the top bar; the choice is remembered. Navigation, statuses and shared screens
are translated; BM wording is PLACEHOLDER until reviewed, and some module pages are still English (deferred P1).

## 9. Demo / training data

On a **non-production** copy only: `make demo` creates one example per UAT scenario (users
`demo.*@infernix.local`) — useful for training. It refuses to run in production.

## 10. Troubleshooting

| Symptom | Check |
|---|---|
| "403 — You don't have access" | expected when the role / record scope does not allow it; check Administration → Permissions and the record's assignment |
| Asked to re-enter password | sensitive action and your last confirmation is older than 10 minutes |
| "Too many attempts" | rate limit — wait a minute |
| Emails not arriving | Horizon → failed jobs; mail settings in `.env` (admin) |
| Health check red | `/health/ready` shows which dependency failed (database, cache, Redis, storage) |
| Something looks wrong in the books | never edit the database: use reversal journals; ask the accountant; the audit log shows who changed what |

Quote the **request ID** shown on error pages (also in the `X-Request-Id` header) when reporting a problem — it finds
the matching log lines.
