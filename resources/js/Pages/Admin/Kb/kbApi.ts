// Doc 10 step 12 ([B9]) — the KB pages' one fetch helper (CSRF-bearing JSON), the
// Doc 09 adminApi shape verbatim: every mutation rides a proven door, never page logic.
function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

export type KbProblem = {
    detail?: string;
    errors?: Record<string, string[]>;
    missing_alt?: string[];
    missing_media?: string[];
    flags?: { block: number; sentence: string; pattern: string }[];
};

export class KbCallError extends Error {
    constructor(
        message: string,
        public readonly problem: KbProblem,
    ) {
        super(message);
    }
}

export async function kbCall<T>(url: string, body?: unknown, method = 'POST'): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = (await response.json().catch(() => ({}))) as T & KbProblem;
    if (!response.ok) {
        const detail =
            json.detail ??
            Object.values(json.errors ?? {})
                .flat()
                .join(' ');
        throw new KbCallError(detail || String(response.status), json);
    }
    return json;
}
