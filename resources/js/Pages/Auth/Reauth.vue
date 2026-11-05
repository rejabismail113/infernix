<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/FormField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps<{ mfaEnabled: boolean; intended: string | null }>();
const form = useForm({ password: '', code: '', intended: props.intended ?? '' });
</script>

<template>
    <GuestLayout>
        <Head title="Confirm it's you" />
        <h1 class="mb-2 text-xl font-semibold">
            Confirm it's you
        </h1>
        <p class="mb-6 text-sm text-surface-600">
            This action is sensitive. Re-enter your password<span v-if="mfaEnabled"> or an authenticator code</span> to continue.
        </p>
        <form
            class="space-y-4"
            @submit.prevent="form.post(route('reauth.store'))"
        >
            <FormField
                id="password"
                label="Password"
                :error="form.errors.password"
            >
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </FormField>
            <FormField
                v-if="mfaEnabled"
                id="code"
                label="Or authenticator code"
                :error="form.errors.code"
            >
                <input
                    id="code"
                    v-model="form.code"
                    autocomplete="one-time-code"
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </FormField>
            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Confirm
            </button>
        </form>
    </GuestLayout>
</template>
