<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/FormField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps<{ token: string; email: string; name: string | null; role: string }>();
const form = useForm({ name: props.name ?? '', password: '', password_confirmation: '' });
</script>

<template>
    <GuestLayout>
        <Head title="Accept invitation" />
        <h1 class="mb-1 text-xl font-semibold">
            Welcome to Infernix Workspace
        </h1>
        <p class="mb-6 text-sm text-surface-500">
            {{ email }} · role: {{ role }}
        </p>
        <form
            class="space-y-4"
            @submit.prevent="form.post(route('invitations.store', { token }))"
        >
            <FormField
                id="name"
                label="Your name"
                :error="form.errors.name"
            >
                <input
                    id="name"
                    v-model="form.name"
                    required
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </FormField>
            <FormField
                id="password"
                label="Password (min 12 chars, letters + numbers)"
                :error="form.errors.password"
            >
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </FormField>
            <FormField
                id="password_confirmation"
                label="Confirm password"
            >
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </FormField>
            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Create account
            </button>
        </form>
    </GuestLayout>
</template>
