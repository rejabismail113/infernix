import { describe, expect, it } from 'vitest';
import { formatMyr, fromMinorUnits, isDecimalString, toMinorUnits } from './money';

describe('money (G-12, rule 1: never a float)', () => {
    it('round-trips decimal strings and minor units without floating point error', () => {
        expect(toMinorUnits('0.10')).toBe(10);
        expect(toMinorUnits('0.1')).toBe(10);
        expect(toMinorUnits('1234.5')).toBe(123450);
        expect(toMinorUnits('-7.05')).toBe(-705);
        expect(fromMinorUnits(123450)).toBe('1234.50');
        expect(fromMinorUnits(-705)).toBe('-7.05');
        // classic float trap: 0.1 + 0.2
        expect(toMinorUnits('0.10') + toMinorUnits('0.20')).toBe(30);
    });

    it('formats MYR with thousands grouping', () => {
        expect(formatMyr('10000')).toBe('RM 10,000.00');
        expect(formatMyr(700000)).toBe('RM 7,000.00');
        expect(formatMyr('-12.3')).toBe('-RM 12.30');
    });

    it('rejects non-decimal input', () => {
        expect(isDecimalString('1e3')).toBe(false);
        expect(isDecimalString('abc')).toBe(false);
        expect(() => toMinorUnits('1,000')).toThrow();
        expect(() => fromMinorUnits(1.5)).toThrow();
    });
});
