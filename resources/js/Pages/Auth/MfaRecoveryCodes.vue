<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

defineProps<{ codes: string[] }>();
</script>

<template>
    <GuestLayout>
        <Head title="Recovery codes" />
        <h1 class="mb-2 text-xl font-semibold">
            Save your recovery codes
        </h1>
        <p class="mb-4 text-sm text-surface-600">
            Each code can be used once if you lose your authenticator. They are shown only now.
        </p>
        <ul class="mb-6 grid grid-cols-2 gap-2 font-mono text-sm">
            <li
                v-for="c in codes"
                :key="c"
                class="rounded bg-surface-100 px-2 py-1 text-center"
            >
                {{ c }}
            </li>
        </ul>
        <Link
            :href="route('dashboard')"
            class="block w-full rounded-md bg-brand-600 px-4 py-2 text-center font-medium text-white hover:bg-brand-700"
        >
            Continue
        </Link>
    </GuestLayout>
</template>
