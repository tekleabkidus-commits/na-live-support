<script setup lang="ts">
// Doc 02 FR-16 — choose a new password on a live reset token. The designed
// screen (strength meter, §8 states, AC-09) is the views step's; this is the
// functional form, same precedent as S11's 2FA card.
import SetPasswordForm from './SetPasswordForm.vue';

defineProps<{
    token: string;
    minLength: number;
    copy: {
        title: string;
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
    <SetPasswordForm endpoint="/reset-password" :token="token" :copy="copy" />
</template>
