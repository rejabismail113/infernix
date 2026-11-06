import { describe, expect, it } from 'vitest';
import { translate } from './i18n';

describe('translate', () => {
    it('returns the BM string when present and falls back to the English key', () => {
        expect(translate({ 'Log out': 'Log keluar' }, 'Log out')).toBe('Log keluar');
        expect(translate({}, 'Assets')).toBe('Assets');
        expect(translate(undefined, 'Assets')).toBe('Assets');
    });
});
