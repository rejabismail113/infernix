<script setup lang="ts">
/** Backup Status (M12-06, NFR-06): nightly encrypted backups, retention, restore tests (RPO ≤ 24 h, quarterly test). */
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Run { id: number; kind: string; status: string; started_at: string; finished_at: string | null; object_key: string | null; size_bytes: number | null; sha256: string | null; error: string | null; pruned: boolean; details: Record<string, unknown> | null }
defineProps<{ runs: Run[]; rpoOk: boolean; restoreTestOk: boolean; disk: string; encrypted: boolean; can: { run: boolean } }>();
const start = (kind: 'backup' | 'restore_test') => router.post(route('admin.backups.run'), { kind }, { preserveScroll: true });
const size = (b: number | null) => (b === null ? '—' : b > 1048576 ? `${Math.round(b / 104857.6) / 10} MB` : `${Math.round(b / 1024)} KB`);
</script>

<template>
    <AppLayout
        title="Backups"
        :breadcrumbs="[{ label: 'Administration' }, { label: 'Backups' }]"
    >
        <template #actions>
            <Button
                v-if="can.run"
                variant="secondary"
                @click="start('restore_test')"
            >
                Run restore test
            </Button>
            <Button
                v-if="can.run"
                @click="start('backup')"
            >
                Back up now
            </Button>
        </template>
        <div class="mb-5 grid gap-4 sm:grid-cols-3">
            <Card title="Last 24 hours (RPO)">
                <StatusChip
                    :status="rpoOk ? 'approved' : 'rejected'"
                    :label="rpoOk ? 'Backup within 24 h' : 'No backup in 24 h'"
                    data-test="rpo"
                />
            </Card>
            <Card title="Restore test (quarterly)">
                <StatusChip
                    :status="restoreTestOk ? 'approved' : 'rejected'"
                    :label="restoreTestOk ? 'Tested within 90 days' : 'Restore test overdue'"
                />
            </Card>
            <Card title="Storage">
                <p class="text-sm">
                    Disk <code>{{ disk }}</code> · {{ encrypted ? 'AES-256 encrypted' : 'NO PASSPHRASE — backups refused' }}
                </p>
            </Card>
        </div>
        <Card title="History">
            <table class="w-full text-sm">
                <thead class="text-left text-surface-500">
                    <tr>
                        <th>Started</th><th>Kind</th><th>Status</th><th>Object</th><th>Size</th><th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in runs"
                        :key="r.id"
                        class="border-t border-surface-100 align-top"
                    >
                        <td class="py-1">
                            {{ r.started_at.slice(0, 19) }}
                        </td>
                        <td>{{ r.kind.replace('_', ' ') }}</td>
                        <td>
                            <StatusChip
                                :status="r.status === 'ok' ? 'approved' : r.status === 'failed' ? 'rejected' : 'pending'"
                                :label="r.pruned ? 'pruned' : r.status"
                            />
                        </td>
                        <td class="font-mono text-xs">
                            {{ r.object_key ?? '—' }}
                        </td>
                        <td>{{ size(r.size_bytes) }}</td>
                        <td class="text-xs">
                            <span
                                v-if="r.error"
                                class="text-red-700"
                            >{{ r.error }}</span>
                            <span v-else-if="r.details && 'restore_seconds' in r.details">restored in {{ r.details.restore_seconds }} s · backup age {{ r.details.backup_age_hours }} h</span>
                        </td>
                    </tr>
                    <tr v-if="!runs.length">
                        <td
                            colspan="6"
                            class="py-3 text-center text-surface-500"
                        >
                            No backups recorded yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </AppLayout>
</template>
