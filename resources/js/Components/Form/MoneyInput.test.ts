import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MoneyInput from './MoneyInput.vue';

const DECIMAL = /^-?\d+\.\d{2}$/;

async function typeInto(mode: 'decimal' | 'minor', text: string, allowNegative = false) {
    const w = mount(MoneyInput, { props: { modelValue: mode === 'minor' ? null : '', mode, allowNegative } });
    await w.get('[data-test="money-input"]').setValue(text);
    const emitted = w.emitted('update:modelValue') ?? [];
    return { w, last: emitted.length ? emitted[emitted.length - 1][0] : undefined, all: emitted.map((e) => e[0]) };
}

describe('<MoneyInput> (rule 1: never a float)', () => {
    it('emits normalised decimal strings in decimal mode', async () => {
        expect((await typeInto('decimal', '1234.5')).last).toBe('1234.50');
        expect((await typeInto('decimal', '0.1')).last).toBe('0.10');
        expect((await typeInto('decimal', '.5')).last).toBe('0.50');
        expect((await typeInto('decimal', '007')).last).toBe('7.00');
    });

    it('emits integer minor units in minor mode', async () => {
        const { last } = await typeInto('minor', '1234.56');
        expect(last).toBe(123456);
        expect(Number.isInteger(last)).toBe(true);
    });

    it('strips invalid characters, keeps at most 2 decimals and one point', async () => {
        const { w, last } = await typeInto('decimal', 'RM 1,2a3.4.56');
        expect(last).toBe('123.45');
        expect((w.get('[data-test="money-input"]').element as HTMLInputElement).value).toBe('123.45');
    });

    it('rejects negatives unless allowed', async () => {
        expect((await typeInto('decimal', '-50')).last).toBe('50.00');
        expect((await typeInto('decimal', '-50', true)).last).toBe('-50.00');
        expect((await typeInto('minor', '-0.05', true)).last).toBe(-5);
    });

    it('never emits a non-integer number or a non-decimal string for any input', async () => {
        const samples = ['0.1', '0.2', '0.30000000000000004', '1e3', '99.999', '12.', '3.14159', '1000000.01', 'abc', ''];
        for (const mode of ['decimal', 'minor'] as const) {
            for (const s of samples) {
                const { all } = await typeInto(mode, s);
                for (const v of all) {
                    if (typeof v === 'number') expect(Number.isInteger(v)).toBe(true);
                    else if (v !== null && v !== '') expect(String(v)).toMatch(DECIMAL);
                }
            }
        }
    });

    it('formats to 2 decimal places on blur and shows existing values', async () => {
        const w = mount(MoneyInput, { props: { modelValue: '12' } });
        const input = w.get('[data-test="money-input"]');
        expect((input.element as HTMLInputElement).value).toBe('12.00');
        await input.setValue('7.5');
        await input.trigger('blur');
        expect((input.element as HTMLInputElement).value).toBe('7.50');
        const minor = mount(MoneyInput, { props: { modelValue: 700000, mode: 'minor' } });
        expect((minor.get('[data-test="money-input"]').element as HTMLInputElement).value).toBe('7000.00');
    });

    it('emits empty values as "" (decimal) or null (minor)', async () => {
        const w = mount(MoneyInput, { props: { modelValue: '5.00' } });
        await w.get('[data-test="money-input"]').setValue('');
        expect(w.emitted('update:modelValue')?.at(-1)).toEqual(['']);
        const m = mount(MoneyInput, { props: { modelValue: 500, mode: 'minor' } });
        await m.get('[data-test="money-input"]').setValue('');
        expect(m.emitted('update:modelValue')?.at(-1)).toEqual([null]);
    });
});
