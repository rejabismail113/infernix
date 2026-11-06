<script setup lang="ts">
/** Joiner / mover / leaver access checklists (M13-04). Leaver entitlements are revoked automatically. */
import { router } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ checklists: { id: number; kind: string; person: string | null; trigger: string; items: { key: string; label: string; done: boolean; auto?: boolean; done_at?: string | null }[]; status: string; created_at: string | null; completed_at: string | null }[]; can: { edit: boolean } }>();
const tick = (id: number, key: string) => router.post(route('credentials.jml.tick', id), { key }, { preserveScroll: true });
</script>

<template>
    <AppLayout
        title="Joiner / mover / leaver"
        :breadcrumbs="[{ label: 'Security' }, { label: 'Access checklists' }]"
    >
        <div class="space-y-3">
            <Card
                v-for="c in checklists"
                :key="c.id"
                :title="`${c.kind}: ${c.person ?? ''}`"
                :description="`${c.trigger} · opened ${c.created_at ?? ''}`"
            >
                <template #actions>
                    <StatusChip :status="c.status === 'completed' ? 'complete' : 'open'" />
                </template>
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="i in c.items"
                        :key="i.key"
                        class="flex items-center gap-2"
                    >
                        <input
                            type="checkbox"
                            :checked="i.done"
                            :disabled="i.done || !can.edit"
                            :aria-label="i.label"
                            @change="tick(c.id, i.key)"
                        >
                        <span :class="i.done ? 'text-surface-500 line-through' : ''">{{ i.label }}</span>
                        <span
                            v-if="i.auto"
                            class="text-xs text-emerald-700"
                        >done automatically</span>
                    </li>
                </ul>
            </Card>
            <p
                v-if="!checklists.length"
                class="text-sm text-surface-500"
            >
                No checklists yet — they open when a staff member joins, changes role or leaves.
            </p>
        </div>
    </AppLayout>
</template>
