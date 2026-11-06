/**
 * Exact decimal arithmetic for document line previews (BigInt fixed-point — never floats, SRS §6.3).
 * Mirrors App\Modules\Commercial\Services\DocumentMath: net = round(qty × price, 2) − discount;
 * tax = round(net × rate%, 2, half-up); total = net + tax. The server recomputes; this is only a preview.
 */
import type { DecimalString } from '@/lib/money';

function toScaled(value: string, scale: number): bigint {
    const v = (value ?? '').trim();
    if (!/^-?\d*(\.\d*)?$/.test(v) || v === '' || v === '-' || v === '.') return 0n;
    const negative = v.startsWith('-');
    const [int, frac = ''] = v.replace('-', '').split('.');
    const digits = (int || '0') + frac.padEnd(scale, '0').slice(0, scale);
    const n = BigInt(digits);
    return negative ? -n : n;
}

/** Round a non-negative scaled integer down by `factor` with half-up rounding. */
function divRound(n: bigint, factor: bigint): bigint {
    if (n < 0n) return -divRound(-n, factor);
    return (n + factor / 2n) / factor;
}

function toDecimal(cents: bigint): DecimalString {
    const negative = cents < 0n;
    const abs = negative ? -cents : cents;
    const s = abs.toString().padStart(3, '0');
    return `${negative ? '-' : ''}${s.slice(0, -2)}.${s.slice(-2)}` as DecimalString;
}

export interface LineAmounts { net: DecimalString; tax: DecimalString; total: DecimalString }

export function lineAmounts(quantity: string, unitPrice: string, discount: string, ratePercent: string): LineAmounts {
    const gross = divRound(toScaled(quantity, 4) * toScaled(unitPrice, 2), 10_000n); // cents
    let net = gross - toScaled(discount || '0', 2);
    if (net < 0n) net = 0n;
    const tax = divRound(net * toScaled(ratePercent || '0', 4), 1_000_000n); // cents × rate(1e4) / 100 / 1e4
    return { net: toDecimal(net), tax: toDecimal(tax), total: toDecimal(net + tax) };
}

export function sumDecimals(values: string[]): DecimalString {
    return toDecimal(values.reduce((acc, v) => acc + toScaled(v, 2), 0n));
}

/** Compare two decimal strings: -1, 0, 1. */
export function compareDecimals(a: string, b: string): number {
    const x = toScaled(a, 2);
    const y = toScaled(b, 2);
    return x === y ? 0 : x < y ? -1 : 1;
}

/** a − b as a decimal string. */
export function subtractDecimals(a: string, b: string): DecimalString {
    return toDecimal(toScaled(a, 2) - toScaled(b, 2));
}

/** Allocate `amount` to items oldest-first, never more than each item's outstanding. */
export function allocateOldestFirst(amount: string, outstanding: string[]): DecimalString[] {
    let left = toScaled(amount, 2);
    return outstanding.map((o) => {
        const cap = toScaled(o, 2);
        const take = left <= 0n ? 0n : left < cap ? left : cap;
        left -= take;
        return toDecimal(take);
    });
}
