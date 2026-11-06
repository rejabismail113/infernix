# UAT evidence — INF-SRS-001 §9 (UAT-01 … UAT-12)

Release candidate `v1.0.0-rc1`. Each scenario has **automated proof** (Pest tests, run with
`php artisan test --group=UAT-xx` against PostgreSQL) and **visual evidence**: a screenshot of the same
scenario in the running app, taken from the demo data created through the real services.

Reproduce the screenshots:

```bash
make demo          # php artisan db:seed --class=UatDemoSeeder  (dev / UAT only; refuses production)
make uat-screens   # node scripts/uat-screenshots.cjs → docs/uat/*.png (exits 1 if any page returns an unexpected status)
```

Demo users: `demo.{fin1,fin2,pm,hr,stf1,stf2,aud}@infernix.local`, password `SEED_DEMO_PASSWORD`. FIN users
share the dev TOTP secret of the seeded owner. **All names, amounts and identifiers are fictitious.**

Formal sign-off is done by the business owner (Julaiha) by repeating each step below in the UAT environment and
initialling the "Accepted" column.

| UAT | Scenario (SRS) | Automated proof (`--group`) | Screenshot | What the screenshot shows | Accepted |
|---|---|---|---|---|---|
| UAT-01 | Staff opens another staff member's claim by changing the URL → 403, nothing exposed | `tests/Feature/Identity/AuthorizationTest.php`, `tests/Feature/Finance/FinanceHttpTest.php`, `tests/Feature/Documents/DownloadSecurityTest.php`, `tests/Feature/Security/RouteMatrixTest.php` (every `{id}` route × 7 roles) | [UAT-01](uat/UAT-01-staff-opens-other-claim-403.png) | Siti (STF) opening Sam's claim URL gets the 403 page — no claim number, amount or receipt | ☐ |
| UAT-02 | Mileage claim → approval → payout: one accounting impact, payment linked, audit complete | `tests/Feature/Finance/ClaimsFlowTest.php` | [UAT-02](uat/UAT-02-mileage-claim-paid.png) | Claim CL-2026-0001 *Paid*: 100 km × PLACEHOLDER rate, approval trail, linked payment voucher | ☐ |
| UAT-03 | Laptop bought with the company account → vendor payment + asset, no double counting | `tests/Feature/Assets/AssetsTest.php` | [UAT-03](uat/UAT-03-laptop-asset-from-purchase.png) | Asset AST-2026-0001 registered from bill BL-2026-0001 (purchase link, no second expense) | ☐ |
| UAT-04 | Invoice RM 10,000, paid RM 3,000 → outstanding RM 7,000, receipt linked | `tests/Feature/Commercial/CommercialFlowTest.php`, `tests/Feature/Commercial/CommercialHttpTest.php` | [UAT-04](uat/UAT-04-invoice-partially-paid.png) | INV-2026-0001 *Issued · partially paid*, received RM 3,000.00, outstanding RM 7,000.00, receipt RC-2026-0001 | ☐ |
| UAT-05 | Unbalanced journal rejected; no partial journal | `tests/Feature/Finance/LedgerTest.php` (DB constraint trigger + PostingService, 200 randomised journals) | [UAT-05](uat/UAT-05-trial-balance-balanced.png) | Trial balance after all demo postings: total debits = total credits | ☐ |
| UAT-06 | Quotation → project → invoice keeps client and scope, unique references, audited | `tests/Feature/Commercial/CommercialFlowTest.php`, `tests/Feature/Commercial/CommercialHttpTest.php` | [UAT-06](uat/UAT-06-project-from-quotation.png) | PRJ-2026-0001 created from QT-2026-0001: same client, scope line and contract value, *Source quotation* link, billing status from the linked invoice (*partially paid*); the invoice page (UAT-04) links back to QT-2026-0001 | ☐ |
| UAT-07 | Time on an unassigned project rejected unless a role-based exception applies | `tests/Feature/HumanResources/TimesheetsAndPayTest.php`, `tests/Feature/HumanResources/HrHttpTest.php` | [UAT-07](uat/UAT-07-timesheet-assigned-projects-only.png) | Sam's draft timesheet (STF view). The entry picker draws only from his project assignments; saving time on an unassigned project is refused server-side (see tests) | ☐ |
| UAT-08 | A secret reached through a report export → no password / API secret in exports or logs | `tests/Feature/Security/SecretSentinelSweepTest.php` (every DB row, file, log, mail, search index), `tests/Feature/Credentials/CredentialsTest.php`, `tests/Unit/RedactorTest.php` | [UAT-08](uat/UAT-08-credential-metadata-only.png) | Credential record holds metadata + vault reference only; reveal needs a reason, re-auth and an entitlement | ☐ |
| UAT-09 | Editing a posted transaction in a closed period is blocked; authorised reversal / adjustment available | `tests/Feature/Finance/LedgerTest.php`, `tests/Feature/Finance/RegistersAndPeriodsTest.php` | [UAT-09 a](uat/UAT-09-locked-period-transaction.png), [UAT-09 b](uat/UAT-09-fiscal-periods-locked.png) | (a) The January 2026 expense is *Posted*: no edit action, only *Reverse…* (the reversal is dated in an open period); (b) January 2026 shown as locked with its reason | ☐ |
| UAT-10 | Document access without authorisation denied, even with a copied direct file URL | `tests/Feature/Documents/DownloadSecurityTest.php`, `tests/Feature/Security/RouteMatrixTest.php` | [UAT-10](uat/UAT-10-direct-file-url-403.png) | STF opening an invoice PDF's download URL without a valid signature → 403 | ☐ |
| UAT-11 | Approving your own payment without delegated authority → rejected or flagged for an explicit Owner override | `tests/Feature/Finance/PaymentsTest.php`, `tests/Feature/Finance/ClaimsFlowTest.php` | [UAT-11](uat/UAT-11-maker-cannot-approve-own-payment.png) | Farah prepared PV-2026-0003; the page says someone else must approve it and the button is disabled (server rejects it too) | ☐ |
| UAT-12 | Subscription renewal creates a scheduled event; a finance transaction only when a bill / payment is recorded | `tests/Feature/Procurement/ProcurementTest.php` | [UAT-12](uat/UAT-12-subscription-renewal-no-finance-entry.png) | Microsoft 365 renewal due in 25 days (alerts at 90/60/30 days); *Paid (linked)* is RM 0.00 — no transaction or bill until one is recorded | ☐ |

Additional Prompt 6 evidence: [bank reconciliation proof](uat/P4-bank-reconciliation-proof.png),
[e-Invoice banner (accountant verification required)](uat/P4-einvoice-banner.png),
[backup status](uat/P4-backup-status.png), [owner dashboard](uat/P4-owner-dashboard.png).

## Cross-cutting proof

- **Every route × every role**: `tests/Feature/Security/RouteMatrixTest.php` against the generated table in
  [PERMISSION_MATRIX.md](PERMISSION_MATRIX.md) — the suite fails on an unlisted route, drift or any 5xx.
- **Performance**: [PERFORMANCE.md](PERFORMANCE.md) — all measured pages are far under the p95 < 3 s target at 50k rows.
- **Accessibility**: `node scripts/a11y-check.cjs` — 0 serious / critical axe violations on the core pages.
- **Restore**: `make restore-test` restores the newest encrypted backup into a scratch database and checks it.
