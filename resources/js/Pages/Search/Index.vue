<script setup lang="ts">
/** Global search over redacted metadata; every hit was re-checked against its record policy on the server. */
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{ q: string; results: { type: string; type_label: string; id: number; title: string; module: string; url: string }[] }>();
const q = ref(props.q);
</script>

<template>
    <AppLayout
        title="Search"
        :breadcrumbs="[{ label: 'Search' }]"
    >
        <form
            class="mb-4 flex gap-2"
            role="search"
            @submit.prevent="router.get(route('search'), { q }, { preserveState: true })"
        >
            <input
                v-model="q"
                type="search"
                placeholder="Assets, vendors, activities, programmes, certifications, credentials…"
                aria-label="Search"
                class="flex-1 rounded-md border border-surface-300 px-3 py-2"
            >
            <Button type="submit">
                Search
            </Button>
        </form>
        <ul class="divide-y divide-surface-100 rounded-lg border border-surface-200 bg-white">
            <li
                v-for="r in results"
                :key="`${r.type}-${r.id}`"
                class="flex items-center gap-3 p-3 text-sm"
            >
                <span class="rounded bg-surface-100 px-1.5 py-0.5 text-xs text-surface-600">{{ r.type_label }}</span>
                <Link
                    :href="r.url"
                    class="font-medium text-brand-700 hover:underline"
                >
                    {{ r.title }}
                </Link>
                <span class="ml-auto text-xs text-surface-500">{{ r.module }}</span>
            </li>
            <li
                v-if="!results.length"
                class="p-6 text-center text-sm text-surface-500"
            >
                {{ props.q.length >= 2 ? 'Nothing found that you can see.' : 'Type at least two characters.' }}
            </li>
        </ul>
    </AppLayout>
</template>
