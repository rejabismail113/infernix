/**
 * Audited reveal of a masked value (NRIC, bank account). The server requires a reason and a recent
 * re-authentication; if that is missing we send the user to confirm their identity and come back.
 * The value is shown once in the page and never stored client-side.
 */
export async function revealValue<T>(url: string, reason: string): Promise<T | null> {
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
    const res = await fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ reason }),
    });
    const type = res.headers.get('content-type') ?? '';
    if (res.ok && type.includes('application/json') && !res.url.includes('/reauth')) {
        return ((await res.json()) as { value: T }).value;
    }
    if (res.url.includes('/reauth') || res.redirected) {
        window.location.href = `/reauth?intended=${encodeURIComponent(window.location.href)}`;
        return null;
    }
    throw new Error(res.status === 403 ? 'You are not allowed to reveal this value.' : 'The value could not be revealed.');
}
