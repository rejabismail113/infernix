<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportNav from './Nav.vue';

interface Row { rule_id: number; entity_type: string; link_type: string; description: string; status: string; missing: { entity_id: number; label: string }[] }
defineProps<{ rows: Row[] }>();
</script>

<template>
    <AppLayout>
        <Head title="Missing required evidence" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Missing required evidence
            </h1>
        </template>
        <ReportNav />
        <div
            v-for="r in rows"
            :key="r.rule_id"
            class="mb-3 rounded-lg border border-surface-200 bg-white p-3 text-sm"
        >
            <div class="font-semibold">
                {{ r.description }}
                <span
                    class="ml-2 rounded px-2 py-0.5 text-xs font-normal"
                    :class="r.status === 'complete' ? 'bg-green-100 text-green-800' : r.status === 'missing' ? 'bg-red-100 text-red-800' : 'bg-surface-100 text-surface-500'"
                >{{ r.status === 'pending_module' ? 'module not installed yet' : r.status }}</span>
            </div>
            <p class="text-xs text-surface-500">
                {{ r.entity_type }} → needs a "{{ r.link_type }}" document
            </p>
            <ul
                v-if="r.missing.length"
                class="mt-1 list-inside list-disc"
            >
                <li
                    v-for="m in r.missing"
                    :key="m.entity_id"
                >
                    {{ m.label }} (#{{ m.entity_id }})
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
