/** Read a validation error by any key (e.g. "general", "lines.0.amount") from an Inertia form's errors. */
export function fieldError(errors: object, key: string): string | undefined {
    return (errors as Record<string, string | undefined>)[key];
}
