export interface JournalRow {
    id: number; journal_no: string; posting_date: string; status: string; source_type: string; source_id: number | null; memo: string | null;
    reversal_of_id: number | null; reversed_by_id: number | null; created_by: string | null; posted_at: string | null;
    lines?: { id: number; line_no: number; account: string; account_id: number; debit: string; credit: string; description: string | null }[];
    total?: string;
}
export interface TransactionRow {
    id: number; transaction_no: string; direction: 'inflow' | 'outflow'; inflow_type: string | null; payment_source: string | null; capitalization_class: string | null;
    transaction_date: string; gross_amount: string; counterparty_name: string | null; description: string | null; bank_reference: string | null;
    tax_treatment: string; status: string; journal_id: number | null; reversal_journal_id: number | null;
}
export interface LinkedDoc { document_id: number; link_type: string; title: string; file_name: string | null }
export interface ClaimLineRow {
    id: number; line_no: number; claim_type_id: number; claim_type: string; claim_type_code: string; is_medical: boolean; receipt_required: boolean;
    incurred_on: string; description: string; amount: string; category_id: number | null; status: string; rejection_reason: string | null;
    origin: string | null; destination: string | null; distance_km: string | null; rate_snapshot: string | null; clinical_note: string | null; clinical_note_masked: boolean;
    documents: LinkedDoc[];
}
export interface ClaimRow {
    id: number; claim_no: string; staff: string | null; staff_user_id: number; purpose: string; status: string; current_level: number; total_amount: string;
    approved_amount: string; paid_amount: string; outstanding: string; submitted_at: string | null; approved_at: string | null; journal_id: number | null;
    rejection_reason: string | null; cost_centre_id: number | null; project_id: number | null; lines?: ClaimLineRow[];
}
export interface BillRow {
    id: number; bill_no: string; vendor: string | null; vendor_id: number; vendor_invoice_no: string; bill_date: string; due_date: string; total_amount: string;
    paid_amount: string; outstanding: string; status: string; current_level: number; tax_treatment: string; description: string | null; journal_id: number | null;
    lines?: { line_no: number; description: string; quantity: string; unit_price: string; amount: string; capitalization_class: string }[];
    unallocated?: string | null;
}
export interface PaymentRow {
    id: number; payment_no: string; payment_date: string; method: string; reference: string | null; payee_type: string; payee_name: string; amount: string;
    status: string; current_level: number; journal_id: number | null; voucher_document_id: number | null; owner_override: boolean; override_reason: string | null;
    bank: string | null; memo: string | null; created_by: number;
    allocations?: { target_type: string; target_id: number; reference: string | null; amount: string }[];
}
export interface Option { id: number; label: string }
export interface ReportColumn { key: string; label: string; numeric?: boolean }
export interface ReportData { title: string; columns: ReportColumn[]; rows: Record<string, unknown>[]; totals: Record<string, string>; notes: string[]; balanced?: boolean }
