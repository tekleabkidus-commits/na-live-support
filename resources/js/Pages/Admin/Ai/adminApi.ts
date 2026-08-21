// Doc 09 FR-18 — the Backoffice pages' one fetch helper (CSRF-bearing JSON).
function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

export async function adminCall<T>(url: string, body?: unknown, method = 'POST'): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = (await response.json().catch(() => ({}))) as T & {
        detail?: string;
        errors?: Record<string, string[]>;
    };
    if (!response.ok) {
        const detail =
            json.detail ??
            Object.values(json.errors ?? {})
                .flat()
                .join(' ');
        throw new Error(detail || String(response.status));
    }
    return json;
}
