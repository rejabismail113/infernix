-- Benchmark-only synthetic ledger volume for `php artisan perf:benchmark` (NFR-05). Executed INSIDE a transaction
-- that the command always rolls back, so nothing here is ever committed. Kept outside app/ on purpose: application
-- code may create journals only through PostingService (tests/Architecture/ArchitectureTest.php, M01-06).
-- Placeholders: :company :user :period :date :count :expense :bank_gl (integers / ISO date, substituted by the command).
INSERT INTO journals (company_id, journal_no, journal_date, posting_date, fiscal_period_id, source_type, memo, status, created_by, created_at, updated_at)
    SELECT :company, 'BENCH-JV-' || g, DATE ':date', DATE ':date', :period, 'manual', 'bench', 'draft', :user, now(), now() FROM generate_series(1, :count) g;
INSERT INTO journal_lines (journal_id, line_no, account_id, debit, credit) SELECT id, 1, :expense, 10, 0 FROM journals WHERE journal_no LIKE 'BENCH-JV-%';
INSERT INTO journal_lines (journal_id, line_no, account_id, debit, credit) SELECT id, 2, :bank_gl, 0, 10 FROM journals WHERE journal_no LIKE 'BENCH-JV-%';
UPDATE journals SET status = 'posted', posted_at = now(), posted_by = :user WHERE journal_no LIKE 'BENCH-JV-%';
