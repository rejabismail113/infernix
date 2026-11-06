# Power BI connection (M11-06)

Infernix One exposes a curated, **read-only** PostgreSQL schema `reporting` for Power BI. It contains one view per
report in SRS §8.1 and nothing else. Views carry business figures only — **no staff names, NRIC/passport, email,
phone, bank account numbers, free-text descriptions, secrets, usernames or vault references**
(`tests/Feature/Reporting/ReportingP4Test.php` enforces the column list and the grants).

| View | SRS §8.1 report | Grain |
|---|---|---|
| `reporting.revenue_vs_cash` | Revenue vs cash receipts | month (accrual revenue, credit notes, net revenue, cash received — separate columns) |
| `reporting.project_margin` | Project margin | project (net revenue − linked direct costs; overhead excluded) |
| `reporting.outstanding_receivables` | Outstanding receivables | issued invoice (total − receipts − credits, days overdue) |
| `reporting.expenses_by_category` | Expenses by category | month × expense account (owner drawings are equity, never listed) |
| `reporting.claims_outstanding` | Claim outstanding | claim (stage: pending approval / approved unpaid / paid…) |
| `reporting.asset_register` | Asset register | asset (cost, class, status, assigned flag, posted depreciation) |
| `reporting.tax_pack_journal_lines` | Tax preparation pack | posted journal line with tax review flags |
| `reporting.credential_renewal` | Credential renewal | credential (system, owner id, rotation due, status — never a secret) |

## 1. Create the login (DBA, once)

The migration creates the NOLOGIN group role `infernix_reporting_read` with `USAGE` on `reporting` and `SELECT` on
the views only — never on base tables. Create the Power BI login yourself; the password is **never** stored in
the repository, `.env` files committed to git, or the application database:

```sql
CREATE ROLE powerbi LOGIN PASSWORD '<generate in your password manager>' IN ROLE infernix_reporting_read
  CONNECTION LIMIT 5;
ALTER ROLE powerbi SET search_path = reporting;
ALTER ROLE powerbi SET default_transaction_read_only = on;
```

On managed Postgres where the app user cannot create roles, the migration prints a NOTICE; run the
`CREATE ROLE infernix_reporting_read NOLOGIN;` and the `GRANT` lines from
`app/Modules/Reporting/Database/Migrations/2026_01_08_000002_create_reporting_schema.php` as the admin user.

## 2. Network

Expose Postgres to Power BI only through the **on-premises data gateway** (or a private link) — never open 5432
to the internet. Require TLS (`sslmode=require`) and allow-list the gateway host in `pg_hba.conf`:

```
hostssl  infernix  powerbi  <gateway-ip>/32  scram-sha-256
```

## 3. Power BI Desktop

1. *Get data → PostgreSQL database*; server `db-host:5432`, database `infernix`, mode **Import** (recommended)
   or DirectQuery.
2. Sign in as `powerbi` (Database authentication). Pick only views under `reporting`.
3. Money columns are `NUMERIC(18,4)` — set the type to *Fixed decimal number* (never *Decimal number*, which is a float).
4. Publish; configure the gateway data source with the same credentials and a daily refresh after 07:00 MYT
   (after the nightly jobs).

## 4. Rotation and revocation

Rotate the `powerbi` password with the other service credentials (record the rotation in Credentials, M13). To
revoke: `ALTER ROLE powerbi NOLOGIN;` or `DROP ROLE powerbi;`. Adding a view: create it in a NEW migration and
add it to the `GRANT SELECT` list — the reporting test fails if a view lacks the grant or exposes a forbidden
column.
