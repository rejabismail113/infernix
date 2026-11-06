/**
 * Turns an audit event's before/after JSON into readable rows for people (G-06, M12-04): plain-English field
 * labels, formatted dates / money / statuses, and only the fields that changed. Raw JSON stays available in the
 * CSV export. Money values arrive as decimal strings and are only re-formatted as text — never parsed to floats.
 */
export type ChangeKind = 'changed' | 'added' | 'removed';
export interface ChangeRow { key: string; label: string; from: string | null; to: string | null; kind: ChangeKind; technical: boolean }

const TECHNICAL = /^(id|company_id|created_at|updated_at|deleted_at|storage_key|content_hash|sha256|.*_hash|scanner|scan_status|mime_type|extension|size_bytes|version_no|current_version_no|retention_rule_id|redactions|remember_token|request_id)$/;
const PRIORITY = ['document_no', 'claim_no', 'payment_no', 'bill_no', 'transaction_no', 'journal_no', 'asset_no', 'credential_no', 'batch_no', 'staff_no',
    'name', 'title', 'purpose', 'description', 'status', 'amount', 'total_amount', 'cost', 'reason'];
const LABELS: Record<string, string> = {
    document_no: 'Document no.', claim_no: 'Claim no.', payment_no: 'Payment no.', bill_no: 'Bill no.', transaction_no: 'Transaction no.',
    journal_no: 'Journal no.', asset_no: 'Asset no.', staff_no: 'Staff no.', batch_no: 'Batch no.', credential_no: 'Credential no.',
    ip: 'IP address', remember: 'Remember me', pdf_document_id: 'PDF document', ssm_no: 'SSM no.', mfa_confirmed_at: 'MFA confirmed at', entity_id: 'Linked record', link_type: 'Link type',
};
const MONEY_KEY = /(^|_)(amount|total|cost|price|value|balance|debit|credit|subtotal|outstanding|paid|fee|rate_amount|max_amount|min_amount|gross_amount|net_amount|tax_amount)$/;
const DECIMAL = /^-?\d+(\.\d+)?$/;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const ISO_DATETIME = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/;
/** Keys holding a user id; the page passes `people` (id → name) for those on screen (AuditLogController::PERSON_KEY). */
const PERSON_KEY = /^(user_id|owner_id|actor_id|approver_id|delegate_id|delegator_id|pm_user_id|staff_user_id|uploaded_by|linked_by|shared_by|triggered_by|[a-z_]+_by)$/;
export type People = Record<string | number, string>;
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function humanize(key: string): string {
    if (LABELS[key]) return LABELS[key];
    const base = key.endsWith('_id') ? key.slice(0, -3) : key;
    const words = base.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ').trim();
    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** "App\\Modules\\Finance\\Models\\ClaimLine" → "Claim line" */
export function entityLabel(type: string): string {
    return humanize((type.split('\\').pop() ?? type).replace(/([a-z])([A-Z])/g, '$1_$2').toLowerCase());
}

/** Thousands separators on a decimal string, 2 dp, without converting to a JS number. */
function money(v: string): string {
    const neg = v.startsWith('-');
    const [int, frac = ''] = (neg ? v.slice(1) : v).split('.');
    const grouped = int.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${neg ? '-' : ''}RM ${grouped}.${(frac + '00').slice(0, 2)}`;
}

function date(v: string): string {
    const [y, m, d] = v.slice(0, 10).split('-');
    const out = `${Number(d)} ${MONTHS[Number(m) - 1]} ${y}`;
    return ISO_DATETIME.test(v) ? `${out}, ${v.slice(11, 16)}` : out;
}

export function formatValue(key: string, value: unknown, people: People = {}): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) return value.length === 0 ? 'none' : value.map((v) => formatValue(key, v, people)).join(', ');
    if (typeof value === 'object') {
        const o = value as Record<string, unknown>;
        // A serialised money value {amount, currency} reads as one amount.
        if (typeof o.amount === 'string' && DECIMAL.test(o.amount) && Object.keys(o).every((k) => k === 'amount' || k === 'currency')) {
            const text = money(o.amount);
            return o.currency && o.currency !== 'MYR' ? text.replace('RM', String(o.currency)) : text;
        }
        const entries = Object.entries(o).filter(([, v]) => v !== null && v !== '');
        return entries.length === 0 ? '—' : entries.map(([k, v]) => `${humanize(k)}: ${formatValue(k, v, people)}`).join(' · ');
    }
    const s = String(value);
    if (PERSON_KEY.test(key) && /^\d+$/.test(s)) return people[s] ?? `User #${s}`;
    if (key.endsWith('_id') && /^\d+$/.test(s)) return `#${s}`;
    if (MONEY_KEY.test(key) && DECIMAL.test(s)) return money(s);
    if (ISO_DATETIME.test(s) || (ISO_DATE.test(s) && /(_on|_at|date|_from|_to|until|due)$/.test(key))) return date(s);
    if (key === 'status' || key.endsWith('_status') || key === 'action' || key === 'classification' || key.endsWith('_type') || key === 'direction') {
        return humanize(s.toLowerCase());
    }
    return s;
}

const isEmptyish = (v: unknown) => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);

export function describeChanges(before: Record<string, unknown> | unknown[] | null, after: Record<string, unknown> | unknown[] | null, people: People = {}): ChangeRow[] {
    const b = before && !Array.isArray(before) ? before : {};
    const a = after && !Array.isArray(after) ? after : {};
    const keys = [...new Set([...Object.keys(b), ...Object.keys(a)])];
    const rows: ChangeRow[] = [];
    for (const key of keys) {
        const hasB = key in b && !isEmptyish(b[key]);
        const hasA = key in a && !isEmptyish(a[key]);
        if (!hasB && !hasA) continue;
        if (hasB && hasA && JSON.stringify(b[key]) === JSON.stringify(a[key])) continue;
        rows.push({
            key, label: humanize(key), technical: TECHNICAL.test(key),
            from: hasB ? formatValue(key, b[key], people) : null, to: hasA ? formatValue(key, a[key], people) : null,
            kind: hasB && hasA ? 'changed' : hasA ? 'added' : 'removed',
        });
    }
    const rank = (k: string) => { const i = PRIORITY.indexOf(k); return i === -1 ? PRIORITY.length : i; };
    return rows.sort((x, y) => Number(x.technical) - Number(y.technical) || rank(x.key) - rank(y.key));
}
