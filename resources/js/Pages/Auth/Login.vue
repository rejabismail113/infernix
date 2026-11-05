<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.post(route('login'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />
        <h1 class="mb-6 text-xl font-semibold">
            Sign in
        </h1>
        <form
            class="space-y-4"
            @submit.prevent="submit"
        >
            <div>
                <label
                    for="email"
                    class="mb-1 block text-sm font-medium"
                >Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    required
                    autofocus
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                    :aria-invalid="Boolean(form.errors.email)"
                    aria-describedby="email-error"
                >
                <p
                    v-if="form.errors.email"
                    id="email-error"
                    class="mt-1 text-sm text-red-600"
                    role="alert"
                >
                    {{ form.errors.email }}
                </p>
            </div>
            <div>
                <label
                    for="password"
                    class="mb-1 block text-sm font-medium"
                >Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                >
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="rounded border-surface-300 text-brand-600"
                >
                Remember me
            </label>
            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Log in
            </button>
        </form>
    </GuestLayout>
</template>
