<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Curated READ-ONLY `reporting` schema for Power BI (M11-06): one view per SRS §8.1 report. No PII (no staff
 * names, NRIC, email, phone, bank account numbers, free-text descriptions) and no secret columns. The NOLOGIN group
 * role `infernix_reporting_read` gets USAGE on the schema and SELECT on its views only — never on base tables. A DBA
 * creates the actual login role IN ROLE infernix_reporting_read (docs/POWER_BI.md); no password lives in code.
 *
 * @see SRS M11-06, §8.1, NFR-02, NFR-07
 */
return new class extends Migration
{
    public const VIEWS = [
        'revenue_vs_cash', 'project_margin', 'outstanding_receivables', 'expenses_by_category',
        'claims_outstanding', 'asset_register', 'tax_pack_journal_lines', 'credential_renewal',
    ];

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS reporting');

        // Revenue (accrual, invoices − credit notes) shown separately from cash actually received, by month.
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW reporting.revenue_vs_cash AS
WITH months AS (
    SELECT date_trunc('month', j.posting_date)::date AS month,
           SUM(CASE WHEN a.type = 'income' AND j.source_type = 'invoice' THEN l.credit - l.debit ELSE 0 END) AS invoiced_revenue,
           SUM(CASE WHEN a.type = 'income' AND j.source_type = 'credit_note' THEN l.debit - l.credit ELSE 0 END) AS credit_notes,
           SUM(CASE WHEN a.subtype IN ('bank', 'cash') AND j.source_type = 'receipt' THEN l.debit - l.credit ELSE 0 END) AS cash_received
    FROM journal_lines l JOIN journals j ON j.id = l.journal_id JOIN chart_accounts a ON a.id = l.account_id
    WHERE j.status IN ('posted', 'reversed')
    GROUP BY 1
)
SELECT month, invoiced_revenue, credit_notes, invoiced_revenue - credit_notes AS net_revenue, cash_received FROM months
SQL);

        // Net project revenue less linked direct costs; overhead (untagged lines) excluded.
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW reporting.project_margin AS
SELECT p.id AS project_id, p.code AS project_code, p.name AS project_name, c.name AS client_name, p.engagement_type,
       p.status_delivery, p.status_payment, p.contract_value,
       COALESCE(SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit END), 0) AS net_revenue,
       COALESCE(SUM(CASE WHEN a.type = 'expense' THEN l.debit - l.credit END), 0) AS direct_costs,
       COALESCE(SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit WHEN a.type = 'expense' THEN l.credit - l.debit END), 0) AS contribution
FROM projects p
JOIN clients c ON c.id = p.client_id
LEFT JOIN journal_lines l ON l.project_id = p.id AND EXISTS (SELECT 1 FROM journals j WHERE j.id = l.journal_id AND j.status IN ('posted', 'reversed'))
LEFT JOIN chart_accounts a ON a.id = l.account_id AND a.type IN ('income', 'expense')
GROUP BY p.id, c.name
SQL);

        // Invoice gross less receipt allocations and credits (as of today; filter by issue/due date in BI).
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW reporting.outstanding_receivables AS
SELECT d.id AS invoice_id, d.document_no AS invoice_no, d.client_id, d.counterparty_snapshot->>'name' AS client_name, d.project_id,
       d.issue_date, d.due_date, d.total, d.amount_received, d.amount_credited,
       d.total - d.amount_received - d.amount_credited AS outstanding,
       GREATEST(0, CURRENT_DATE - d.due_date) AS days_overdue, d.settlement_status
FROM commercial_documents d
WHERE d.type = 'invoice' AND d.status IN ('issued', 'accepted')
SQL);

        // Posted categorised business expenses by month; owner drawings are equity and never appear.
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW reporting.expenses_by_category AS
SELECT date_trunc('month', j.posting_date)::date AS month, a.code AS account_code, a.name AS account_name, a.subtype,
       SUM(l.debit - l.credit) AS amount
FROM journal_lines l JOIN journals j ON j.id = l.journal_id JOIN chart_accounts a ON a.id = l.account_id
WHERE j.status IN ('posted', 'reversed') AND a.type = 'expense'
GROUP BY 1, a.code, a.name, a.subtype
SQL);

        // Approved claims less reimbursements; pending approval shown separately (no staff names).
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW reporting.claims_outstanding AS
SELECT c.id AS claim_id, c.claim_no, c.status,
       CASE WHEN c.status IN ('submitted', 'pending_approval') THEN 'pending_approval' WHEN c.status IN ('approved', 'finance_payable') THEN 'approved_unpaid' ELSE c.status END AS stage,
       c.total_amount, c.approved_amount, c.paid_amount,
       CASE WHEN c.status IN ('approved', 'finance_payable') THEN c.approved_amount - c.paid_amount ELSE 0 END AS outstanding,
       c.project_id, c.submitted_at::date AS submitted_on, c.approved_at::date AS approved_on
FROM claims c
WHERE c.status NOT IN ('draft', 'cancelled')
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW reporting.asset_register AS
SELECT a.id AS asset_id, a.asset_no, a.name, cat.name AS category, a.asset_class, a.status, a.acquisition_type,
       a.purchase_source_type, a.acquired_on, a.cost, a.residual_value, a.useful_life_months,
       (a.custodian_staff_id IS NOT NULL) AS assigned, a.disposed_at::date AS disposed_on, a.warranty_expires_on, a.project_id,
       COALESCE((SELECT SUM(d.amount) FROM asset_depreciation d WHERE d.asset_id = a.id AND d.journal_id IS NOT NULL), 0) AS depreciation_posted
FROM assets a LEFT JOIN asset_categories cat ON cat.id = a.category_id
SQL);

        // Tax preparation pack: posted journal lines with review flags (no free-text descriptions).
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW reporting.tax_pack_journal_lines AS
SELECT j.journal_no, j.posting_date, j.source_type, j.status AS journal_status, a.code AS account_code, a.name AS account_name, a.type AS account_type,
       a.tax_treatment_default, l.debit, l.credit, l.project_id,
       t.tax_treatment AS transaction_tax_treatment
FROM journal_lines l JOIN journals j ON j.id = l.journal_id JOIN chart_accounts a ON a.id = l.account_id
LEFT JOIN transactions t ON j.source_type = 'transaction' AND t.id = j.source_id
WHERE j.status IN ('posted', 'reversed')
SQL);

        // System, owner, rotation due and status only — no secret, username or vault reference (M13-05).
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW reporting.credential_renewal AS
SELECT c.credential_no, c.system_name, c.category, c.environment, c.sensitivity, c.owner_id, c.vault_provider,
       c.rotation_interval_days, c.last_rotated_on, c.rotation_due, c.expiry_date, c.status,
       (c.rotation_due IS NOT NULL AND c.rotation_due < CURRENT_DATE) AS rotation_overdue
FROM credentials_metadata c
SQL);

        $views = implode(', ', array_map(fn (string $v): string => "reporting.{$v}", self::VIEWS));
        DB::unprepared(<<<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'infernix_reporting_read') THEN
        CREATE ROLE infernix_reporting_read NOLOGIN;
    END IF;
EXCEPTION WHEN insufficient_privilege THEN
    RAISE NOTICE 'Cannot create role infernix_reporting_read here; ask the DBA (docs/POWER_BI.md).';
END
\$\$;
DO \$\$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'infernix_reporting_read') THEN
        REVOKE ALL ON SCHEMA reporting FROM PUBLIC;
        GRANT USAGE ON SCHEMA reporting TO infernix_reporting_read;
        GRANT SELECT ON {$views} TO infernix_reporting_read;
    END IF;
END
\$\$;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS reporting CASCADE');
    }
};
