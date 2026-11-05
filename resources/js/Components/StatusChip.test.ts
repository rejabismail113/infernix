import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusChip from './StatusChip.vue';

describe('<StatusChip>', () => {
    it.each([
        ['approved', 'success'], ['paid', 'success'], ['posted', 'success'],
        ['pending_approval', 'warning'], ['submitted', 'warning'],
        ['rejected', 'danger'], ['void', 'danger'], ['medical', 'danger'],
        ['draft', 'neutral'], ['archived', 'neutral'], ['issued', 'info'], ['locked', 'info'],
        ['something_unknown', 'neutral'],
    ])('maps %s to the %s tone', (status, tone) => {
        expect(mount(StatusChip, { props: { status } }).attributes('data-tone')).toBe(tone);
    });

    it('humanises the status as its label', () => {
        expect(mount(StatusChip, { props: { status: 'pending_approval' } }).text()).toBe('Pending approval');
    });

    it('accepts an explicit label and tone', () => {
        const w = mount(StatusChip, { props: { status: 'x', label: 'OWN', tone: 'brand' } });
        expect(w.text()).toBe('OWN');
        expect(w.attributes('data-tone')).toBe('brand');
        expect(w.classes()).toContain('bg-brand-50');
    });
});
