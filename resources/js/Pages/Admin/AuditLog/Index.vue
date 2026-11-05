<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Ev { id: number; occurred_at: string; actor: string | null; actor_role: string | null; action: string; entity_type: string; entity_id: number | null; before: Record<string, unknown> | null; after: Record<string, unknown> | null; redactions: string[] | null; ip: string | null; request_id: string | null; reason: string | null }
interface Paginated { data: Ev[]; links: { url: string | null; label: string; active: boolean }[]; total: number }
const props = defineProps<{ events: Paginated; filters: Record<string, string | null>; actions: string[]; can: { export: boolean } }>();
const f = reactive({ action: props.filters.action ?? '', entity_type: props.filters.entity_type ?? '', entity_id: props.filters.entity_id ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const apply = () => router.get(route('admin.audit-log.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')), { preserveState: true });
const label = (l: string) => l.replace('&laquo;', '«').replace('&raquo;', '»');
const exportUrl = () => route('admin.audit-log.export', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')));
</script>

<template>
    <AppLayout>
        <Head title="Audit log" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Audit log <span class="text-sm font-normal text-surface-500">({{ events.total }})</span>
            </h1>
        </template>
        <form
            class="mb-4 flex flex-wrap gap-2 text-sm"
            @submit.prevent="apply"
        >
            <select
                v-model="f.action"
                aria-label="Action"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option value="">
                    any action
                </option>
                <option
                    v-for="a in actions"
                    :key="a"
                >
                    {{ a }}
                </option>
            </select>
            <input
                v-model="f.entity_type"
                placeholder="entity type"
                aria-label="Entity type"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="f.entity_id"
                placeholder="entity id"
                aria-label="Entity id"
                class="w-24 rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="f.from"
                type="date"
                aria-label="From"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="f.to"
                type="date"
                aria-label="To"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <button
                type="submit"
                class="rounded-md bg-brand-600 px-3 py-2 text-white"
            >
                Filter
            </button>
            <a
                v-if="can.export"
                :href="exportUrl()"
                class="rounded-md border border-surface-300 px-3 py-2 hover:bg-surface-100"
            >Export CSV (audited)</a>
        </form>
        <div class="overflow-x-auto rounded-lg border border-surface-200 bg-white">
            <table class="min-w-full text-xs">
                <thead class="bg-surface-100 text-left uppercase text-surface-500">
                    <tr>
                        <th class="px-2 py-2">
                            When
                        </th><th class="px-2 py-2">
                            Actor
                        </th><th class="px-2 py-2">
                            Action
                        </th><th class="px-2 py-2">
                            Entity
                        </th><th class="px-2 py-2">
                            Before → after
                        </th><th class="px-2 py-2">
                            Reason
                        </th><th class="px-2 py-2">
                            IP / request
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="e in events.data"
                        :key="e.id"
                        class="border-t border-surface-100 align-top"
                    >
                        <td class="whitespace-nowrap px-2 py-1">
                            {{ e.occurred_at.replace('T', ' ').slice(0, 19) }}
                        </td>
                        <td class="px-2 py-1">
                            {{ e.actor ?? 'system' }}<br><span class="text-surface-400">{{ e.actor_role }}</span>
                        </td>
                        <td class="px-2 py-1 font-medium">
                            {{ e.action }}
                        </td>
                        <td class="px-2 py-1">
                            {{ e.entity_type }}<span v-if="e.entity_id">#{{ e.entity_id }}</span>
                        </td>
                        <td class="max-w-lg px-2 py-1 font-mono">
                            <div
                                v-if="e.before"
                                class="text-red-700"
                            >
                                {{ JSON.stringify(e.before) }}
                            </div>
                            <div
                                v-if="e.after"
                                class="text-green-700"
                            >
                                {{ JSON.stringify(e.after) }}
                            </div>
                            <div
                                v-if="e.redactions?.length"
                                class="text-surface-400"
                            >
                                redacted: {{ e.redactions.join(', ') }}
                            </div>
                        </td>
                        <td class="px-2 py-1">
                            {{ e.reason }}
                        </td>
                        <td class="px-2 py-1 text-surface-400">
                            {{ e.ip }}<br>{{ e.request_id?.slice(0, 8) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav
            class="mt-3 flex flex-wrap gap-1 text-sm"
            aria-label="Pagination"
        >
            <template
                v-for="l in events.links"
                :key="l.label"
            >
                <Link
                    v-if="l.url"
                    :href="l.url"
                    class="rounded border px-2 py-1"
                    :class="l.active ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-surface-200'"
                >
                    {{ label(l.label) }}
                </Link>
            </template>
        </nav>
    </AppLayout>
</template>
