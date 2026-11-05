<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import SimpleTable from '@/Components/SimpleTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Row { id: number; delegator: string; delegate: string; entity_type: string; max_amount: string | null; valid_from: string; valid_to: string; reason: string; version: number; supersedes_id: number | null; revoked_at: string | null }
defineProps<{ delegations: Row[]; users: { id: number; name: string }[]; can: { create: boolean } }>();
const form = useForm({ delegate_id: '', entity_type: 'claim', max_amount: '', valid_from: '', valid_to: '', reason: '' });
const columns = [
    { key: 'delegator', label: 'Delegator' }, { key: 'delegate', label: 'Delegate' }, { key: 'entity_type', label: 'Entity' },
    { key: 'max_amount', label: 'Max (RM)' }, { key: 'valid_from', label: 'From' }, { key: 'valid_to', label: 'To' },
    { key: 'version', label: 'Ver.' }, { key: 'revoked_at', label: 'Revoked' }, { key: 'reason', label: 'Reason' },
];
function revoke(id: number) {
    const reason = window.prompt('Reason for revocation (audited)');
    if (reason) router.post(route('admin.delegations.revoke', id), { reason });
}
</script>

<template>
    <AppLayout>
        <Head title="Delegations" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Approval delegations
            </h1>
        </template>
        <SimpleTable
            :columns="columns"
            :rows="delegations"
        >
            <template #actions="{ row }">
                <button
                    v-if="!row.revoked_at"
                    class="text-red-700 hover:underline"
                    @click="revoke(row.id as number)"
                >
                    Revoke
                </button>
            </template>
        </SimpleTable>
        <form
            v-if="can.create"
            class="mt-6 grid max-w-4xl grid-cols-2 gap-3 rounded-lg border border-surface-200 bg-white p-4 text-sm md:grid-cols-7"
            @submit.prevent="form.post(route('admin.delegations.store'), { onSuccess: () => form.reset() })"
        >
            <select
                v-model="form.delegate_id"
                required
                aria-label="Delegate"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    value=""
                    disabled
                >
                    Delegate…
                </option>
                <option
                    v-for="u in users"
                    :key="u.id"
                    :value="u.id"
                >
                    {{ u.name }}
                </option>
            </select>
            <select
                v-model="form.entity_type"
                aria-label="Entity type"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
                <option
                    v-for="t in ['claim', 'payment', 'invoice', 'bill', 'purchase_request', 'credit_note', 'asset_disposal']"
                    :key="t"
                >
                    {{ t }}
                </option>
            </select>
            <input
                v-model="form.max_amount"
                placeholder="max RM (decimal)"
                aria-label="Max amount"
                inputmode="decimal"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="form.valid_from"
                type="date"
                required
                aria-label="Valid from"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="form.valid_to"
                type="date"
                required
                aria-label="Valid to"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="form.reason"
                required
                placeholder="reason"
                aria-label="Reason"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-brand-600 px-3 py-2 text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Delegate
            </button>
            <p
                v-if="Object.keys(form.errors).length"
                class="col-span-full text-red-600"
            >
                {{ Object.values(form.errors).join(' ') }}
            </p>
        </form>
    </AppLayout>
</template>
