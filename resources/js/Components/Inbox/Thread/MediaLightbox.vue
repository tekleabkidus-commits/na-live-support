<script setup lang="ts">
// B12-S01 — the media lightbox: photo at rest WITH its description (the
// §13 alt slot), video without one, loading-then-failed, and INFECTED —
// which gets NO download control and NO description (SEC-04 as an absence,
// the frame's own bar). Focus is trapped while open; Esc closes.
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
    media: { url?: string; kind?: string; alt_text?: string | null; scan_status?: string };
    t: Record<string, string>;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const failed = ref(false);
const loading = ref(true);

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));

const infected = (): boolean => props.media.scan_status === 'infected';
</script>

<template>
    <div
        data-testid="media-lightbox"
        role="dialog"
        aria-modal="true"
        :aria-label="t.photo"
        class="fixed inset-0 z-50 flex flex-col items-center justify-center bg-black/80 p-6"
        @click.self="emit('close')"
    >
        <button
            type="button"
            data-testid="lightbox-close"
            class="absolute end-4 top-4 rounded px-2 py-1 text-sm text-white"
            :aria-label="t.lightbox_close"
            @click="emit('close')"
        >
            ✕
        </button>

        <!-- INFECTED (SEC-04 absence): the block notice — no media element,
             no download control, no description. -->
        <template v-if="infected()">
            <p data-testid="lightbox-infected" class="max-w-md text-center text-sm text-white">
                {{ t.media_infected }}
            </p>
        </template>

        <template v-else>
            <img
                v-if="media.kind === 'photo' && !failed"
                data-testid="lightbox-photo"
                :src="media.url"
                :alt="media.alt_text ?? ''"
                class="max-h-[80vh] max-w-full rounded"
                @load="loading = false"
                @error="
                    failed = true;
                    loading = false;
                "
            />
            <video
                v-else-if="media.kind === 'video' && !failed"
                data-testid="lightbox-video"
                :src="media.url"
                controls
                class="max-h-[80vh] max-w-full rounded"
                @error="failed = true"
            ></video>

            <p v-if="failed" data-testid="lightbox-failed" class="text-sm text-white">
                {{ t.media_failed }}
            </p>

            <!-- Description renders only when the alt slot is filled. -->
            <p
                v-if="!failed && media.alt_text"
                data-testid="lightbox-description"
                class="mt-3 max-w-md text-center text-xs text-white/80"
            >
                {{ media.alt_text }}
            </p>

            <a
                v-if="!failed && media.url"
                data-testid="lightbox-download"
                :href="media.url"
                download
                class="mt-3 rounded border border-white/40 px-2 py-1 text-xs text-white"
            >
                {{ t.lightbox_download }}
            </a>
        </template>
    </div>
</template>
