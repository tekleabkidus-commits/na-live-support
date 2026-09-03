<script setup lang="ts">
// Doc 10 FR-16/SS-04 — the citation landing. SESSION-F1 [B9] rebuilt the STAFF landing to the
// frozen B9-S03 §C: the state chip (Current · Superseded · Retired · Unknown), the version-
// pinned snapshot exactly as the customer read it (FR-14 — forensics), what changed since
// (the same diff summary the history uses, with the compare link), one primary to the
// current version (or the archived record), and the resolver-states rail with the no-404
// law (AC-06). The LABELED-DEGRADE pattern (mandate §5): a once-cited URL always answers a
// STATE, never a 404 and never a silent empty. The widget Help-panel tombstones are Doc 13's
// (recorded out of scope). Tokens only; no failure vocabulary.
import { computed } from 'vue';
import BackofficeShell from '@/Components/Backoffice/BackofficeShell.vue';

type Snapshot = {
    version: number;
    language: string;
    title: string;
    audience: string;
    published_at: string;
    author_name: string | null;
    blocks: { type: string; text: string }[];
    variant_id: string;
    variant_status: string | null;
    archived_at: string | null;
    current_version: number | null;
    current_version_id: string | null;
    changes: { added: number; removed: number; changed: number; unchanged: number } | null;
    category_name: string | null;
};
const props = defineProps<{
    t: Record<string, string>;
    state: string;
    article_id: string | null;
    version_id: string | null;
    shown: { title?: string; language?: string; version?: number; published_at?: string } | null;
    current_url: string | null;
    current_version_id: string | null;
    tombstone: string;
    snapshot: Snapshot | null;
}>();

function fill(template: string | undefined, vars: Record<string, string | number>): string {
    let out = template ?? '';
    for (const [k, v] of Object.entries(vars)) out = out.split(':' + k).join(String(v));
    return out;
}
const hhmm = (iso: string | null | undefined): string =>
    iso ? iso.replace('T', ' ').slice(0, 16) : '';
// Four resolver states, one word each: current · superseded (a newer version serves) · retired · unknown.
const resolved = computed<'current' | 'superseded' | 'retired' | 'unknown'>(() => {
    if (props.state === 'current') return 'current';
    if (props.state === 'unknown' || !props.shown) return 'unknown';
    return props.current_url && props.current_version_id ? 'superseded' : 'retired';
});
const stateKey = computed(
    () =>
        ({
            current: 'cite_current',
            superseded: 'cite_retired',
            retired: 'cite_retired',
            unknown: 'cite_unknown',
        })[resolved.value],
);
const chipClass = computed(
    () => ({ current: 'ok', superseded: 'warn', retired: '', unknown: '' })[resolved.value],
);
const ticket = new URLSearchParams(window.location.search).get('ticket');
const title = computed(() =>
    ticket ? fill(props.t.citation_from_ticket, { n: ticket }) : (props.t.citation_title ?? ''),
);
const typeWord = (type: string): string =>
    (type === 'also_asked' ? props.t.also_asked : props.t['block_' + type]) ?? type;
</script>

<template>
    <BackofficeShell
        :t="t"
        active="kb"
        :title="title"
        :crumb="
            ticket
                ? [
                      { label: t.tickets_word ?? '', href: '/inbox' },
                      { label: '#' + ticket },
                      { label: t.ai_answer_word ?? '' },
                      { label: t.citation_word ?? '' },
                  ]
                : [{ label: t.nav_kb ?? '', href: '/admin/kb' }, { label: t.citation_word ?? '' }]
        "
    >
        <template #sub>
            <span class="bo-chip" :class="chipClass" data-testid="kb-tombstone-chip"
                >{{ t['state_' + resolved]
                }}<template v-if="resolved === 'superseded' && shown?.version">
                    · {{ fill(t.v_was_cited, { v: shown.version }) }}</template
                ></span
            >
            <span v-if="shown" class="bo-stamp"
                >{{ t.cited_word }} <span class="bo-mono">{{ shown.language }}</span> v{{
                    shown.version
                }}
                · {{ hhmm(shown.published_at) }} · {{ t.resolver_word }}:
                <span class="bo-mono">{{ t.resolver_name }}</span></span
            >
        </template>
        <template #actions>
            <a
                v-if="resolved === 'current' || resolved === 'superseded'"
                class="bo-btn p"
                :href="current_url ?? '#'"
                data-testid="kb-tombstone-current-link"
                >{{
                    resolved === 'current'
                        ? t.cite_view_current
                        : fill(t.open_current_v, { v: snapshot?.current_version ?? '' })
                }}</a
            >
            <a
                v-else-if="resolved === 'retired' && snapshot"
                class="bo-btn"
                :href="'/admin/kb/variants/' + snapshot.variant_id + '/history-page'"
                data-testid="kb-tombstone-archived-link"
                >{{ t.open_archived_record }}</a
            >
            <a v-else class="bo-btn" href="/admin/kb">{{ t.browse_kb }}</a>
        </template>

        <div class="kb-cite">
            <div class="kb-cite-main">
                <p data-testid="kb-tombstone-state" class="bo-banner" role="status">
                    ⓘ {{ t[stateKey] ?? tombstone
                    }}<template v-if="resolved === 'superseded' || resolved === 'retired'">
                        {{ t.snapshot_pinned }}</template
                    >
                </p>
                <h2 v-if="shown?.title" class="sr-only" data-testid="kb-tombstone-title">
                    {{ shown.title }}
                </h2>
                <div v-if="snapshot" class="bo-card">
                    <div class="ct">{{ t.what_customer_read }}</div>
                    <div class="cb">
                        <div class="kb-snap" data-testid="kb-tombstone-snapshot">
                            <div class="lab">
                                v{{ snapshot.version }} ·
                                {{
                                    fill(t.published_by, {
                                        time: hhmm(snapshot.published_at),
                                        name: snapshot.author_name ?? '—',
                                    })
                                }}
                                · {{ snapshot.language }} · {{ t['audience_' + snapshot.audience] }}
                            </div>
                            <h3 class="kb-snap-title">{{ snapshot.title }}</h3>
                            <div
                                v-for="(b, i) in snapshot.blocks"
                                :key="i"
                                class="kb-snap-block"
                                :class="b.type"
                            >
                                <span class="sr-only">{{ typeWord(b.type) }}: </span>{{ b.text }}
                            </div>
                        </div>
                        <p
                            v-if="snapshot.changes && snapshot.current_version"
                            class="bo-hint"
                            data-testid="kb-tombstone-changes"
                        >
                            {{ t.what_changed_since }}
                            <a
                                class="bo-link"
                                :href="
                                    '/admin/kb/variants/' + snapshot.variant_id + '/history-page'
                                "
                                >{{
                                    fill(t.compare_v_to_v, {
                                        from: snapshot.version,
                                        to: snapshot.current_version,
                                    })
                                }}</a
                            >
                            ({{
                                fill(t.changes_summary, {
                                    changed: snapshot.changes.changed,
                                    added: snapshot.changes.added,
                                    removed: snapshot.changes.removed,
                                })
                            }})
                        </p>
                        <p v-else-if="resolved === 'retired'" class="bo-hint">
                            {{
                                snapshot.archived_at
                                    ? fill(t.archived_no_current, {
                                          time: hhmm(snapshot.archived_at),
                                      })
                                    : t.retired_no_current
                            }}
                        </p>
                    </div>
                </div>
                <div v-else-if="shown" class="bo-card">
                    <div class="ct">{{ t.what_customer_read }}</div>
                    <div class="cb">
                        <h3 class="kb-snap-title">{{ shown.title }}</h3>
                        <p class="bo-hint">{{ t.snapshot_title_only }}</p>
                    </div>
                </div>
                <p v-if="current_url && resolved !== 'current'" class="bo-hint">
                    <a :href="current_url" class="bo-link">{{ t.cite_view_current }}</a>
                </p>
            </div>
            <aside class="bo-rail">
                <div class="bo-card" data-testid="kb-resolver-states">
                    <div class="ct">{{ t.resolver_states }}</div>
                    <div class="cb">
                        <dl class="bo-kv2">
                            <dt>{{ t.state_current }}</dt>
                            <dd>{{ t.resolver_current }}</dd>
                            <dt>{{ t.state_superseded }}</dt>
                            <dd>{{ t.resolver_superseded }}</dd>
                            <dt>{{ t.state_retired }}</dt>
                            <dd>{{ t.resolver_retired }}</dd>
                            <dt>{{ t.state_unknown }}</dt>
                            <dd>{{ t.resolver_unknown }}</dd>
                            <dt>{{ t.old_slug_word }}</dt>
                            <dd>{{ t.resolver_old_slug }}</dd>
                        </dl>
                        <p class="bo-hint">{{ t.never_404 }}</p>
                    </div>
                </div>
            </aside>
        </div>
    </BackofficeShell>
</template>

<style>
.kb-cite {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 16px;
    padding: 12px 18px 24px;
    align-items: start;
}
.kb-cite-main {
    display: grid;
    gap: 12px;
    min-width: 0;
}
.kb-cite-main .bo-banner {
    margin: 0;
}
.kb-snap {
    border: 1px dashed var(--ds-border-strong);
    border-radius: 8px;
    padding: 12px 14px;
    background: var(--ds-panel-soft);
}
.kb-snap .lab {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--ds-text-label);
    margin-bottom: 6px;
}
.kb-snap-title {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 8px;
    line-height: 1.35;
    overflow-wrap: anywhere;
}
.kb-snap-block {
    font-size: 13px;
    line-height: 1.6;
    margin: 4px 0;
    overflow-wrap: anywhere;
}
.kb-snap-block.heading {
    font-weight: 600;
    margin-top: 8px;
}
.kb-snap-block.callout {
    border-inline-start: 3px solid var(--ds-warn-border);
    background: var(--ds-warn-bg);
    padding: 6px 8px;
    border-radius: 0 6px 6px 0;
}
@media (max-width: 639px) {
    .kb-cite {
        grid-template-columns: 1fr;
        padding: 10px 12px;
    }
}
</style>
