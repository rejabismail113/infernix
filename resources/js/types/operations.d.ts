/** Shared payload types for the P3 operational modules (M05, M07, M08, M09, M10, M13). Money is always a decimal string. */
export interface Option { id: number; label: string; meta?: string; client_id?: number; user_id?: number | null; vendor_id?: number | null }

/** A booked finance record an operational record LINKS to (never copies) — FinanceRecords snapshot. */
export interface FinanceRecordSnapshot {
    type: 'transaction' | 'bill' | 'claim' | 'payment'; id: number; number: string; date: string; amount: string; status: string;
    party: string | null; description: string | null; url: string; purpose?: string; note?: string | null;
}

/** Schema-driven form field (Components/Form/SchemaFields.vue). */
export interface FieldDef {
    key: string; label: string;
    type: 'text' | 'textarea' | 'date' | 'select' | 'money' | 'number' | 'checkbox' | 'url' | 'email';
    options?: { value: string | number; label: string }[]; span?: 1 | 2; placeholder?: string; required?: boolean; help?: string;
}
