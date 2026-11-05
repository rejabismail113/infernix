<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const tabs = [
    { label: 'Inventory', name: 'documents.reports.inventory' },
    { label: 'Recent changes', name: 'documents.reports.recent-changes' },
    { label: 'Missing evidence', name: 'documents.reports.missing-evidence' },
];
const isActive = (name: string) => page.url.split('?')[0] === new URL(route(name)).pathname;
</script>

<template>
    <nav
        class="flex flex-wrap gap-1 rounded-lg bg-white p-1 ring-1 ring-surface-200"
        aria-label="Document reports"
    >
        <Link
            v-for="t in tabs"
            :key="t.name"
            :href="route(t.name)"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="isActive(t.name) ? 'bg-brand-50 text-brand-700' : 'text-surface-600 hover:bg-surface-100'"
            :aria-current="isActive(t.name) ? 'page' : undefined"
        >
            {{ t.label }}
        </Link>
    </nav>
</template>
