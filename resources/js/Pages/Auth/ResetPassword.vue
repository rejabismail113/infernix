<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/FormField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps<{ token: string; email: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>

<template>
    <GuestLayout>
        <Head title="Choose a new password" />
        <h1 class="mb-6 text-xl font-semibold">
            Choose a new password
        </h1>
        <form
            class="space-y-4"
            @submit.prevent="form.post(route('password.update'))"
        >
            <FormField
                id="email"
                label="Email"
                :error="form.errors.email"
            >
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </FormField>
            <FormField
                id="password"
                label="New password"
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
                    required
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </FormField>
            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Update password
            </button>
        </form>
    </GuestLayout>
</template>
