export type Author = 'customer' | 'agent' | 'ai' | 'system';
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
        behavior: {
            csat: boolean;
            sound: boolean | null;
            file_upload: boolean;
            proactive: ProactiveRule[];
            context_allowlist: string[];
        };
    };
    capabilities: {
        max_text: number;
        media: string[];
        typing: boolean;
        buttons: boolean;
        max_mb: number;
        voice: boolean;
        chatbot: boolean;
    };
    forms: {
        pre_chat: { version_id: string | null; mode: 'off' | 'optional' | 'required' };
        offline: { version_id: string | null };
    };
    visitor: { display: string | null };
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
    strings?: Record<string, Record<string, unknown>>;
}

export interface ProactiveRule {
    id: string;
    condition: {
        kind: 'url' | 'time' | 'exit' | 'capture';
        match?: string;
        seconds?: number;
        key?: string;
    };
    action: { kind: 'message' | 'open' | 'shortcuts'; message?: Record<string, string> };
}

export interface FormField {
    key: string;
    type: string;
    required?: boolean;
    label: Record<string, string>;
    placeholder?: Record<string, string>;
    options?: Array<{ value: string; label: Record<string, string> }>;
    visible_when?: { field: string; equals?: unknown };
}

export interface FormContract {
    form_id: string;
    version: number;
    schema: {
        languages?: string[];
        steps: Array<{ key: string; title?: Record<string, string>; fields: FormField[] }>;
    };
}

export interface HelpTree {
    categories: Array<{
        id: string;
        parent_id: string | null;
        name: Record<string, string>;
        slug: string;
        articles: Array<{
            article_id: string;
            variant_id: string;
            language: string;
            title: string;
            slug: string;
        }>;
    }>;
    start_chat: boolean;
}

export interface HelpArticle {
    article_id: string;
    variant_id: string;
    language: string;
    served_language_matches: boolean;
    title: string;
    summary: string | null;
    blocks: Array<{
        type: string;
        text?: string;
        items?: string[];
        url?: string;
        alt?: string;
        level?: number;
    }>;
    updated_at?: string;
    start_chat: boolean;
}
