export type Author = 'customer' | 'staff' | 'ai' | 'system';
export type DeliveryState = 'sending' | 'sent' | 'delivered';

export interface Message {
    id: string;
    created_at: string;
    direction: 'in' | 'out';
    author: Author;
    kind: string;
    body: string | null;
    media: Array<{
        kind: string | null;
        mime: string | null;
        bytes: number | null;
        scan: string | null;
        url: string | null;
    }>;
    delivery_state: string | null;
    external_message_id: string | null;
    occurred_at: string | null;
    survey: { token: string; scores: number[] } | null;
    /** client-side only: the visitor's own pending sends */
    local?: DeliveryState;
    client_id?: string;
}

export interface MessagesPage {
    data: Message[];
    next_cursor: string | null;
    conversation_id: string | null;
    envelope: {
        typing: boolean;
        queue: {
            band: string;
            degraded: boolean;
            position: number | null;
            opens_at: string | null;
        } | null;
        survey: { survey_id: string; expires_at: string } | null;
        blocked: boolean;
    };
}

export interface SessionResponse {
    visitor_id: string;
    token: string;
    expires_in: number;
}

export interface Shortcut {
    kind: 'article' | 'form' | 'chat' | 'url';
    label: Record<string, string>;
    target: string;
}

export interface ConfigEnvelope {
    v: number;
    widget_id: string;
    version: number | null;
    config: {
        appearance: {
            position: string;
            offset: { side: number; bottom: number };
            theme: 'light' | 'dark' | 'auto';
            font: string;
            launcher_icon: string;
            brand: unknown;
        };
        content: {
            welcome: Record<string, string>;
            shortcuts: Shortcut[];
            tabs: { home: boolean; help: boolean };
            default_language: string | null;
            pre_chat_mode: string;
        };
        behavior: { csat: boolean; sound: boolean | null; file_upload: boolean };
    };
    capabilities: {
        max_text: number;
        media: string[];
        typing: boolean;
        buttons: boolean;
        max_mb: number;
        voice: boolean;
    };
    transport: {
        channels: { thread: string; queue: string };
        polling_interval_seconds: number;
        config_poll_seconds: number;
        reverb: { key: string; host: string; port: number; scheme: string };
    };
    home: { band: string | null };
    hours: { open: boolean; opens_at: string | null; timezone: string };
    language: string;
    languages: string[];
    strings: Record<string, unknown>;
    settings: {
        agent_identity: string;
        cache_content: boolean;
        proactive_cooldown_hours: number;
        sound_default: boolean;
        transcript_mode: string;
    };
}

export interface HostFacts {
    origin: string;
    ancestors: string[];
    url: string;
    referrer: string;
    session: SessionResponse | null;
    viewport?: { width: number; height: number };
}
