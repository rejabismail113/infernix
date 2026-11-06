import { describe, expect, it } from 'vitest';
import { blanksToNull, enumOptions, setField } from './forms';

describe('forms helpers', () => {
    it('sets a field on a form-like object', () => {
        const f: { a: string } = { a: '' };
        setField(f, 'a', 'x');
        expect(f.a).toBe('x');
    });
    it('turns blanks into null for the given keys only', () => {
        expect(blanksToNull({ a: '', b: '', c: 1 }, ['a'])).toEqual({ a: null, b: '', c: 1 });
    });
    it('prettifies enum labels', () => {
        expect(enumOptions(['site_visit'])).toEqual([{ value: 'site_visit', label: 'site visit' }]);
    });
});
