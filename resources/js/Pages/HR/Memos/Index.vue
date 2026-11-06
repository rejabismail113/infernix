<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ memos: { id: number; subject: string; delivered_at: string; read: boolean; number: string | null; type: string | null; status: string | null; pdf: string | null }[]; hasProfile: boolean }>();
const open = (m: { id: number; pdf: string | null; read: boolean }) => { if (!m.read) router.post(route('hr.memos.read', m.id), {}, { preserveScroll: true }); if (m.pdf) window.location.href = m.pdf; };
</script>

<template>
    <AppLayout
        title="My memos & letters"
        :breadcrumbs="[{ label: 'People' }, { label: 'My memos' }]"
    >
        <p
            v-if="!hasProfile"
            class="text-sm text-surface-500"
        >
            You do not have a staff profile yet.
        </p>
        <ul
            v-else
            class="divide-y divide-surface-100 rounded-lg border border-surface-200 bg-white"
        >
            <li
                v-for="m in memos"
                :key="m.id"
                class="flex items-center gap-3 p-4"
                :class="{ 'bg-brand-50/40': !m.read }"
            >
                <Icon
                    name="document"
                    :class="m.read ? 'text-surface-300' : 'text-brand-600'"
                />
                <div class="flex-1">
                    <button
                        type="button"
                        class="text-left font-medium hover:underline"
                        @click="open(m)"
                    >
                        {{ m.subject }}
                    </button>
                    <p class="text-xs text-surface-500">
                        {{ m.number }} · {{ m.delivered_at.slice(0, 10) }}
                    </p>
                </div>
                <StatusChip
                    v-if="m.status === 'void'"
                    status="void"
                />
            </li>
            <li
                v-if="!memos.length"
                class="p-6 text-center text-sm text-surface-500"
            >
                No memos.
            </li>
        </ul>
    </AppLayout>
</template>
