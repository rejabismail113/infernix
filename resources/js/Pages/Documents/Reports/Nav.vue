<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <nav
        class="mb-4 flex gap-3 text-sm"
        aria-label="Document reports"
    >
        <Link
            :href="route('documents.reports.inventory')"
            class="text-brand-700 hover:underline"
        >
            Inventory
        </Link>
        <Link
            :href="route('documents.reports.recent-changes')"
            class="text-brand-700 hover:underline"
        >
            Recent changes
        </Link>
        <Link
            :href="route('documents.reports.missing-evidence')"
            class="text-brand-700 hover:underline"
        >
            Missing evidence
        </Link>
    </nav>
</template>
