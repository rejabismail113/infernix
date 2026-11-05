/**
 * Money on the client is ALWAYS a decimal string (e.g. "1234.50") or integer minor units (123450).
 * Never a JS float. Mirrors App\Kernel\Money (CLAUDE.md rule 1).
 */
export type DecimalString = string;

const DECIMAL_RE = /^-?\d+(\.\d{1,4})?$/;

export function isDecimalString(value: unknown): value is DecimalString {
    return typeof value === 'string' && DECIMAL_RE.test(value.trim());
}

/** "1234.5" -> 123450 (minor units, integer arithmetic only). */
export function toMinorUnits(value: DecimalString): number {
    if (!isDecimalString(value)) throw new Error(`Invalid decimal string: ${value}`);
    const negative = value.trim().startsWith('-');
    const [whole, frac = ''] = value.trim().replace('-', '').split('.');
    const cents = (frac + '00').slice(0, 2);
    const minor = parseInt(whole, 10) * 100 + parseInt(cents, 10);
    return negative ? -minor : minor;
}

/** 123450 -> "1234.50" */
export function fromMinorUnits(minor: number): DecimalString {
    if (!Number.isInteger(minor)) throw new Error('Minor units must be an integer');
    const sign = minor < 0 ? '-' : '';
    const abs = Math.abs(minor);
    return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** Display formatting for MYR: "RM 1,234.50". Input is a decimal string or minor units. */
export function formatMyr(value: DecimalString | number): string {
    const minor = typeof value === 'number' ? value : toMinorUnits(value);
    const decimal = fromMinorUnits(minor);
    const [whole, frac] = decimal.replace('-', '').split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${minor < 0 ? '-' : ''}RM ${grouped}.${frac}`;
}
