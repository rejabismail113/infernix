import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PickerModal from './PickerModal.vue';

const items = [
    { id: 1, label: 'Infernix Evolv Hub', meta: '#1' },
    { id: 2, label: 'Acme Training Sdn Bhd', meta: '#2' },
    { id: 3, label: 'Borneo Analytics', meta: '#3' },
];
const tabs = [{ key: 'company', label: 'Company' }, { key: 'user', label: 'Staff' }];
const mountPicker = (props: Record<string, unknown> = {}) =>
    mount(PickerModal, { props: { open: true, title: 'Pick', items, tabs, activeTab: 'company', ...props }, global: { stubs: { teleport: true } }, attachTo: document.body });

beforeEach(() => vi.useFakeTimers());
afterEach(() => { vi.useRealTimers(); document.body.innerHTML = ''; });

describe('<PickerModal>', () => {
    it('renders tabs and emits tab changes with a fresh search', async () => {
        const w = mountPicker();
        expect(w.get('[data-test="tab-company"]').attributes('aria-selected')).toBe('true');
        await w.get('[data-test="tab-user"]').trigger('click');
        expect(w.emitted('update:activeTab')?.[0]).toEqual(['user']);
        expect(w.emitted('search')?.[0]).toEqual(['', 'user']);
    });

    it('debounces the search input before emitting', async () => {
        const w = mountPicker({ debounceMs: 250 });
        await w.get('[data-test="picker-search"]').setValue('acm');
        expect(w.emitted('search')).toBeUndefined();
        vi.advanceTimersByTime(260);
        expect(w.emitted('search')?.[0]).toEqual(['acm', 'company']);
    });

    it('filters locally when asked and shows an empty state', async () => {
        const w = mountPicker({ localFilter: true });
        await w.get('[data-test="picker-search"]').setValue('borneo');
        expect(w.findAll('[role="option"]').map((o) => o.text())).toEqual(['Borneo Analytics#3']);
        await w.get('[data-test="picker-search"]').setValue('zzz');
        expect(w.find('[data-test="picker-empty"]').exists()).toBe(true);
    });

    it('selects with the keyboard (↓ then Enter) and closes', async () => {
        const w = mountPicker();
        const input = w.get('[data-test="picker-search"]');
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'Enter' });
        expect(w.emitted('select')?.[0]).toEqual([items[1], 'company']);
        expect(w.emitted('close')).toBeTruthy();
    });

    it('requires a choice before confirming, then confirms the clicked item', async () => {
        const w = mountPicker();
        expect((w.get('[data-test="picker-confirm"]').element as HTMLButtonElement).disabled).toBe(true);
        await w.get('[data-test="picker-item-3"]').trigger('click');
        await w.get('[data-test="picker-confirm"]').trigger('click');
        expect(w.emitted('select')?.[0]).toEqual([items[2], 'company']);
    });

    it('closes on Escape and on the close button', async () => {
        const w = mountPicker();
        await w.get('[role="dialog"]').trigger('keydown', { key: 'Escape' });
        await w.get('[data-test="modal-close"]').trigger('click');
        expect(w.emitted('close')).toHaveLength(2);
    });
});
