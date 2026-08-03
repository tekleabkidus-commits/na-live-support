<script setup lang="ts">
// Doc 02 FR-17 — the invited person's first password, doubling as §8's
// profile completion (name, language) since step 13. The avatar half is
// deferred to the media pipeline (Docs 04/08) and recorded in BUILD-STATUS.
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
        showPassword: string;
        hidePassword: string;
        strengthLabel: string;
        strengthWeak: string;
        strengthFair: string;
        strengthStrong: string;
    };
    profile: {
        nameLabel: string;
        languageLabel: string;
        langEnglish: string;
        langAmharic: string;
        name: string;
        locale: string;
    };
}>();
</script>

<template>
    <SetPasswordForm
        endpoint="/invitation"
        :token="token"
        :min-length="minLength"
        :copy="copy"
        :profile="profile"
    />
</template>
