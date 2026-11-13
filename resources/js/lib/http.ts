/** Requests that answer with JSON instead of a new page (autosave, uploads). Same security token as Inertia's. */
const token = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

const headers = () => ({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token() });

export async function sendJson<T = unknown>(
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    data?: unknown,
    keepalive = false,
): Promise<T | null> {
    const body = data === undefined ? undefined : JSON.stringify(data);
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { ...headers(), ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body,
        // Lets a last save finish while the tab closes (browsers allow up to 64 KB).
        keepalive: keepalive && (body?.length ?? 0) < 60_000,
    });
    if (!res.ok) throw new Error(String(res.status));
    return res.status === 204 ? null : ((await res.json()) as T);
}

export async function sendFiles<T = unknown>(url: string, files: File[], extra: Record<string, string> = {}): Promise<T> {
    const form = new FormData();
    files.forEach((f) => form.append('files[]', f));
    Object.entries(extra).forEach(([k, v]) => form.append(k, v));
    const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers(), body: form });
    if (!res.ok) {
        const err = (await res.json().catch(() => null)) as { message?: string } | null;
        throw new Error(err?.message ?? 'That file could not be added.');
    }
    return (await res.json()) as T;
}
