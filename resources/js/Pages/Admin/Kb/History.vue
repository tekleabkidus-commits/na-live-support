<script setup lang="ts">
// Doc 10 FR-08/US-02 (S10 step 12 → [B9]): version history — immutable snapshots newest
// first, the block-aware diff between a version and its predecessor, and restore-as-new-
// draft (history and the published pointer untouched — the door enforces it, AC-07).
import { ref } from 'vue';
import { kbCall } from './kbApi';

type Version = {
    id: string;
    version: number;
    author_id: string;
    note: string | null;
    content_hash: string;
    published_at: string;
};
type Diff = {
    from: number;
    to: number;
    summary: { added: number; removed: number; changed: number; unchanged: number };
    entries: { kind: string; key: string }[];
};
const props = defineProps<{
    t: Record<string, string>;
    variant: { id: string; language: string; title: string; current_version_id: string | null };
    versions: Version[];
}>();

const diff = ref<Record<string, Diff>>({});
const message = ref('');

async function loadDiff(v: Version): Promise<void> {
    // The block-aware diff reads OLDER → THIS version (versions arrive newest first).
    const index = props.versions.findIndex((x) => x.id === v.id);
    const older = props.versions[index + 1];
    if (!older) {
        return; // v1 has no predecessor to diff against
    }
    try {
        diff.value[v.id] = await kbCall<Diff>(
            '/admin/kb/versions/' + older.id + '/diff?to=' + v.id,
            undefined,
            'GET',
        );
    } catch (e) {
        message.value = (e as Error).message;
    }
}
async function restore(versionId: string): Promise<void> {
    try {
        await kbCall('/admin/kb/versions/' + versionId + '/restore', {});
        message.value = props.t.restored ?? '';
    } catch (e) {
        message.value = (e as Error).message;
    }
}
</script>

<template>
    <main
        class="mx-auto max-w-3xl space-y-3 p-4 text-start text-sm"
        :style="{ color: 'var(--ds-text-primary)', background: 'var(--ds-page-bg)' }"
    >
        <h1 class="text-lg font-semibold">
            {{ t.versions_title }} — {{ variant.title }} ({{ variant.language }})
        </h1>
        <p
            v-if="message"
            role="status"
            data-testid="kb-history-message"
            class="rounded border px-2 py-1 text-xs"
            :style="{ borderColor: 'var(--ds-border-strong)' }"
        >
            {{ message }}
        </p>
        <ol class="space-y-2" data-testid="kb-versions">
            <li
                v-for="v in versions"
                :key="v.id"
                class="rounded border p-2 text-xs"
                :style="{
                    borderColor:
                        v.id === variant.current_version_id
                            ? 'var(--ds-accent)'
                            : 'var(--ds-border-strong)',
                    background: 'var(--ds-panel-bg)',
                }"
            >
                <p>
                    <span class="font-semibold">{{ t.version }} {{ v.version }}</span>
                    · {{ v.published_at }}
                    <span v-if="v.note"> · {{ v.note }}</span>
                </p>
                <p class="mt-1 flex gap-2">
                    <button
                        type="button"
                        :data-testid="'kb-diff-' + v.version"
                        class="rounded border px-2 py-0.5"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        @click="loadDiff(v)"
                    >
                        {{ t.diff }}
                    </button>
                    <button
                        type="button"
                        :data-testid="'kb-restore-' + v.version"
                        class="rounded border px-2 py-0.5"
                        :style="{ borderColor: 'var(--ds-border-strong)' }"
                        @click="restore(v.id)"
                    >
                        {{ t.restore }}
                    </button>
                </p>
                <template
                    v-for="d in diff[v.id] ? [diff[v.id]!] : []"
                    :key="'diff-' + v.id + '-' + d.to"
                >
                    <div class="mt-1 space-y-0.5" :data-testid="'kb-diff-view-' + v.version">
                        <p>
                            v{{ d.from }} → v{{ d.to }} — {{ t.diff_added }} {{ d.summary.added }} ·
                            {{ t.diff_removed }} {{ d.summary.removed }} · {{ t.diff_changed }}
                            {{ d.summary.changed }}
                        </p>
                        <ul class="list-disc ps-4" :style="{ color: 'var(--ds-text-muted)' }">
                            <li v-for="e in d.entries" :key="e.kind + e.key">
                                {{ e.kind }}: {{ e.key }}
                            </li>
                        </ul>
                    </div>
                </template>
            </li>
        </ol>
    </main>
</template>
