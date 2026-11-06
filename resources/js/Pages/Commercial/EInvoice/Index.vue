<script setup lang="ts">
/** e-Invoice (MyInvois) outbox (§6.2). Eligibility is NOT assumed — the banner says so until confirmed. */
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Card from '@/Components/Card.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{
    enabled: boolean; gateway: string;
    rows: { id: number; invoice_id: number; invoice_no: string | null; status: string; attempts: number; next_attempt_at: string | null; last_error: string | null; external_uuid: string | null; accepted_at: string | null; events: { event: string; detail: Record<string, unknown> | null; at: string }[] }[];
    can: { retry: boolean };
}>();
const open = ref<number | null>(null);
const retry = (id: number) => router.post(route('commercial.einvoice.retry', id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout
        title="e-Invoice (MyInvois)"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'e-Invoice' }]"
    >
        <p
            class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
            role="note"
            data-test="einvoice-banner"
        >
            e-Invoicing is <strong>{{ enabled ? 'switched ON' : 'switched OFF' }}</strong> (gateway: {{ gateway }}). Whether and from when Infernix Evolv Hub must issue
            MyInvois e-invoices, and which fields are required, <strong>must be verified by the accountant</strong> before this is used for real submissions.
            Only a stub gateway is installed; it does not contact LHDN.
        </p>
        <Card title="Submission outbox">
            <table class="w-full text-sm">
                <thead class="text-left text-surface-500">
                    <tr>
                        <th>Invoice</th><th>Status</th><th>Attempts</th><th>Next attempt</th><th>UUID</th><th>Last error</th><th />
                    </tr>
                </thead>
                <tbody>
                    <template
                        v-for="r in rows"
                        :key="r.id"
                    >
                        <tr class="border-t border-surface-100">
                            <td class="py-1">
                                <a
                                    :href="route('commercial.invoices.show', r.invoice_id)"
                                    class="text-brand-700 hover:underline"
                                >{{ r.invoice_no }}</a>
                            </td>
                            <td>
                                <StatusChip
                                    :status="r.status === 'accepted' ? 'approved' : r.status === 'failed' || r.status === 'rejected' ? 'rejected' : 'pending'"
                                    :label="r.status"
                                />
                            </td>
                            <td>{{ r.attempts }}</td>
                            <td>{{ r.next_attempt_at ?? '—' }}</td>
                            <td class="font-mono text-xs">
                                {{ r.external_uuid ?? '—' }}
                            </td>
                            <td class="text-xs text-red-700">
                                {{ r.last_error }}
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <button
                                    type="button"
                                    class="text-xs text-brand-700 hover:underline"
                                    @click="open = open === r.id ? null : r.id"
                                >
                                    Log
                                </button>
                                <button
                                    v-if="can.retry && ['failed', 'rejected'].includes(r.status)"
                                    type="button"
                                    class="ml-2 text-xs text-brand-700 hover:underline"
                                    @click="retry(r.id)"
                                >
                                    Retry
                                </button>
                            </td>
                        </tr>
                        <tr v-if="open === r.id">
                            <td
                                colspan="7"
                                class="bg-surface-50 px-3 py-2 text-xs"
                            >
                                <div
                                    v-for="(e, i) in r.events"
                                    :key="i"
                                >
                                    {{ e.at }} · {{ e.event }} {{ e.detail ? JSON.stringify(e.detail) : '' }}
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!rows.length">
                        <td
                            colspan="7"
                            class="py-3 text-center text-surface-500"
                        >
                            Nothing submitted.
                        </td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </AppLayout>
</template>
