import { describe, expect, it } from 'vitest';
import { describeChanges, entityLabel, formatValue, humanize } from './auditDiff';

describe('auditDiff (G-06 readable audit trail)', () => {
    it('shows only changed fields, in plain English', () => {
        const rows = describeChanges({ status: 'draft', purpose: 'test' }, { status: 'submitted', purpose: 'test', submitted_at: '2026-11-06 19:36:28' });
        expect(rows.map((r) => [r.label, r.from, r.to, r.kind])).toEqual([
            ['Status', 'Draft', 'Submitted', 'changed'],
            ['Submitted at', null, '6 Nov 2026, 19:36', 'added'],
        ]);
    });

    it('formats money from decimal strings without floats, booleans, empty lists and nested objects', () => {
        expect(formatValue('total_amount', '1234567.5')).toBe('RM 1,234,567.50');
        expect(formatValue('cost', '-4200.0000')).toBe('-RM 4,200.00');
        expect(formatValue('remember', false)).toBe('No');
        expect(formatValue('tags', [])).toBe('none');
        expect(formatValue('address', { line1: 'Jalan 1', city: 'KL', postcode: null })).toBe('Line1: Jalan 1 · City: KL');
        expect(formatValue('due_on', '2026-12-06')).toBe('6 Dec 2026');
    });

    it('puts meaningful fields first and technical ones last; empty-to-empty is not a change', () => {
        const rows = describeChanges(null, { id: 6, company_id: 1, title: 'Receipt', content_hash: 'abc', claim_no: 'CL-2026-0002', tags: null });
        expect(rows.map((r) => r.key)).toEqual(['claim_no', 'title', 'id', 'company_id', 'content_hash']);
        expect(rows.filter((r) => r.technical).map((r) => r.key)).toEqual(['id', 'company_id', 'content_hash']);
        expect(describeChanges([], { total_amount: '2.0000' })[0]).toMatchObject({ label: 'Total amount', to: 'RM 2.00', kind: 'added' });
    });

    it('humanizes keys and entity class names', () => {
        expect(humanize('claim_no')).toBe('Claim no.');
        expect(humanize('staff_profile_id')).toBe('Staff profile');
        expect(entityLabel('App\\Modules\\Finance\\Models\\ClaimLine')).toBe('Claim line');
    });

    it('names people, reads money objects as one amount and marks linked ids', () => {
        const rows = describeChanges({ status: 'pending_approval', paid_amount: { amount: '0.00', currency: 'MYR' } },
            { status: 'approved', approved_by: 17, journal_id: 11221, paid_amount: { amount: '4200.00', currency: 'MYR' } }, { 17: 'Hakim' });
        expect(Object.fromEntries(rows.map((r) => [r.label, `${r.from ?? ''}→${r.to ?? ''}`]))).toEqual({
            Status: 'Pending approval→Approved', 'Paid amount': 'RM 0.00→RM 4,200.00', 'Approved by': '→Hakim', Journal: '→#11221',
        });
        expect(formatValue('posted_by', 99)).toBe('User #99');
    });
});
