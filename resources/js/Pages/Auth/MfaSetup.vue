<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/FormField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps<{ qrSvg: string; secret: string; required: boolean }>();
const form = useForm({ code: '' });
const qrSrc = 'data:image/svg+xml;utf8,' + encodeURIComponent(props.qrSvg);
</script>

<template>
    <GuestLayout>
        <Head title="Set up two-factor authentication" />
        <h1 class="mb-2 text-xl font-semibold">
            Two-factor authentication
        </h1>
        <p class="mb-4 text-sm text-surface-600">
            <span v-if="required">Your role requires MFA. </span>Scan the QR code with an authenticator app, then enter the 6-digit code.
        </p>
        <img
            :src="qrSrc"
            alt="QR code for your authenticator app"
            width="200"
            height="200"
            class="mx-auto mb-4"
        >
        <p class="mb-4 break-all text-center font-mono text-xs text-surface-500">
            {{ secret }}
        </p>
        <form
            class="space-y-4"
            @submit.prevent="form.post(route('mfa.confirm'))"
        >
            <FormField
                id="code"
                label="Authentication code"
                :error="form.errors.code"
            >
                <input
                    id="code"
                    v-model="form.code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    required
                    autofocus
                    class="w-full rounded-md border border-surface-300 px-3 py-2 text-center tracking-widest"
                >
            </FormField>
            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Enable MFA
            </button>
        </form>
    </GuestLayout>
</template>
