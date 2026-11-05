<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ company: { name: string; tagline: string } }>();
</script>

<template>
    <AppLayout>
        <Head title="Dashboard" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Dashboard
            </h1>
        </template>
        <div class="rounded-xl border border-surface-200 bg-white p-8">
            <h2 class="text-2xl font-semibold">
                {{ company.name }}
            </h2>
            <p class="text-surface-500">
                {{ company.tagline }}
            </p>
            <p class="mt-6 text-sm text-surface-700">
                Bootstrap complete. Modules M01–M13 are scaffolded; business features arrive in the next build prompts.
            </p>
        </div>
    </AppLayout>
</template>
