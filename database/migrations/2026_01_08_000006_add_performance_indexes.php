<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Performance pass (NFR-05): indexes for the default sort / filter of the common list pages and the reporting
 * views, found with EXPLAIN on the 50k-row benchmark dataset (php artisan perf:benchmark).
 *
 * @see SRS NFR-05
 */
return new class extends Migration
{
    public const INDEXES = [
        'audit_events_occurred_at_idx' => 'audit_events (occurred_at DESC)',
        'transactions_date_id_idx' => 'transactions (transaction_date DESC, id DESC)',
        'claims_created_idx' => 'claims (created_at DESC)',
        'claims_status_idx' => 'claims (status)',
        'bills_bill_date_idx' => 'bills (bill_date DESC)',
        'payments_date_idx' => 'payments (payment_date DESC)',
        'documents_uploaded_idx' => 'documents (uploaded_at DESC, id DESC)',
        'commercial_documents_type_issue_idx' => 'commercial_documents (type, issue_date DESC)',
        'commercial_documents_client_idx' => 'commercial_documents (client_id)',
        'commercial_documents_project_idx' => 'commercial_documents (project_id)',
        'bank_transactions_status_idx' => 'bank_transactions (bank_account_id, status)',
        'bank_matches_line_idx' => 'bank_matches (journal_line_id, amount)',
        'notifications_notifiable_unread_idx' => 'notifications (notifiable_type, notifiable_id, read_at)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $def) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$def}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
