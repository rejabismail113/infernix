/** Small helpers for Inertia forms used by schema-driven fields (no `any`). */
export function setField<T extends object>(form: T, key: string, value: unknown): void {
    (form as Record<string, unknown>)[key] = value;
}

/** Turn '' into null for optional numeric/id/date fields before posting. */
export function blanksToNull<T extends Record<string, unknown>>(data: T, keys: string[]): T {
    const out: Record<string, unknown> = { ...data };
    for (const k of keys) if (out[k] === '' || out[k] === undefined) out[k] = null;
    return out as T;
}

/** Options from a list of strings, prettifying snake_case. */
export const enumOptions = (values: readonly string[]): { value: string; label: string }[] =>
    values.map((v) => ({ value: v, label: v.replace(/_/g, ' ') }));
