<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/Button.vue';

const props = defineProps<{ from: string; to: string; routeName: string }>();
const f = reactive({ from: props.from, to: props.to });
const year = new Date().getFullYear();
const presets = [
    { label: 'This year', from: `${year}-01-01`, to: new Date().toISOString().slice(0, 10) },
    { label: 'Last year', from: `${year - 1}-01-01`, to: `${year - 1}-12-31` },
];
const go = (from: string, to: string) => router.get(route(props.routeName), { from, to }, { preserveScroll: true });
</script>

<template>
    <form
        class="flex flex-wrap items-end gap-2 text-sm"
        @submit.prevent="go(f.from, f.to)"
    >
        <label class="flex flex-col text-xs text-surface-500">From <input
            v-model="f.from"
            type="date"
            class="rounded-md border border-surface-300 px-2 py-1.5 text-sm text-surface-800"
        ></label>
        <label class="flex flex-col text-xs text-surface-500">To <input
            v-model="f.to"
            type="date"
            class="rounded-md border border-surface-300 px-2 py-1.5 text-sm text-surface-800"
        ></label>
        <Button type="submit">
            Apply
        </Button>
        <button
            v-for="p in presets"
            :key="p.label"
            type="button"
            class="rounded-md px-2 py-1.5 text-brand-700 hover:bg-brand-50"
            @click="go(p.from, p.to)"
        >
            {{ p.label }}
        </button>
    </form>
</template>
