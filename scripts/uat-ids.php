<?php

// Prints the record ids of the UatDemoSeeder scenarios as JSON for scripts/uat-screenshots.cjs (dev only).
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$v = fn (string $sql, array $b = []) => DB::selectOne($sql, $b)?->id;
echo json_encode([
    'claim' => $v("SELECT id FROM claims WHERE purpose = 'Cohort 1 delivery travel'"),
    'invoice_pdf_doc' => $v("SELECT d.id FROM documents d JOIN entity_document_links l ON l.document_id = d.id WHERE l.entity_type = 'invoice' ORDER BY d.id LIMIT 1"),
    'invoice' => $v("SELECT id FROM commercial_documents WHERE type = 'invoice' AND title LIKE 'Leadership programme%'"),
    'quotation' => $v("SELECT id FROM commercial_documents WHERE type = 'quotation' AND title LIKE 'Leadership programme%'"),
    'project' => $v("SELECT id FROM projects WHERE name = 'Demo: Cahaya leadership programme'"),
    'asset' => $v("SELECT id FROM assets WHERE serial_no = 'DL7450-0001'"),
    'pending_payment' => $v("SELECT id FROM payments WHERE status = 'pending_approval' ORDER BY id DESC LIMIT 1"),
    'january_txn' => $v("SELECT id FROM transactions WHERE description = 'Demo: printer toner (January)'"),
    'subscription' => $v("SELECT id FROM subscriptions WHERE product LIKE 'Demo: Microsoft 365%'"),
    'credential' => $v("SELECT id FROM credentials_metadata WHERE system_name = 'Demo: Xero (company)'"),
    'timesheet' => $v("SELECT t.id FROM timesheets t JOIN staff_profiles s ON s.id = t.staff_profile_id WHERE s.legal_name = 'Samuel anak Jimbun' ORDER BY t.id DESC LIMIT 1"),
    'bank' => $v('SELECT id FROM bank_accounts ORDER BY id LIMIT 1'),
], JSON_PRETTY_PRINT)."\n";
