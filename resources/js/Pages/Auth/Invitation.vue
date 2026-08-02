<script setup lang="ts">
// Doc 02 FR-17 — the invited person's first password. §8's profile-completion
// half (name, language, avatar) belongs to the designed views step; recorded
// in BUILD-STATUS, not silently dropped.
import SetPasswordForm from './SetPasswordForm.vue';

defineProps<{
    token: string;
    minLength: number;
    copy: {
        title: string;
        intro: string;
        passwordLabel: string;
        confirmLabel: string;
        submit: string;
        done: string;
        lengthHint: string;
        mismatch: string;
    };
}>();
</script>

<template>
    <SetPasswordForm endpoint="/invitation" :token="token" :copy="copy" />
</template>
