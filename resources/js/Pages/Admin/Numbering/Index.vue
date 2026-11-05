<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SimpleTable from '@/Components/SimpleTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ sequences: { id: number; document_type: string; fiscal_year: number; prefix: string; format: string; next_number: number; padding: number; preview: string }[]; can: { edit: boolean } }>();
const form = useForm({ document_type: '', fiscal_year: new Date().getFullYear(), prefix: '', format: '{prefix}-{year}-{seq}', padding: 4, next_number: 1 });
</script>

<template>
    <AppLayout>
        <Head title="Numbering" />
        <template #header>
            <h1 class="text-lg font-semibold">
                Document numbering
            </h1>
        </template>
        <SimpleTable
            :columns="[{ key: 'document_type', label: 'Type' }, { key: 'fiscal_year', label: 'FY' }, { key: 'prefix', label: 'Prefix' }, { key: 'format', label: 'Format' }, { key: 'next_number', label: 'Next' }, { key: 'preview', label: 'Preview' }]"
            :rows="sequences"
        />
        <form
            v-if="can.edit"
            class="mt-6 grid max-w-4xl grid-cols-2 gap-3 text-sm md:grid-cols-7"
            @submit.prevent="form.post(route('admin.numbering.store'), { onSuccess: () => form.reset() })"
        >
            <input
                v-model="form.document_type"
                required
                placeholder="TYPE"
                aria-label="Document type"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model.number="form.fiscal_year"
                type="number"
                aria-label="Fiscal year"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="form.prefix"
                required
                placeholder="prefix"
                aria-label="Prefix"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model="form.format"
                required
                aria-label="Format"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model.number="form.padding"
                type="number"
                aria-label="Padding"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <input
                v-model.number="form.next_number"
                type="number"
                aria-label="Next number"
                class="rounded-md border border-surface-300 px-2 py-2"
            >
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-brand-600 px-3 py-2 text-white hover:bg-brand-700 disabled:opacity-50"
            >
                Save
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
