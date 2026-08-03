<script setup lang="ts">
// Doc 02 FR-16 — choose a new password on a live reset token, with §9's
// password field and text strength meter (step 13). Pixel design still awaits
// the approved screens (AC-09 PARTIAL); the functional and accessible surface
// is complete.
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
        showPassword: string;
        hidePassword: string;
        strengthLabel: string;
        strengthWeak: string;
        strengthFair: string;
        strengthStrong: string;
    };
}>();
</script>

<template>
    <SetPasswordForm endpoint="/reset-password" :token="token" :min-length="minLength" :copy="copy" />
</template>
