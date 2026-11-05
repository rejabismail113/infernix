import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Stepper from './Stepper.vue';

const steps = [{ key: 'builder', label: 'Builder' }, { key: 'review', label: 'Review' }, { key: 'issue', label: 'Issue' }];

describe('<Stepper>', () => {
    it('marks the current step and only lets you go back', async () => {
        const w = mount(Stepper, { props: { steps, current: 'review' } });
        const buttons = w.findAll('button');
        expect(buttons[1].attributes('aria-current')).toBe('step');
        expect(buttons[2].attributes('disabled')).toBeDefined();
        await buttons[0].trigger('click');
        await buttons[2].trigger('click');
        expect(w.emitted('select')).toEqual([['builder']]);
    });
});
