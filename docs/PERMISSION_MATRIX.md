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
`notification_rules`, `audit_log`, `backup_status`, `reports_access`, `documents`, `approval_matrix`, `data_import` — seeded by
`database/seeders/PermissionMatrixSeeder.php` (Prompt 1a). Later prompts append their resources
(`documents`, `claims`, `journals`, …). The table below is the human-readable source; the seeder must match it.

## Kernel / M12 Administration

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| users / invitations / deactivation | view create edit delete | view | – | view create edit (STF/PM users only) | view(O) edit(O profile) | view | – |
| roles & permission matrix | view edit | view | – | – | – | view | – |
| approval matrix (`approval_matrix`, Prompt 6: versioned editor) | view edit | – | – | – | – | view | – |
| delegations | view create edit | view create(O) edit(O) | view(O) create(O) edit(O) | view(O) create(O) edit(O) | – | view | view(S) |
| company profile, bank accounts, signatory | view edit | view edit(bank accounts) | – | view | – | view | – |
| fiscal years / periods, lock & unlock | view edit approve(lock) | view edit approve(lock) | – | – | – | view | – |
| numbering sequences | view edit | view | – | – | – | view | – |
| dictionaries (categories, tax codes, project types, cost centres, claim rates) | view create edit | view create edit | view | view (claim types, HR dictionaries edit) | view | view | – |
| notification rules | view edit | view edit | – | view edit (HR events) | – | – | – |
| audit log | view export | view(finance entities) | – | – | – | view export | – |
| backup status / restore exercise | view approve | – | – | – | – | view | – |
| data import (`data_import`, Prompt 6) | create | – | – | – | – | – | – |
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

### As seeded in Prompt 3

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| clients | view create edit export | view create edit export | view create edit | – | – | view export | – |
| projects | view create edit export | view create edit export | view(assigned) create edit(assigned) | – | – | view export | – |
| quotations | view create edit approve export | view create edit approve export | view create edit (own) | – | – | view export | approve(assigned) |
| invoices | view create edit approve export | view create edit approve export | – | – | – | view export | approve(assigned) |
| credit_notes | view create edit approve export | view create edit approve export | – | – | – | view export | approve(assigned) |
| receipts | view create edit export | view create edit export | – | – | – | view export | – |
| document_templates | view edit | view | – | – | – | view | – |
| dashboard_owner | view | – | – | – | – | view | – |
| dashboard_finance | view | view | – | – | – | view | – |
| exports (Export Centre) | view(own) create | view(own) create | view(own) create | view(own) create | – | view(own) create (A-37) | – |

Approving a commercial document follows the approval matrix and maker-checker (preparer never approves unless an
Owner records a reason). Issuing needs `edit` on the document; voiding needs `approve`. Every export also needs
`export` on the report's own resource. The PM role currently has nothing it may export.

### As seeded in Prompt 5 (P3)

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| assets | view create edit export | view create edit export | view | view | view(own = in my custody) | view export | – |
| asset_disposals | view create edit approve | view create edit(own) approve | – | – | – | view | view/approve(assigned) |
| activities | view create edit export | view edit export | view(assigned) create edit(own) export(assigned) | view | view(assigned) create edit(own) | view export | – |
| csr_programmes | view create edit export | view create edit export | view | view create edit export | view(assigned = volunteer) | view export | – |
| csr_evidence (restricted photos) | view edit | – | – | view edit | – | – | – |
| certifications / memberships | view create edit export | view export | view (certs) | view create edit export | view(own) create edit(own) | view export | – |
| purchase_requests | view create edit approve export | view create edit approve export | view(own) create edit(own) | view(own) create edit(own) | view(own) create edit(own) | view export | view/approve(assigned) |
| goods_receipts | view create | view create | view create | – | – | view | – |
| subscriptions | view create edit export | view create edit export | view | – | view(own = licence owner) | view export | – |
| credentials (metadata) | view create edit approve reveal export | view(assigned) create edit(own) approve(own) reveal(assigned) | same as FIN | view(assigned) | view(assigned) reveal(assigned) | view export | – |
| credential_requests | create | create | create | create | create | – | – |
| jml_checklists | view edit | – | – | view edit | – | – | – |

Credential "assigned" = resource owner, active entitlement, or a project/client the user can see (M13-06). A reveal additionally needs
recent re-authentication and an active entitlement (or ownership) — matrix rights alone are not enough (M13-03). Approving an access
request = approve:own (the credential owner) or approve:all (Owner role), never the requester.

### As seeded in Prompt 4

| Resource | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|
| leads | view create edit export | view create edit export | view(own) create edit(own) export(own) | – | – | view export | – |
| projects | view create edit export | view create edit export | view(assigned) create edit(assigned) export(assigned) | view | view(assigned) | view export | – |
| staff_profiles | view create edit export | view export | view | view create edit export | view(own) edit(own contact) | view export | – |
| staff_identifiers (reveal) | reveal | – | – | reveal edit | – | – | – |
| staff_bank | view reveal edit | view reveal | – | view reveal edit | view(own) edit(own) | – | – |
| engagements | view create edit | view | – | view create edit | view(own) | view | – |
| timesheets | all | view edit pay export | view(assigned) create(own) approve(assigned) | view export | view/create/edit(own) | view export | – |
| payment_batches | all | view create edit approve pay export | – | view export | – | view export | approve(assigned) |
| memos | view create edit export | view(own) | view(own) | view create edit export | view(own) | view | – |
| letters / appointment_letters | create … | letters only | – | create … | appointment(own view) | view | – |
| certificates | view create edit export | – | view(own) create edit(own) | view | – | view | – |
| client_pos | view create edit export | view create edit export | view(own) create edit(own) | – | – | view export | – |
| vendor_pos | view create edit approve export | view create edit approve export | – | – | – | view export | approve(assigned) |

Identifiers and bank numbers are masked for every role in every list, detail and export; reveals are audited and need
recent re-auth (A-59). The full payroll handoff also needs MFA re-auth (A-58).

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

## Route × role matrix (generated — do not edit by hand)

Generated by `ROUTE_MATRIX_WRITE=1 php artisan test tests/Feature/Security/RouteMatrixTest.php`; without the variable the
same test **fails** if any registered route has no row here, if a row is stale, or if any role's outcome drifts. Each
route is called once per role (OWN = the seeded owner; the others are fresh users holding only that role) with an empty
payload, inside a rolled-back transaction, against fixtures owned by *other* people — so the STF/APP columns are also the
IDOR check for every `{id}` route (a second test fails if STF or APP shows A/V on one, outside a reviewed allow-list in
`tests/Support/RouteMatrix.php`).

**A** = the action ran (2xx/3xx). **V** = authorized but stopped by validation (empty payload). **R** = refused by a
domain / approval-authority / maker-checker rule after authorization. **D** = denied (401/403). **N** = not found (404:
record hidden by scope, signed link refused or bogus token). Any 5xx fails the suite.

Reading notes: `commercial.generated.store` validates `type` before it can pick the policy (V for all; the create policy
then applies). OWN shows D where an action is the subject's alone (submit *my* timesheet, edit *my* claim, mark *my* memo
read) or the record's state forbids it (edit an issued invoice). Signed routes (`documents.stream`, `*.version`) are D
without a valid signature. Framework internals (`horizon.index`, ignition, `storage.local`) are excluded; the Horizon API
rows are listed and owner-only.

<!-- route-matrix:start -->
| Route | Method | URI | OWN | FIN | PM | HR | STF | AUD | APP |
|---|---|---|---|---|---|---|---|---|---|
| `GET up` | GET | `up` | A | A | A | A | A | A | A |
| `POST login` | POST | `login` | A | A | A | A | A | A | A |
| `activities.activities.attendees` | PUT | `activities/{activity}/attendees` | V | V | D | D | D | D | D |
| `activities.activities.cost-candidates` | GET | `activities/{activity}/cost-candidates` | A | A | D | D | D | D | D |
| `activities.activities.costs.link` | POST | `activities/{activity}/costs` | V | V | D | D | D | D | D |
| `activities.activities.costs.unlink` | DELETE | `activities/{activity}/costs` | V | V | D | D | D | D | D |
| `activities.activities.index` | GET | `activities` | A | A | A | A | A | A | D |
| `activities.activities.itinerary` | PUT | `activities/{activity}/itinerary` | V | V | D | D | D | D | D |
| `activities.activities.report` | PUT | `activities/{activity}/report` | V | V | D | D | D | D | D |
| `activities.activities.show` | GET | `activities/{activity}` | A | A | D | A | D | A | D |
| `activities.activities.status` | POST | `activities/{activity}/status` | V | V | D | D | D | D | D |
| `activities.activities.store` | POST | `activities` | V | D | V | D | V | D | D |
| `activities.activities.update` | PUT | `activities/{activity}` | V | V | D | D | D | D | D |
| `admin.approval-matrix.index` | GET | `admin/approval-matrix` | A | D | D | D | D | A | D |
| `admin.approval-matrix.store` | POST | `admin/approval-matrix` | V | D | D | D | D | D | D |
| `admin.audit-log.export` | GET | `admin/audit-log/export` | A | D | D | D | D | A | D |
| `admin.audit-log.index` | GET | `admin/audit-log` | A | A | D | D | D | A | D |
| `admin.backups.index` | GET | `admin/backups` | A | D | D | D | D | A | D |
| `admin.backups.run` | POST | `admin/backups` | V | D | D | D | D | D | D |
| `admin.claim-types.rates.store` | POST | `admin/claim-types/{claimType}/rates` | V | V | D | V | D | D | D |
| `admin.company.bank-accounts.store` | POST | `admin/company/bank-accounts` | V | V | D | D | D | D | D |
| `admin.company.edit` | GET | `admin/company` | A | A | A | A | D | A | D |
| `admin.company.update` | PUT | `admin/company` | V | V | D | D | D | D | D |
| `admin.delegations.index` | GET | `admin/delegations` | A | A | A | A | D | A | A |
| `admin.delegations.revise` | POST | `admin/delegations/{delegation}/revise` | V | D | D | D | D | D | D |
| `admin.delegations.revoke` | POST | `admin/delegations/{delegation}/revoke` | V | D | D | D | D | D | D |
| `admin.delegations.store` | POST | `admin/delegations` | V | V | V | V | D | D | D |
| `admin.dictionaries.index` | GET | `admin/dictionaries` | A | A | A | A | A | A | A |
| `admin.dictionaries.items.store` | POST | `admin/dictionaries/{dictionary}/items` | V | V | D | V | D | D | D |
| `admin.dictionaries.items.update` | PUT | `admin/dictionary-items/{item}` | V | V | D | V | D | D | D |
| `admin.fiscal-periods.index` | GET | `admin/fiscal-periods` | A | A | D | D | D | A | D |
| `admin.fiscal-periods.lock` | POST | `admin/fiscal-periods/{period}/lock` | V | V | D | D | D | D | D |
| `admin.fiscal-periods.unlock` | POST | `admin/fiscal-periods/{period}/unlock` | V | V | D | D | D | D | D |
| `admin.fiscal-years.store` | POST | `admin/fiscal-years` | V | V | D | D | D | D | D |
| `admin.imports.index` | GET | `admin/imports` | A | D | D | D | D | D | D |
| `admin.imports.run` | POST | `admin/imports` | V | D | D | D | D | D | D |
| `admin.imports.template` | GET | `admin/imports/template/{type}` | A | D | D | D | D | D | D |
| `admin.notification-rules.index` | GET | `admin/notification-rules` | A | A | D | A | D | D | D |
| `admin.notification-rules.store` | POST | `admin/notification-rules` | V | V | D | D | D | D | D |
| `admin.notification-rules.update` | PUT | `admin/notification-rules/{rule}` | V | V | D | V | D | D | D |
| `admin.numbering.index` | GET | `admin/numbering` | A | A | D | D | D | A | D |
| `admin.numbering.store` | POST | `admin/numbering` | V | D | D | D | D | D | D |
| `admin.numbering.update` | PUT | `admin/numbering/{sequence}` | V | D | D | D | D | D | D |
| `admin.permissions.index` | GET | `admin/permissions` | A | A | D | D | D | A | D |
| `admin.permissions.update` | PUT | `admin/permissions` | V | D | D | D | D | D | D |
| `admin.reports.policy-history` | GET | `admin/reports/policy-history` | A | D | D | A | D | A | D |
| `admin.reports.policy-history.export` | GET | `admin/reports/policy-history/export` | A | D | D | D | D | A | D |
| `admin.reports.user-access` | GET | `admin/reports/user-access` | A | D | D | A | D | A | D |
| `admin.reports.user-access.export` | GET | `admin/reports/user-access/export` | A | D | D | D | D | A | D |
| `admin.users.deactivate` | POST | `admin/users/{user}/deactivate` | V | D | D | D | D | D | D |
| `admin.users.index` | GET | `admin/users` | A | A | A | A | A | A | A |
| `admin.users.invite` | POST | `admin/users/invite` | V | D | D | V | D | D | D |
| `admin.users.reactivate` | POST | `admin/users/{user}/reactivate` | V | D | D | D | D | D | D |
| `admin.users.roles` | PUT | `admin/users/{user}/roles` | V | D | D | V | D | D | D |
| `admin.users.show` | GET | `admin/users/{user}` | A | A | D | A | D | A | D |
| `assets.assets.assign` | POST | `assets/{asset}/assign` | V | V | D | D | D | D | D |
| `assets.assets.index` | GET | `assets` | A | A | A | A | A | A | D |
| `assets.assets.maintenance` | POST | `assets/{asset}/maintenance` | V | V | D | D | D | D | D |
| `assets.assets.return` | POST | `assets/{asset}/return` | V | V | D | D | D | D | D |
| `assets.assets.show` | GET | `assets/{asset}` | A | A | A | A | D | A | D |
| `assets.assets.store` | POST | `assets` | V | V | D | D | D | D | D |
| `assets.assets.update` | PUT | `assets/{asset}` | V | V | D | D | D | D | D |
| `assets.candidates.dismiss` | POST | `assets/candidates/{candidate}/dismiss` | V | V | D | D | D | D | D |
| `assets.candidates.register` | POST | `assets/candidates/{candidate}/register` | V | V | D | D | D | D | D |
| `assets.disposals.approve` | POST | `assets/disposals/{disposal}/approve` | R | R | D | D | D | D | D |
| `assets.disposals.cancel` | POST | `assets/disposals/{disposal}/cancel` | V | D | D | D | D | D | D |
| `assets.disposals.proceeds` | POST | `assets/disposals/{disposal}/proceeds` | V | D | D | D | D | D | D |
| `assets.disposals.reject` | POST | `assets/disposals/{disposal}/reject` | V | V | D | D | D | D | D |
| `assets.disposals.store` | POST | `assets/{asset}/disposals` | V | V | D | D | D | D | D |
| `assets.disposals.submit` | POST | `assets/disposals/{disposal}/submit` | A | D | D | D | D | D | D |
| `assets.maintenance.complete` | POST | `assets/maintenance/{maintenance}/complete` | V | V | D | D | D | D | D |
| `assets.maintenance.link` | POST | `assets/maintenance/{maintenance}/link` | V | V | D | D | D | D | D |
| `certifications.certifications.index` | GET | `certifications` | A | A | A | A | A | A | D |
| `certifications.certifications.store` | POST | `certifications` | V | D | D | V | V | D | D |
| `certifications.certifications.update` | PUT | `certifications/{certification}` | V | D | D | V | D | D | D |
| `certifications.memberships.store` | POST | `certifications/memberships` | V | D | D | V | V | D | D |
| `certifications.memberships.update` | PUT | `certifications/memberships/{membership}` | V | D | D | V | D | D | D |
| `certifications.renewals.lapse` | POST | `certifications/renewals/{renewal}/lapse` | V | D | D | V | D | D | D |
| `certifications.renewals.link` | POST | `certifications/renewals/{renewal}/link` | V | D | D | D | D | D | D |
| `certifications.renewals.renew` | POST | `certifications/renewals/{renewal}/renew` | V | D | D | V | D | D | D |
| `commercial.client-pos.index` | GET | `commercial/client-pos` | A | A | A | D | D | A | D |
| `commercial.client-pos.store` | POST | `commercial/client-pos` | V | V | V | D | D | D | D |
| `commercial.credit-notes.approve` | POST | `commercial/credit-notes/{document}/approve` | R | R | D | D | D | D | D |
| `commercial.credit-notes.destroy` | DELETE | `commercial/credit-notes/{document}` | A | A | D | D | D | D | D |
| `commercial.credit-notes.edit` | GET | `commercial/credit-notes/{document}/edit` | A | A | D | D | D | D | D |
| `commercial.credit-notes.index` | GET | `commercial/credit-notes` | A | A | D | D | D | A | D |
| `commercial.credit-notes.issue` | POST | `commercial/credit-notes/{document}/issue` | R | R | D | D | D | D | D |
| `commercial.credit-notes.regenerate` | POST | `commercial/credit-notes/{document}/regenerate-pdf` | R | R | D | D | D | D | D |
| `commercial.credit-notes.return` | POST | `commercial/credit-notes/{document}/return` | V | V | D | D | D | D | D |
| `commercial.credit-notes.show` | GET | `commercial/credit-notes/{document}` | A | A | D | D | D | A | D |
| `commercial.credit-notes.submit` | POST | `commercial/credit-notes/{document}/submit` | R | R | D | D | D | D | D |
| `commercial.credit-notes.update` | PUT | `commercial/credit-notes/{document}` | V | V | D | D | D | D | D |
| `commercial.credit-notes.void` | POST | `commercial/credit-notes/{document}/void` | V | V | D | D | D | D | D |
| `commercial.einvoice.index` | GET | `commercial/einvoice` | A | A | D | D | D | A | D |
| `commercial.einvoice.retry` | POST | `commercial/einvoice/{outbox}/retry` | A | A | D | D | D | D | D |
| `commercial.generated.create` | GET | `commercial/generated/create` | V | V | V | V | V | V | V |
| `commercial.generated.destroy` | DELETE | `commercial/generated/{document}` | A | D | D | A | D | D | D |
| `commercial.generated.edit` | GET | `commercial/generated/{document}/edit` | A | D | D | A | D | D | D |
| `commercial.generated.index` | GET | `commercial/generated` | A | A | A | A | A | A | D |
| `commercial.generated.issue` | POST | `commercial/generated/{document}/issue` | A | D | D | A | D | D | D |
| `commercial.generated.regenerate` | POST | `commercial/generated/{document}/regenerate-pdf` | R | D | D | R | D | D | D |
| `commercial.generated.show` | GET | `commercial/generated/{document}` | A | D | D | A | D | A | D |
| `commercial.generated.store` | POST | `commercial/generated` | V | V | V | V | V | V | V |
| `commercial.generated.update` | PUT | `commercial/generated/{document}` | V | D | D | V | D | D | D |
| `commercial.generated.void` | POST | `commercial/generated/{document}/void` | V | D | D | V | D | D | D |
| `commercial.invoices.approve` | POST | `commercial/invoices/{document}/approve` | R | R | D | D | D | D | D |
| `commercial.invoices.create` | GET | `commercial/invoices/create` | A | A | D | D | D | D | D |
| `commercial.invoices.credit-note` | POST | `commercial/invoices/{document}/credit-note` | A | A | D | D | D | D | D |
| `commercial.invoices.destroy` | DELETE | `commercial/invoices/{document}` | R | R | D | D | D | D | D |
| `commercial.invoices.edit` | GET | `commercial/invoices/{document}/edit` | D | D | D | D | D | D | D |
| `commercial.invoices.index` | GET | `commercial/invoices` | A | A | D | D | D | A | D |
| `commercial.invoices.issue` | POST | `commercial/invoices/{document}/issue` | R | R | D | D | D | D | D |
| `commercial.invoices.regenerate` | POST | `commercial/invoices/{document}/regenerate-pdf` | A | A | D | D | D | D | D |
| `commercial.invoices.return` | POST | `commercial/invoices/{document}/return` | V | V | D | D | D | D | D |
| `commercial.invoices.show` | GET | `commercial/invoices/{document}` | A | A | D | D | D | A | D |
| `commercial.invoices.store` | POST | `commercial/invoices` | V | V | D | D | D | D | D |
| `commercial.invoices.submit` | POST | `commercial/invoices/{document}/submit` | R | R | D | D | D | D | D |
| `commercial.invoices.update` | PUT | `commercial/invoices/{document}` | V | V | D | D | D | D | D |
| `commercial.invoices.void` | POST | `commercial/invoices/{document}/void` | V | V | D | D | D | D | D |
| `commercial.quotations.approve` | POST | `commercial/quotations/{document}/approve` | R | R | D | D | D | D | D |
| `commercial.quotations.convert-invoice` | POST | `commercial/quotations/{document}/convert-invoice` | R | R | D | D | D | D | D |
| `commercial.quotations.convert-project` | POST | `commercial/quotations/{document}/convert-project` | R | R | D | D | D | D | D |
| `commercial.quotations.create` | GET | `commercial/quotations/create` | A | A | A | D | D | D | D |
| `commercial.quotations.destroy` | DELETE | `commercial/quotations/{document}` | A | A | D | D | D | D | D |
| `commercial.quotations.edit` | GET | `commercial/quotations/{document}/edit` | A | A | D | D | D | D | D |
| `commercial.quotations.index` | GET | `commercial/quotations` | A | A | A | D | D | A | D |
| `commercial.quotations.issue` | POST | `commercial/quotations/{document}/issue` | R | R | D | D | D | D | D |
| `commercial.quotations.regenerate` | POST | `commercial/quotations/{document}/regenerate-pdf` | R | R | D | D | D | D | D |
| `commercial.quotations.respond` | POST | `commercial/quotations/{document}/respond` | V | V | D | D | D | D | D |
| `commercial.quotations.return` | POST | `commercial/quotations/{document}/return` | V | V | D | D | D | D | D |
| `commercial.quotations.revise` | POST | `commercial/quotations/{document}/revise` | R | R | D | D | D | D | D |
| `commercial.quotations.show` | GET | `commercial/quotations/{document}` | A | A | D | D | D | A | D |
| `commercial.quotations.store` | POST | `commercial/quotations` | V | V | V | D | D | D | D |
| `commercial.quotations.submit` | POST | `commercial/quotations/{document}/submit` | A | A | D | D | D | D | D |
| `commercial.quotations.update` | PUT | `commercial/quotations/{document}` | V | V | D | D | D | D | D |
| `commercial.quotations.void` | POST | `commercial/quotations/{document}/void` | V | V | D | D | D | D | D |
| `commercial.receipts.create` | GET | `commercial/receipts/create` | A | A | D | D | D | D | D |
| `commercial.receipts.index` | GET | `commercial/receipts` | A | A | D | D | D | A | D |
| `commercial.receipts.regenerate` | POST | `commercial/receipts/{receipt}/regenerate-pdf` | A | A | D | D | D | D | D |
| `commercial.receipts.show` | GET | `commercial/receipts/{receipt}` | A | A | D | D | D | A | D |
| `commercial.receipts.store` | POST | `commercial/receipts` | V | V | D | D | D | D | D |
| `commercial.receipts.void` | POST | `commercial/receipts/{receipt}/void` | V | V | D | D | D | D | D |
| `commercial.reports.show` | GET | `commercial/reports/{report}` | A | A | D | D | D | A | D |
| `commercial.templates.index` | GET | `commercial/templates` | A | A | D | D | D | A | D |
| `commercial.templates.preview` | GET | `commercial/template-versions/{version}/preview` | A | A | D | D | D | A | D |
| `commercial.templates.versions.store` | POST | `commercial/templates/{template}/versions` | V | D | D | D | D | D | D |
| `commercial.vendor-pos.approve` | POST | `commercial/vendor-pos/{document}/approve` | R | R | D | D | D | D | D |
| `commercial.vendor-pos.create` | GET | `commercial/vendor-pos/create` | A | A | D | D | D | D | D |
| `commercial.vendor-pos.destroy` | DELETE | `commercial/vendor-pos/{document}` | A | A | D | D | D | D | D |
| `commercial.vendor-pos.edit` | GET | `commercial/vendor-pos/{document}/edit` | A | A | D | D | D | D | D |
| `commercial.vendor-pos.index` | GET | `commercial/vendor-pos` | A | A | D | D | D | A | D |
| `commercial.vendor-pos.issue` | POST | `commercial/vendor-pos/{document}/issue` | R | R | D | D | D | D | D |
| `commercial.vendor-pos.regenerate` | POST | `commercial/vendor-pos/{document}/regenerate-pdf` | R | R | D | D | D | D | D |
| `commercial.vendor-pos.return` | POST | `commercial/vendor-pos/{document}/return` | V | V | D | D | D | D | D |
| `commercial.vendor-pos.show` | GET | `commercial/vendor-pos/{document}` | A | A | D | D | D | A | D |
| `commercial.vendor-pos.store` | POST | `commercial/vendor-pos` | V | V | D | D | D | D | D |
| `commercial.vendor-pos.submit` | POST | `commercial/vendor-pos/{document}/submit` | A | A | D | D | D | D | D |
| `commercial.vendor-pos.update` | PUT | `commercial/vendor-pos/{document}` | V | V | D | D | D | D | D |
| `commercial.vendor-pos.void` | POST | `commercial/vendor-pos/{document}/void` | V | V | D | D | D | D | D |
| `credentials.audit` | GET | `credentials/audit` | A | A | A | D | D | A | D |
| `credentials.credentials.grant` | POST | `credentials/{credential}/grant` | V | D | D | D | D | D | D |
| `credentials.credentials.index` | GET | `credentials` | A | A | A | A | A | A | D |
| `credentials.credentials.request` | POST | `credentials/{credential}/request` | V | D | D | D | D | D | D |
| `credentials.credentials.reveal` | POST | `credentials/{credential}/reveal` | V | D | D | D | D | D | D |
| `credentials.credentials.rotate` | POST | `credentials/{credential}/rotate` | V | D | D | D | D | D | D |
| `credentials.credentials.show` | GET | `credentials/{credential}` | A | D | D | D | D | A | D |
| `credentials.credentials.status` | POST | `credentials/{credential}/status` | V | D | D | D | D | D | D |
| `credentials.credentials.store` | POST | `credentials` | V | V | V | D | D | D | D |
| `credentials.credentials.update` | PUT | `credentials/{credential}` | V | D | D | D | D | D | D |
| `credentials.entitlements.revoke` | POST | `credentials/entitlements/{entitlement}/revoke` | V | D | D | D | D | D | D |
| `credentials.jml.index` | GET | `credentials/jml` | A | D | D | A | D | D | D |
| `credentials.jml.tick` | POST | `credentials/jml/{checklist}/tick` | V | D | D | V | D | D | D |
| `credentials.requests.decide` | POST | `credentials/requests/{accessRequest}/decide` | V | D | D | D | D | D | D |
| `csr.annual-report` | GET | `csr/annual-report` | V | V | D | V | D | V | D |
| `csr.evidence.consent` | POST | `csr/evidence/{evidence}/consent` | V | D | D | V | D | D | D |
| `csr.evidence.publishable` | POST | `csr/evidence/{evidence}/publishable` | V | D | D | V | D | D | D |
| `csr.in-kind.destroy` | DELETE | `csr/in-kind/{contribution}` | A | A | D | A | D | D | D |
| `csr.programmes.evidence` | POST | `csr/{programme}/evidence` | V | D | D | V | D | D | D |
| `csr.programmes.in-kind` | POST | `csr/{programme}/in-kind` | V | V | D | V | D | D | D |
| `csr.programmes.index` | GET | `csr` | A | A | A | A | A | A | D |
| `csr.programmes.show` | GET | `csr/{programme}` | A | A | A | A | D | A | D |
| `csr.programmes.store` | POST | `csr` | V | V | D | V | D | D | D |
| `csr.programmes.update` | PUT | `csr/{programme}` | V | V | D | V | D | D | D |
| `csr.programmes.volunteers` | PUT | `csr/{programme}/volunteers` | V | V | D | V | D | D | D |
| `dashboard` | GET | `/` | A | A | A | A | A | A | A |
| `documents.archive` | POST | `documents/{document}/archive` | A | D | D | D | D | D | D |
| `documents.destroy` | DELETE | `documents/{document}` | V | D | D | D | D | D | D |
| `documents.download` | GET | `documents/{document}/download` | A | D | D | D | D | D | D |
| `documents.index` | GET | `documents` | A | A | A | A | A | A | A |
| `documents.legal-hold` | POST | `documents/{document}/legal-hold` | V | D | D | D | D | D | D |
| `documents.link-targets` | GET | `documents/link-targets` | V | V | V | V | V | D | D |
| `documents.links.store` | POST | `documents/{document}/links` | V | D | D | D | D | D | D |
| `documents.reports.inventory` | GET | `documents/reports/inventory` | A | A | A | A | A | A | A |
| `documents.reports.inventory.export` | GET | `documents/reports/inventory/export` | A | A | D | A | D | A | D |
| `documents.reports.missing-evidence` | GET | `documents/reports/missing-evidence` | A | A | A | A | A | A | A |
| `documents.reports.recent-changes` | GET | `documents/reports/recent-changes` | A | A | A | A | A | A | A |
| `documents.shares.destroy` | DELETE | `documents/{document}/shares/{share}` | A | D | D | D | D | D | D |
| `documents.shares.store` | POST | `documents/{document}/shares` | V | D | D | D | D | D | D |
| `documents.show` | GET | `documents/{document}` | A | D | D | D | D | D | D |
| `documents.store` | POST | `documents` | V | V | V | V | V | D | D |
| `documents.stream` | GET | `documents/stream/{version}` | D | D | D | D | D | D | D |
| `documents.versions.store` | POST | `documents/{document}/versions` | V | D | D | D | D | D | D |
| `finance.accounts.index` | GET | `finance/accounts` | A | A | D | D | D | A | D |
| `finance.accounts.store` | POST | `finance/accounts` | V | V | D | D | D | D | D |
| `finance.accounts.update` | PUT | `finance/accounts/{account}` | V | V | D | D | D | D | D |
| `finance.bank.auto-match` | POST | `finance/bank/{bank}/auto-match` | A | A | D | D | D | D | D |
| `finance.bank.import` | POST | `finance/bank/{bank}/import` | V | V | D | D | D | D | D |
| `finance.bank.index` | GET | `finance/bank` | A | A | D | D | D | A | D |
| `finance.bank.ledger-lines` | GET | `finance/bank/{bank}/ledger-lines` | A | A | D | D | D | A | D |
| `finance.bank.match` | POST | `finance/bank-transactions/{transaction}/match` | V | V | D | D | D | D | D |
| `finance.bank.match-group` | POST | `finance/bank/{bank}/match-group` | V | V | D | D | D | D | D |
| `finance.bank.profiles.store` | POST | `finance/bank/profiles` | V | V | D | D | D | D | D |
| `finance.bank.profiles.update` | PUT | `finance/bank/profiles/{profile}` | V | V | D | D | D | D | D |
| `finance.bank.reconciliation` | GET | `finance/bank/{bank}/reconciliation` | A | A | D | D | D | A | D |
| `finance.bank.rules.store` | POST | `finance/bank/rules` | V | V | D | D | D | D | D |
| `finance.bank.rules.update` | PUT | `finance/bank/rules/{rule}` | V | V | D | D | D | D | D |
| `finance.bank.settings` | GET | `finance/bank/settings` | A | A | D | D | D | A | D |
| `finance.bank.show` | GET | `finance/bank/{bank}` | A | A | D | D | D | A | D |
| `finance.bank.transactions.store` | POST | `finance/bank/{bank}/transactions` | V | V | D | D | D | D | D |
| `finance.bank.unmatch` | POST | `finance/bank-transactions/{transaction}/unmatch` | V | V | D | D | D | D | D |
| `finance.bills.approve` | POST | `finance/bills/{bill}/approve` | R | R | D | D | D | D | D |
| `finance.bills.cancel` | POST | `finance/bills/{bill}/cancel` | V | V | D | D | D | D | D |
| `finance.bills.create` | GET | `finance/bills/create` | A | A | D | D | D | D | D |
| `finance.bills.index` | GET | `finance/bills` | A | A | D | D | D | A | D |
| `finance.bills.return` | POST | `finance/bills/{bill}/return` | V | V | D | D | D | D | D |
| `finance.bills.show` | GET | `finance/bills/{bill}` | A | A | D | D | D | A | D |
| `finance.bills.store` | POST | `finance/bills` | V | V | D | D | D | D | D |
| `finance.bills.submit` | POST | `finance/bills/{bill}/submit` | R | R | D | D | D | D | D |
| `finance.bills.tax-treatment` | PUT | `finance/bills/{bill}/tax-treatment` | V | V | D | D | D | V | D |
| `finance.claims.approve` | POST | `finance/claims/{claim}/approve` | R | R | D | R | D | R | D |
| `finance.claims.cancel` | POST | `finance/claims/{claim}/cancel` | D | D | D | D | D | D | D |
| `finance.claims.close` | POST | `finance/claims/{claim}/close` | R | R | D | D | D | D | D |
| `finance.claims.create` | GET | `finance/claims/create` | A | A | A | D | A | D | D |
| `finance.claims.edit` | GET | `finance/claims/{claim}/edit` | D | D | D | D | D | D | D |
| `finance.claims.index` | GET | `finance/claims` | A | A | A | A | A | A | A |
| `finance.claims.reject` | POST | `finance/claims/{claim}/reject` | V | V | D | V | D | V | D |
| `finance.claims.show` | GET | `finance/claims/{claim}` | A | A | D | A | D | A | D |
| `finance.claims.store` | POST | `finance/claims` | V | V | V | D | V | D | D |
| `finance.claims.submit` | POST | `finance/claims/{claim}/submit` | R | R | D | D | D | D | D |
| `finance.claims.update` | PUT | `finance/claims/{claim}` | V | V | D | D | D | D | D |
| `finance.journals.approve` | POST | `finance/journals/{journal}/approve` | R | R | D | D | D | D | D |
| `finance.journals.create` | GET | `finance/journals/create` | A | A | D | D | D | D | D |
| `finance.journals.destroy` | DELETE | `finance/journals/{journal}` | R | D | D | D | D | D | D |
| `finance.journals.index` | GET | `finance/journals` | A | A | D | D | D | A | D |
| `finance.journals.reverse` | POST | `finance/journals/{journal}/reverse` | V | V | D | D | D | D | D |
| `finance.journals.show` | GET | `finance/journals/{journal}` | A | A | D | D | D | A | D |
| `finance.journals.store` | POST | `finance/journals` | V | V | D | D | D | D | D |
| `finance.overview` | GET | `finance` | A | A | A | A | A | A | A |
| `finance.payments.approve` | POST | `finance/payments/{payment}/approve` | R | R | D | D | D | D | D |
| `finance.payments.cancel` | POST | `finance/payments/{payment}/cancel` | V | V | D | D | D | D | D |
| `finance.payments.create` | GET | `finance/payments/create` | A | A | D | D | D | D | D |
| `finance.payments.index` | GET | `finance/payments` | A | A | D | D | D | A | D |
| `finance.payments.post` | POST | `finance/payments/{payment}/post` | R | R | D | D | D | D | D |
| `finance.payments.show` | GET | `finance/payments/{payment}` | A | A | D | D | D | A | D |
| `finance.payments.store` | POST | `finance/payments` | V | V | D | D | D | D | D |
| `finance.payments.submit` | POST | `finance/payments/{payment}/submit` | R | R | D | D | D | D | D |
| `finance.periods.index` | GET | `finance/periods` | A | A | D | D | D | A | D |
| `finance.periods.show` | GET | `finance/periods/{period}` | A | A | D | D | D | A | D |
| `finance.records.search` | GET | `finance/records/search` | V | V | V | V | V | V | V |
| `finance.reports.export` | GET | `finance/reports/{report}/export` | A | A | D | D | D | A | D |
| `finance.reports.index` | GET | `finance/reports` | A | A | D | D | D | A | D |
| `finance.reports.show` | GET | `finance/reports/{report}` | A | A | D | D | D | A | D |
| `finance.reports.tax-pack` | GET | `finance/reports/tax-pack` | A | A | D | D | D | A | D |
| `finance.transactions.cancel` | POST | `finance/transactions/{transaction}/cancel` | V | V | D | D | D | D | D |
| `finance.transactions.create` | GET | `finance/transactions/create` | A | A | D | D | D | D | D |
| `finance.transactions.index` | GET | `finance/transactions` | A | A | D | D | D | A | D |
| `finance.transactions.post` | POST | `finance/transactions/{transaction}/post` | R | R | D | D | D | D | D |
| `finance.transactions.reverse` | POST | `finance/transactions/{transaction}/reverse` | V | V | D | D | D | D | D |
| `finance.transactions.show` | GET | `finance/transactions/{transaction}` | A | A | D | D | D | A | D |
| `finance.transactions.store` | POST | `finance/transactions` | V | V | D | D | D | D | D |
| `finance.transactions.tax-treatment` | PUT | `finance/transactions/{transaction}/tax-treatment` | V | V | D | D | D | V | D |
| `health.live` | GET | `health/live` | A | A | A | A | A | A | A |
| `health.ready` | GET | `health/ready` | A | A | A | A | A | A | A |
| `horizon.completed-jobs.index` | GET | `horizon/api/jobs/completed` | A | D | D | D | D | D | D |
| `horizon.failed-jobs.index` | GET | `horizon/api/jobs/failed` | A | D | D | D | D | D | D |
| `horizon.failed-jobs.show` | GET | `horizon/api/jobs/failed/{id}` | A | D | D | D | D | D | D |
| `horizon.jobs-batches.index` | GET | `horizon/api/batches` | A | D | D | D | D | D | D |
| `horizon.jobs-batches.retry` | POST | `horizon/api/batches/retry/{id}` | A | D | D | D | D | D | D |
| `horizon.jobs-batches.show` | GET | `horizon/api/batches/{id}` | A | D | D | D | D | D | D |
| `horizon.jobs-metrics.index` | GET | `horizon/api/metrics/jobs` | A | D | D | D | D | D | D |
| `horizon.jobs-metrics.show` | GET | `horizon/api/metrics/jobs/{id}` | A | D | D | D | D | D | D |
| `horizon.jobs.show` | GET | `horizon/api/jobs/{id}` | A | D | D | D | D | D | D |
| `horizon.masters.index` | GET | `horizon/api/masters` | A | D | D | D | D | D | D |
| `horizon.monitoring-tag.destroy` | DELETE | `horizon/api/monitoring/{tag}` | A | D | D | D | D | D | D |
| `horizon.monitoring-tag.paginate` | GET | `horizon/api/monitoring/{tag}` | A | D | D | D | D | D | D |
| `horizon.monitoring.index` | GET | `horizon/api/monitoring` | A | D | D | D | D | D | D |
| `horizon.monitoring.store` | POST | `horizon/api/monitoring` | A | D | D | D | D | D | D |
| `horizon.pending-jobs.index` | GET | `horizon/api/jobs/pending` | A | D | D | D | D | D | D |
| `horizon.queues-metrics.index` | GET | `horizon/api/metrics/queues` | A | D | D | D | D | D | D |
| `horizon.queues-metrics.show` | GET | `horizon/api/metrics/queues/{id}` | A | D | D | D | D | D | D |
| `horizon.retry-jobs.show` | POST | `horizon/api/jobs/retry/{id}` | A | D | D | D | D | D | D |
| `horizon.silenced-jobs.index` | GET | `horizon/api/jobs/silenced` | A | D | D | D | D | D | D |
| `horizon.stats.index` | GET | `horizon/api/stats` | A | D | D | D | D | D | D |
| `horizon.workload.index` | GET | `horizon/api/workload` | A | D | D | D | D | D | D |
| `hr.batches.approve` | POST | `hr/batches/{batch}/approve` | R | R | D | D | D | D | D |
| `hr.batches.cancel` | POST | `hr/batches/{batch}/cancel` | V | V | D | D | D | D | D |
| `hr.batches.handoff` | GET | `hr/batches/{batch}/handoff` | D | D | D | D | D | D | D |
| `hr.batches.index` | GET | `hr/batches` | A | A | D | A | D | A | D |
| `hr.batches.pay` | POST | `hr/batches/{batch}/pay` | V | V | D | D | D | D | D |
| `hr.batches.show` | GET | `hr/batches/{batch}` | A | A | D | A | D | A | D |
| `hr.batches.store` | POST | `hr/batches` | V | V | D | D | D | D | D |
| `hr.batches.submit` | POST | `hr/batches/{batch}/submit` | A | A | D | D | D | D | D |
| `hr.memos.index` | GET | `hr/memos` | A | A | A | A | A | A | A |
| `hr.memos.read` | POST | `hr/memos/{memo}/read` | D | D | D | D | D | D | D |
| `hr.staff.bank` | PUT | `hr/staff/{staff}/bank` | V | D | D | V | D | D | D |
| `hr.staff.engagements.store` | POST | `hr/staff/{staff}/engagements` | V | D | D | V | D | D | D |
| `hr.staff.index` | GET | `hr/staff` | A | A | A | A | A | A | D |
| `hr.staff.reveal-bank` | POST | `hr/staff/{staff}/reveal-bank` | V | V | D | V | D | D | D |
| `hr.staff.reveal-id` | POST | `hr/staff/{staff}/reveal-id` | V | D | D | V | D | D | D |
| `hr.staff.show` | GET | `hr/staff/{staff}` | A | A | A | A | D | A | D |
| `hr.staff.store` | POST | `hr/staff` | V | D | D | V | D | D | D |
| `hr.staff.update` | PUT | `hr/staff/{staff}` | V | D | D | V | D | D | D |
| `hr.timesheets.calculate` | POST | `hr/timesheets/{timesheet}/calculate` | R | R | D | D | D | D | D |
| `hr.timesheets.entries` | PUT | `hr/timesheets/{timesheet}/entries` | D | D | D | D | D | D | D |
| `hr.timesheets.index` | GET | `hr/timesheets` | A | A | A | A | A | A | D |
| `hr.timesheets.show` | GET | `hr/timesheets/{timesheet}` | A | A | D | A | D | A | D |
| `hr.timesheets.store` | POST | `hr/timesheets` | V | D | V | D | V | D | D |
| `hr.timesheets.submit` | POST | `hr/timesheets/{timesheet}/submit` | D | D | D | D | D | D | D |
| `hr.timesheets.verify` | POST | `hr/timesheets/{timesheet}/verify` | V | D | D | D | D | D | D |
| `invitations.accept` | GET | `invitations/{token}` | A | A | A | A | A | A | A |
| `invitations.store` | POST | `invitations/{token}` | A | A | A | A | A | A | A |
| `locale.update` | POST | `locale` | V | V | V | V | V | V | V |
| `login` | GET | `login` | A | A | A | A | A | A | A |
| `logout` | POST | `logout` | A | A | A | A | A | A | A |
| `mfa.challenge` | GET | `mfa/challenge` | A | A | A | A | A | A | A |
| `mfa.confirm` | POST | `mfa/setup` | V | V | V | V | V | V | V |
| `mfa.setup` | GET | `mfa/setup` | A | A | A | A | A | A | A |
| `mfa.verify` | POST | `mfa/challenge` | V | V | V | V | V | V | V |
| `notifications.index` | GET | `notifications` | A | A | A | A | A | A | A |
| `notifications.open` | GET | `notifications/{notification}/open` | A | N | N | N | N | N | N |
| `notifications.read-all` | POST | `notifications/read-all` | A | A | A | A | A | A | A |
| `password.email` | POST | `forgot-password` | A | A | A | A | A | A | A |
| `password.request` | GET | `forgot-password` | A | A | A | A | A | A | A |
| `password.reset` | GET | `reset-password/{token}` | A | A | A | A | A | A | A |
| `password.update` | POST | `reset-password` | A | A | A | A | A | A | A |
| `procurement.receipts.store` | POST | `procurement/receipts` | V | V | V | D | D | D | D |
| `procurement.requests.approve` | POST | `procurement/requests/{purchaseRequest}/approve` | R | R | D | D | D | D | D |
| `procurement.requests.cancel` | POST | `procurement/requests/{purchaseRequest}/cancel` | V | V | D | D | D | D | D |
| `procurement.requests.index` | GET | `procurement/requests` | A | A | A | A | A | A | A |
| `procurement.requests.order` | POST | `procurement/requests/{purchaseRequest}/order` | V | V | D | D | D | D | D |
| `procurement.requests.reject` | POST | `procurement/requests/{purchaseRequest}/reject` | V | V | D | D | D | D | D |
| `procurement.requests.show` | GET | `procurement/requests/{purchaseRequest}` | A | A | D | D | D | A | D |
| `procurement.requests.store` | POST | `procurement/requests` | V | V | V | V | V | D | D |
| `procurement.requests.submit` | POST | `procurement/requests/{purchaseRequest}/submit` | A | A | D | D | D | D | D |
| `procurement.requests.update` | PUT | `procurement/requests/{purchaseRequest}` | V | V | D | D | D | D | D |
| `procurement.subscription-reviews.decide` | POST | `procurement/subscription-reviews/{review}/decide` | V | V | D | D | D | D | D |
| `procurement.subscriptions.cancel` | POST | `procurement/subscriptions/{subscription}/cancel` | V | V | D | D | D | D | D |
| `procurement.subscriptions.index` | GET | `procurement/subscriptions` | A | A | A | D | A | A | D |
| `procurement.subscriptions.link` | POST | `procurement/subscriptions/{subscription}/link` | V | V | D | D | D | D | D |
| `procurement.subscriptions.store` | POST | `procurement/subscriptions` | V | V | D | D | D | D | D |
| `procurement.subscriptions.update` | PUT | `procurement/subscriptions/{subscription}` | V | V | D | D | D | D | D |
| `procurement.vendors.index` | GET | `procurement/vendors` | A | A | A | D | D | A | D |
| `procurement.vendors.show` | GET | `procurement/vendors/{vendor}` | A | A | A | D | D | A | D |
| `procurement.vendors.store` | POST | `procurement/vendors` | V | V | D | D | D | D | D |
| `procurement.vendors.update` | PUT | `procurement/vendors/{vendor}` | V | V | D | D | D | D | D |
| `projects.clients.index` | GET | `projects/clients` | A | A | A | D | D | A | D |
| `projects.clients.store` | POST | `projects/clients` | V | V | V | D | D | D | D |
| `projects.clients.update` | PUT | `projects/clients/{client}` | V | V | V | D | D | D | D |
| `projects.leads.index` | GET | `projects/leads` | A | A | A | D | D | A | D |
| `projects.leads.stage` | POST | `projects/leads/{lead}/stage` | V | V | D | D | D | D | D |
| `projects.leads.store` | POST | `projects/leads` | V | V | V | D | D | D | D |
| `projects.leads.update` | PUT | `projects/leads/{lead}` | V | V | D | D | D | D | D |
| `projects.members.end` | POST | `projects/members/{member}/end` | V | V | D | D | D | D | D |
| `projects.members.store` | POST | `projects/{project}/members` | V | V | D | D | D | D | D |
| `projects.milestones.store` | POST | `projects/{project}/milestones` | V | V | D | D | D | D | D |
| `projects.milestones.update` | PUT | `projects/milestones/{milestone}` | V | V | D | D | D | D | D |
| `projects.projects.closeout` | POST | `projects/{project}/closeout` | A | A | D | D | D | D | D |
| `projects.projects.index` | GET | `projects` | A | A | A | A | A | A | D |
| `projects.projects.show` | GET | `projects/{project}` | A | A | D | A | D | A | D |
| `projects.projects.status` | POST | `projects/{project}/status` | V | V | D | D | D | D | D |
| `projects.projects.store` | POST | `projects` | V | V | V | D | D | D | D |
| `projects.projects.update` | PUT | `projects/{project}` | V | V | D | D | D | D | D |
| `projects.sessions.certificates` | POST | `projects/sessions/{session}/certificates` | R | R | D | D | D | D | D |
| `projects.sessions.participants` | POST | `projects/sessions/{session}/participants` | V | V | D | D | D | D | D |
| `projects.sessions.show` | GET | `projects/sessions/{session}` | A | A | D | A | D | A | D |
| `projects.sessions.store` | POST | `projects/{project}/sessions` | V | V | D | D | D | D | D |
| `projects.sessions.update` | PUT | `projects/sessions/{session}` | V | V | D | D | D | D | D |
| `projects.tasks.store` | POST | `projects/milestones/{milestone}/tasks` | V | V | D | D | D | D | D |
| `projects.tasks.update` | PUT | `projects/tasks/{task}` | V | V | D | D | D | D | D |
| `reauth.show` | GET | `reauth` | A | A | A | A | A | A | A |
| `reauth.store` | POST | `reauth` | V | V | V | V | V | V | V |
| `reports.dashboard.finance` | GET | `reports/dashboard/finance` | A | A | D | D | D | A | D |
| `reports.dashboard.owner` | GET | `reports/dashboard/owner` | A | D | D | D | D | A | D |
| `reports.exports.index` | GET | `reports/exports` | A | A | A | A | D | A | D |
| `reports.exports.store` | POST | `reports/exports` | V | V | V | V | V | V | V |
| `reports.index` | GET | `reports` | A | A | A | A | A | A | A |
| `reports.projects.dashboard` | GET | `reports/projects` | A | A | A | A | A | A | D |
| `reports.projects.financials` | GET | `reports/projects/{project}/financials` | A | A | D | A | D | A | D |
| `reports.schedules.store` | POST | `reports/schedules` | V | V | V | V | V | V | V |
| `reports.schedules.toggle` | POST | `reports/schedules/{schedule}/toggle` | A | D | D | D | D | D | D |
| `reports.view` | GET | `reports/view/{key}` | A | A | D | D | D | A | D |
| `search` | GET | `search` | A | A | A | A | A | A | A |
| `verify.certificate` | GET | `verify/certificate/{token}` | N | N | N | N | N | N | N |
<!-- route-matrix:end -->
