// Doc 09 FR-14/FR-15 — the Copilot's client door (S09 step 12, Option A /
// [B8]). One fetch helper, CSRF-bearing; the card arrives by POLLING the
// request door (T-04's socket client is Doc 07's Reverb seam — when it lands,
// the same card shape arrives as ai.copilot.card and polling stops early).

export type CopilotCard = {
    request_id?: string;
    state:
        | 'pending'
        | 'artifact'
        | 'low_confidence'
        | 'locked'
        | 'cooldown'
        | 'refused'
        | 'degraded'
        | 'busy';
    kind?: string;
    artifact_id?: string;
    text?: string;
    citations?: string[];
    confidence?: number;
    notices?: string[];
    kb?: { article_ref: string; excerpt: string; url?: string; title?: string; version_id?: string }[];
    regenerations?: { used: number; limit: number };
    reason?: string;
    source_language?: string;
    target_language?: string;
};

export type CopilotPanel = {
    chips: {
        intent: string | null;
        sentiment: number | null;
        urgency: number | null;
        confidence: number | null;
    } | null;
    summary: string | null;
    summary_state: 'refreshing' | 'fresh' | 'empty';
    kb: { article_ref: string; excerpt: string; url?: string; title?: string; version_id?: string }[];
    similar_tickets: {
        ticket_id: string;
        number: number | null;
        subject: string | null;
        summary: string;
        score: number;
    }[];
    notices: string[];
    macros: { seam: string; items: unknown[] };
};

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

async function call<T>(url: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
            ...(init.headers ?? {}),
        },
    });
    if (!response.ok) throw new Error(String(response.status));
    return (await response.json()) as T;
}

export const copilotApi = {
    panel: (conversationId: string) =>
        call<CopilotPanel>(`/inbox/ai/copilot/${conversationId}/panel`),
    draft: (conversationId: string) =>
        call<{ request_id: string }>(`/inbox/ai/copilot/${conversationId}/draft`, {
            method: 'POST',
        }),
    action: (
        conversationId: string,
        action: 'improve' | 'translate' | 'summarize',
        payload: Record<string, string>,
    ) =>
        call<{ request_id: string }>(`/inbox/ai/copilot/${conversationId}/actions/${action}`, {
            method: 'POST',
            body: JSON.stringify(payload),
        }),
    request: (conversationId: string, requestId: string) =>
        call<CopilotCard>(`/inbox/ai/copilot/${conversationId}/requests/${requestId}`),
    insert: (artifactId: string) =>
        call<{ artifact_id: string; kind: string; text: string }>(
            `/inbox/ai/copilot/artifacts/${artifactId}/insert`,
            {
                method: 'POST',
            },
        ),
    discard: (artifactId: string) =>
        call<{ artifact_id: string }>(`/inbox/ai/copilot/artifacts/${artifactId}/discard`, {
            method: 'POST',
        }),
    sent: (artifactId: string, messageId: string) =>
        call<{ outcome: string }>(`/inbox/ai/copilot/artifacts/${artifactId}/sent`, {
            method: 'POST',
            body: JSON.stringify({ message_id: messageId }),
        }),
};

/**
 * §8: "never an empty spinner forever — 10 s timeout to the degraded card".
 * Polls the request door every 800 ms until a non-pending card or the
 * deadline; the deadline yields a client-side degraded card.
 */
export async function awaitCard(
    conversationId: string,
    requestId: string,
    timeoutMs = 10_000,
    intervalMs = 800,
): Promise<CopilotCard> {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        try {
            const card = await copilotApi.request(conversationId, requestId);
            if (card.state !== 'pending') return card;
        } catch {
            // a transient fetch failure keeps polling until the deadline
        }
        await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
    return {
        request_id: requestId,
        state: 'degraded',
        reason: 'timeout',
        notices: ['ai.copilot.notice.degraded'],
    };
}
