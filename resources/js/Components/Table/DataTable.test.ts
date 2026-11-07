import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import DataTable from './DataTable.vue';
import type { TableColumn } from '@/types/table';

const columns: TableColumn[] = [
    { key: 'name', label: 'Name', sortable: true, hideable: false },
    { key: 'amount', label: 'Amount', sortable: true, numeric: true },
    { key: 'status', label: 'Status' },
];
const rows = [
    { id: 1, name: 'Charlie', amount: '100.00', status: 'paid' },
    { id: 2, name: 'alpha', amount: '9.50', status: 'draft' },
    { id: 3, name: 'Bravo', amount: '1000.00', status: 'approved' },
];
const names = (w: ReturnType<typeof mount>) => w.findAll('[data-test="row"]').map((r) => r.find('td').text());

afterEach(() => window.localStorage.clear());

describe('<DataTable>', () => {
    it('sorts client-side, case-insensitively and numerically for decimal strings', async () => {
        const w = mount(DataTable, { props: { columns, rows } });
        expect(names(w)).toEqual(['Charlie', 'alpha', 'Bravo']);

        await w.get('[data-test="sort-name"]').trigger('click');
        expect(names(w)).toEqual(['alpha', 'Bravo', 'Charlie']);
        expect(w.get('th[aria-sort="ascending"]').text()).toContain('Name');

        await w.get('[data-test="sort-name"]').trigger('click');
        expect(names(w)).toEqual(['Charlie', 'Bravo', 'alpha']);

        await w.get('[data-test="sort-amount"]').trigger('click');
        expect(w.findAll('[data-test="row"]').map((r) => r.findAll('td')[1].text())).toEqual(['9.50', '100.00', '1000.00']);
    });

    it('in server mode emits sort/page/per-page instead of sorting locally', async () => {
        const w = mount(DataTable, {
            props: { columns, rows, pagination: { total: 30, perPage: 10, currentPage: 2, lastPage: 3 }, sort: { key: 'name', dir: 'asc' } },
        });
        await w.get('[data-test="sort-name"]').trigger('click');
        expect(w.emitted('sort')?.[0]).toEqual([{ key: 'name', dir: 'desc' }]);
        expect(names(w)).toEqual(['Charlie', 'alpha', 'Bravo']); // untouched: server decides order

        await w.get('[data-test="next"]').trigger('click');
        await w.get('[data-test="prev"]').trigger('click');
        expect(w.emitted('page')).toEqual([[3], [1]]);

        await w.get('[data-test="per-page"]').setValue('50');
        expect(w.emitted('per-page')?.[0]).toEqual([50]);
        expect(w.get('[data-test="range"]').text()).toBe('11–20 of 30');
    });

    it('column chooser hides and shows columns, but never a non-hideable one', async () => {
        const w = mount(DataTable, { props: { columns, rows }, attachTo: document.body });
        expect(w.findAll('thead th')).toHaveLength(3);
        await w.get('[data-test="columns-button"]').trigger('click');
        await w.get('[data-test="column-toggle-status"]').setValue(false);
        expect(w.findAll('thead th').map((t) => t.text())).toEqual(['Name', 'Amount']);
        expect(w.findAll('[data-test="row"]')[0].findAll('td')).toHaveLength(2);
        expect((w.get('[data-test="column-toggle-name"]').element as HTMLInputElement).disabled).toBe(true);
        await w.get('[data-test="column-toggle-status"]').setValue(true);
        expect(w.findAll('thead th')).toHaveLength(3);
        w.unmount();
    });

    it('density changes row padding and preferences persist per prefsKey', async () => {
        const w = mount(DataTable, { props: { columns, rows, prefsKey: 'test.grid' } });
        expect(w.get('[data-test="row"] td').classes()).toContain('py-2');
        await w.get('[data-test="density-button"]').trigger('click');
        await w.get('[data-test="density-compact"]').trigger('click');
        expect(w.get('[data-test="row"] td').classes()).toContain('py-1');
        await w.get('[data-test="density-button"]').trigger('click');
        await w.get('[data-test="density-comfortable"]').trigger('click');
        expect(w.get('[data-test="row"] td').classes()).toContain('py-3');
        await w.get('[data-test="columns-button"]').trigger('click');
        await w.get('[data-test="column-toggle-amount"]').setValue(false);

        const again = mount(DataTable, { props: { columns, rows, prefsKey: 'test.grid' } });
        await again.vm.$nextTick();
        expect(again.get('[data-test="row"] td').classes()).toContain('py-3');
        expect(again.findAll('thead th').map((t) => t.text())).toEqual(['Name', 'Status']);
    });

    it('shows the export menu only when an export endpoint is given, with CSV and PDF', async () => {
        expect(mount(DataTable, { props: { columns, rows } }).find('[data-test="export-link"]').exists()).toBe(false);
        const w = mount(DataTable, { props: { columns, rows, exportUrl: '/admin/audit-log/export' } });
        await w.get('[data-test="export-link"]').trigger('click');
        expect(w.get('[data-test="export-csv"]').attributes('href')).toBe('/admin/audit-log/export');
        expect(w.get('[data-test="export-pdf"]').attributes('href')).toBe('/admin/audit-log/export?format=pdf');
        const f = mount(DataTable, { props: { columns, rows, exportUrl: '/finance/reports/pl/export?year=2026' } });
        await f.get('[data-test="export-link"]').trigger('click');
        expect(f.get('[data-test="export-pdf"]').attributes('href')).toBe('/finance/reports/pl/export?year=2026&format=pdf');
    });

    it('renders loading skeletons and an empty state', () => {
        expect(mount(DataTable, { props: { columns, rows, loading: true } }).findAll('[data-test="skeleton-row"]')).toHaveLength(5);
        expect(mount(DataTable, { props: { columns, rows: [], emptyText: 'Nothing here' } }).text()).toContain('Nothing here');
    });
});
