<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Flash from '@/Components/Flash.vue';
import FormField from '@/Components/FormField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const form = useForm({ email: '' });
</script>

<template>
    <GuestLayout>
        <Head title="Forgot password" />
        <h1 class="mb-6 text-xl font-semibold">
            Reset your password
        </h1>
        <Flash />
        <form
            class="space-y-4"
            @submit.prevent="form.post(route('password.email'))"
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
                    autofocus
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </FormField>
            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Send reset link
            </button>
            <Link
                :href="route('login')"
                class="block text-center text-sm text-surface-500 hover:underline"
            >
                Back to sign in
            </Link>
        </form>
    </GuestLayout>
</template>
