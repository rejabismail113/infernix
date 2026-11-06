/** Commercial (M03) Inertia payloads. Money is always a decimal string. */
export interface DocRow {
    id: number; type: 'quotation' | 'invoice' | 'credit_note' | 'receipt' | 'payment_voucher'; number: string; document_no: string | null; revision_no: number;
    client: string; title: string | null; status: string; settlement_status: string | null; issue_date: string | null; due_date: string | null;
    valid_until: string | null; total: string; outstanding: string | null; created_at: string | null;
}
export interface DocLine {
    id?: number; line_no?: number; description: string; quantity: string; unit: string | null; unit_price: string; discount: string;
    tax_code_id: number | null; tax_rate?: string; net_amount?: string; tax_amount?: string; line_total?: string; revenue_account_id: number | null; source_line_id?: number | null;
}
export interface DocDetail extends DocRow {
    client_id: number | null; counterparty: Record<string, unknown> | null; project: { id: number; code: string; name: string; client_id: number; contract_value: string } | null;
    project_id: number | null; terms: string | null; internal_notes: string | null; subtotal: string; discount_total: string; tax_total: string;
    amount_received: string; amount_credited: string; current_level: number; void_reason: string | null; voided_at: string | null; issued_at: string | null;
    source_type: string; source_id: number | null; related_document_id: number | null; supersedes_id: number | null; pdf_document_id: number | null;
    void_pdf_document_id: number | null; template: string | null; lines: DocLine[];
}
export interface ReceiptRow {
    id: number; receipt_no: string; receipt_date: string; amount: string; unapplied_amount: string; method: string; reference: string | null;
    payer_name: string; status: string; pdf_document_id: number | null; void_reason: string | null; journal_id: number; allocated?: string;
}
export interface PickOption { id: number; label: string; meta?: string; client_id?: number }
export interface TaxCode { id: number; label: string; rate: string }
