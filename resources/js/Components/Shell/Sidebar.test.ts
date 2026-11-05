import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ props: { href: { type: String, required: true } }, setup: (p, { slots, attrs }) => () => h('a', { ...attrs, href: p.href }, slots.default?.()) }),
}));

const { default: Sidebar } = await import('./Sidebar.vue');

const items = [
    { label: 'Dashboard', href: 'http://app.test/', group: 'Overview', icon: 'home' },
    { label: 'Documents', href: 'http://app.test/documents', group: 'Documents', icon: 'document' },
    { label: 'Document reports', href: 'http://app.test/documents/reports/inventory', group: 'Documents', icon: 'report' },
];

describe('<Sidebar>', () => {
    it('renders only the entries the server sent (permission filtering happens server-side), grouped', () => {
        const w = mount(Sidebar, { props: { items, currentUrl: '/documents' } });
        expect(w.findAll('a').map((a) => a.text())).toEqual(['Dashboard', 'Documents', 'Document reports']);
        expect(w.text()).toContain('Overview');
        expect(w.text()).not.toContain('Audit log');
    });

    it('highlights the longest matching entry as the current page', () => {
        const w = mount(Sidebar, { props: { items, currentUrl: '/documents/reports/inventory?x=1' } });
        expect(w.get('[aria-current="page"]').text()).toBe('Document reports');
        const home = mount(Sidebar, { props: { items, currentUrl: '/' } });
        expect(home.get('[aria-current="page"]').text()).toBe('Dashboard');
        const doc = mount(Sidebar, { props: { items, currentUrl: '/documents/12' } });
        expect(doc.get('[aria-current="page"]').text()).toBe('Documents');
    });
});
