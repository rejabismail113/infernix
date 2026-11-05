# Permission matrix — role × resource × action × record scope

Derived from SRS §2, §2.1 and each module's access rules. This document is the **source** for the
`permissions` seed (Prompt 1a) and for the generated route security test matrix (Prompt 6): a registered
route with no row here fails the suite.

Actions: `view`, `create`, `edit`, `approve`, `pay`, `export`, `delete`, `reveal`.
Record scopes: `all`, `assigned` (project membership / explicit share / assigned approval item), `own`
(created_by / staff_id = self), `none`. Notation `A` = all, `S` = assigned, `O` = own, `–` = none.
Cells list the actions the role has, with the scope in brackets when not `all`.

Global guards (apply on top of every row): deny-by-default; MFA required for OWN and FIN; AUD only within
`access_from..access_until`; APP only on items delegated to them (entity type + amount ceiling + date window);
maker-checker on approve/pay (creator ≠ approver unless OWN override with reason, audited); re-authentication
for `reveal`, period lock/unlock, permission changes and payments above the configured threshold.

## Resource keys (as seeded in `permissions.resource`)

`users`, `permissions`, `delegations`, `company`, `fiscal_periods`, `numbering`, `dictionaries`,
`notification_rules`, `audit_log`, `backup_status`, `reports_access`, `documents` — seeded by
`database/seeders/PermissionMatrixSeeder.php` (Prompt 1a). Later prompts append their resources
(`documents`, `claims`, `journals`, …). The table below is the human-readable source; the seeder must match it.

## Kernel / M12 Administration

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| users / invitations / deactivation | view create edit delete | view | – | view create edit (STF/PM users only) | view(O) edit(O profile) | view | – |
| roles & permission matrix | view edit | view | – | – | – | view | – |
| approval matrix & delegations | view create edit | view | – | – | – | view | view(S) |
| company profile, bank accounts, signatory | view edit | view edit(bank accounts) | – | view | – | view | – |
| fiscal years / periods, lock & unlock | view edit approve(lock) | view edit approve(lock) | – | – | – | view | – |
| numbering sequences | view edit | view | – | – | – | view | – |
| dictionaries (categories, tax codes, project types, cost centres, claim rates) | view create edit | view create edit | view | view (claim types, HR dictionaries edit) | view | view | – |
| notification rules | view edit | view edit | – | view edit (HR events) | – | – | – |
| audit log | view export | view(finance entities) | – | – | – | view export | – |
| backup status / restore exercise | view approve | – | – | – | – | view | – |
| user access report / policy history | view export | – | – | view | – | view export | – |

## M06 Document Centre

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| documents (inherit from linked record + classification) | view create edit delete(+reason) export | view create edit (finance-linked) | view create edit (S: assigned projects / shared) | view create edit (staff/HR-linked) | view create (O: own claims/timesheets/profile) | view (finance-linked, read only) | view (S: items under approval) |
| classification `restricted` / `medical` | view | medical: amount+receipt only, clinical detail masked | – | view (medical, staff) | view(O) | – | – |
| legal hold / retention rules | edit | – | – | – | – | view | – |
| document search | A | finance-linked | S | HR-linked | O | finance-linked | S |

Seeded `documents` rows (Prompt 1b): OWN view/create/edit/delete/export/approve = all; FIN view/edit/export =
assigned, create = all; PM, HR view/edit = assigned, create = all (HR also export); STF view = assigned,
create/edit = own; AUD view/export = assigned; APP view = assigned. "assigned" for documents = uploader,
active explicit share, or may view a linked record (see DECISIONS A-10). `delete` = hard delete (OWN only, with
reason + re-auth); `approve` = legal hold. Classification gates apply on top (DECISIONS A-09).

## M01 Finance

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| chart of accounts | view edit | view edit | – | – | – | view export | – |
| journals (post via services only) | view create approve | view create approve | – | – | – | view export | – |
| reversals / adjusting journals in locked period | approve | create | – | – | – | view | – |
| income / receipts register | view create edit export | view create edit export | view(S: project) | – | – | view export | – |
| expense register | view create edit export | view create edit export | view(S: project) create(S) | – | – | view export | – |
| tax_treatment flag | edit | edit | – | – | – | edit | – |
| claims | view approve pay export | view(all) edit(amount/receipt) pay export; **clinical note masked** | view approve (S: project team claims per matrix) | view (medical clinical detail) | view create edit(O, draft/rejected) | view export | approve(S, within limit) |
| bills / payables | view create approve pay | view create approve pay | view(S) | – | – | view export | approve(S) |
| payments & payment vouchers | view create approve pay export | view create approve(≠ maker) pay | – | – | – | view export | approve(S) |
| bank accounts / transactions / matching | view create edit | view create edit | – | – | – | view export | – |
| period close checklist | view approve(lock, MFA) | view approve(lock, MFA) | – | – | – | view | – |
| finance reports & tax pack | view export | view export | project reports(S) | – | – | view export | – |
| vendors (minimal master, Prompt 2) | view create edit | view create edit | view | – | – | view | – |

Seeded resource keys: `chart_accounts`, `journals`, `transactions`, `tax_review`, `claims` (+ `reveal` for OWN/HR =
clinical note), `bills`, `payments`, `bank`, `finance_reports`, `vendors`, plus `fiscal_periods` (approve = lock/unlock,
needs TOTP re-auth). AUD stays read-only except `tax_review` (A-33). Staff see only their own claims (record scope O),
enforced in the SQL query and the policy (UAT-01).

## M03 Commercial documents

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| templates (versioned) | view create edit | view | – | view (HR templates) | – | view | – |
| quotations | view create edit approve export | view create edit | view create edit (S: own projects/leads) | – | – | view | approve(S) |
| invoices / credit notes | view create approve export | view create edit approve(≠ maker) | view(S) | – | – | view export | approve(S) |
| receipts | view create | view create | view(S) | – | – | view export | – |
| payment vouchers | view create approve | view create | – | – | – | view export | approve(S) |
| client PO / vendor PO | view create edit approve | view create edit | client PO: view create (S) | – | – | view | approve(S) |
| memos / letters / certificates | view create | – | certificates: create (S: own trainings) | view create (memos, appointment letters) | view(O: addressed to me) | – | – |

## M02 Projects & Training

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| leads / pipeline | view create edit | view | view create edit (S/O) | – | – | – | – |
| projects, milestones, tasks | view create edit | view (financials) | view create edit (S) | – | view (S: assigned, no financials) | view | – |
| project members & rate agreements | view edit | view | edit(S) (rates masked unless OWN/FIN/HR) | view | view(O) | – | – |
| project documents | A | finance-linked | S + explicitly shared | – | S (shared only) | – | – |
| project financials / margin | view | view | view(S) | – | – | view | – |
| training sessions / participants | view create edit | – | view create edit(S) | view | view(S: trainer) | – | – |

## M04 HR & Part-time

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| staff profiles | view create edit | view (no NRIC) | view (team, masked) | view create edit **+ reveal NRIC/passport (audited)** | view(O) edit(O contact) | – | – |
| bank details (restricted record) | reveal (audited) | reveal for payment run (audited) | – | edit reveal | view(O) | – | – |
| engagements / rate versions | view create edit | view | view(S, masked) | view create edit | view(O) | – | – |
| contracts / NDA documents | view | – | – | view create edit | view(O) | – | – |
| timesheets | view approve | view (approved, for payment) | approve(S: assigned projects) | view | view create edit(O) | – | – |
| payment batches / payroll handoff | view approve pay | view create approve(≠ maker) pay export | – | view | view(O: own history) | view export | approve(S) |
| memo inbox | – | – | – | create | view(O) | – | – |

## M05 Assets · M07 Activities · M08 CSR · M09 Certifications · M10 Procurement

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| assets, assignments, maintenance | view create edit | view create edit (from purchase) | view(S: project assets) | view (custodian handover) | view(O: assets assigned to me) | view export | – |
| asset disposal | approve | create | – | – | – | view | approve(S) |
| activities / trips | view create edit | view (costs) | view create edit(S) | – | view(S: attendee) create(O: own trip claims) | view | – |
| CSR programmes, volunteers, in-kind | view create edit | view (spend) | view create edit(S) | view | view(S: volunteer) | view export | – |
| CSR evidence photos (consent) | view | – | view(S) | view | – | – | – |
| certifications / memberships | view create edit | view (renewal expense) | – | view create edit | view create(O: own certificates) | view | – |
| vendors | view create edit | view create edit (bank ref masked unless OWN/FIN) | view | – | – | view | – |
| purchase requests | view approve | view approve | view create(S/O) | create(O) | create(O) | view | approve(S) |
| goods / service receipts | view create | view create | create(S) | – | – | view | – |
| subscriptions & renewal review tasks | view create edit | view create edit | view(S: license owner) | – | view(O: license owner) | view | – |

## M11 Reporting & M13 Credentials

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| owner executive dashboard | view | – | – | – | – | – | – |
| finance dashboard | view export | view export | – | – | – | view export | – |
| project dashboard | view | view | view(S) | – | – | – | – |
| HR/admin dashboard | view | – | – | view | – | – | – |
| export centre | export (all) | export (finance) | export (S project reports) | export (HR) | – | export (finance, time-boxed) | – |
| Power BI `reporting` schema | DB role only; no PII/secret columns | | | | | | |
| credentials_metadata inventory | view create edit | view(S: finance systems) | view(S: assigned client/project scope) | view(S: HR systems) | view(O: owner of item) | view (inventory only, no secrets) | – |
| credential reveal (vault) | reveal (re-auth + vault entitlement + audit) | reveal(S, same guards) | reveal(S, same guards) | reveal(S, same guards) | reveal(O, same guards) | **never** | – |
| access requests / grants / revocation | view approve | view(S) | approve(S: resource owner) | view create (JML) | create(O) | view | – |
| credential access audit | view export | – | – | – | – | view export | – |

## Field-level masking & encryption (NFR-07, M04-06, §2.1)

| Field | Stored as | Who sees plaintext | Everyone else |
|---|---|---|---|
| `staff_profiles.nric` / `passport_no` | encrypted cast (P4) | HR, OWN via `reveal` (audited) | last 4 chars masked `****-**-1234` |
| `staff_bank_details.*` | restricted record, encrypted cast | HR, OWN; FIN during payment run (audited) | masked |
| `claim_lines.clinical_note` (medical) | text, classification `medical` | HR, OWN, claimant | FIN sees amount + receipt only; masked in list/detail/export |
| `vendors.bank_reference` | encrypted cast | OWN, FIN | masked |
| `credentials_metadata.*` | metadata only; **no secret column exists** | n/a | n/a |
| `users.mfa_secret` | encrypted cast | nobody (used only for verification) | never serialised |
| `audit_events.before/after` | JSON with `redactions[]` applied | OWN, AUD | n/a |
