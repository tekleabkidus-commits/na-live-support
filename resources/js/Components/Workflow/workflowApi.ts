// Doc 12 §8 (S12 step 14) — the one fetch door every Workflow page posts through.
// RFC 9457 problem details come back as a refusal string (C-04); the caller
// renders it in place (B2-S06's inline refusal, role=alert), never a dialog.
export type Problem = {
    title?: string;
    detail?: string;
    code?: string;
    errors?: Record<string, string[]>;
};

export async function post<T = Record<string, unknown>>(
    url: string,
    body: Record<string, unknown>,
): Promise<
    { ok: true; data: T } | { ok: false; refusal: string; status: number; problem: Problem | null }
> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            ),
        },
        body: JSON.stringify(body),
    });
    if (response.ok) {
        return { ok: true, data: (await response.json()) as T };
    }
    let problem: Problem | null = null;
    try {
        problem = (await response.json()) as Problem;
    } catch {
        problem = null;
    }
    return {
        ok: false,
        refusal: problem?.detail ?? problem?.title ?? String(response.status),
        status: response.status,
        problem,
    };
}

export function tr(
    template: string | undefined,
    replacements: Record<string, string | number>,
): string {
    let value = template ?? '';
    for (const [token, replacement] of Object.entries(replacements)) {
        value = value.replaceAll(':' + token, String(replacement));
    }
    return value;
}

