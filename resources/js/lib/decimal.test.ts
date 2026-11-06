import { describe, expect, it } from 'vitest';
import { lineAmounts, sumDecimals } from './decimal';

describe('lineAmounts (mirrors DocumentMath)', () => {
    it('multiplies, discounts and rounds half-up without floats', () => {
        expect(lineAmounts('3', '4500.00', '500.00', '0')).toEqual({ net: '13000.00', tax: '0.00', total: '13000.00' });
        expect(lineAmounts('0.3333', '10.00', '', '0').net).toBe('3.33');
        expect(lineAmounts('1', '0.10', '', '0').net).toBe('0.10');
        expect(lineAmounts('3', '0.10', '', '0').net).toBe('0.30'); // 0.1 × 3 is 0.30000000000000004 in floats
        expect(lineAmounts('1', '100.05', '', '8').tax).toBe('8.00'); // 8.004 → 8.00
        expect(lineAmounts('1', '100.07', '', '8').tax).toBe('8.01'); // 8.0056 → 8.01
        expect(lineAmounts('1', '10.00', '20.00', '0').net).toBe('0.00'); // discount never makes a line negative
    });

    it('sums decimal strings exactly', () => {
        expect(sumDecimals(['0.10', '0.20', '13000.00'])).toBe('13000.30');
        expect(sumDecimals([])).toBe('0.00');
    });
});

describe('allocation helpers', () => {
    it('allocates oldest first and never beyond outstanding', async () => {
        const { allocateOldestFirst, compareDecimals, subtractDecimals } = await import('./decimal');
        expect(allocateOldestFirst('3000.00', ['1000.00', '5000.00', '10.00'])).toEqual(['1000.00', '2000.00', '0.00']);
        expect(allocateOldestFirst('99999.00', ['0.10', '0.20'])).toEqual(['0.10', '0.20']);
        expect(compareDecimals('10.00', '9.99')).toBe(1);
        expect(subtractDecimals('10000.00', '3000.00')).toBe('7000.00');
    });
});
