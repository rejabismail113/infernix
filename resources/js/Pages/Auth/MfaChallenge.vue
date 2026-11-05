<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/FormField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const form = useForm({ code: '' });
</script>

<template>
    <GuestLayout>
        <Head title="Two-factor check" />
        <h1 class="mb-6 text-xl font-semibold">
            Enter your authentication code
        </h1>
        <form
            class="space-y-4"
            @submit.prevent="form.post(route('mfa.verify'))"
        >
            <FormField
                id="code"
                label="6-digit code or recovery code"
                :error="form.errors.code"
            >
                <input
                    id="code"
                    v-model="form.code"
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
                Verify
            </button>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="block w-full text-center text-sm text-surface-500 hover:underline"
            >
                Sign out
            </Link>
        </form>
    </GuestLayout>
</template>
