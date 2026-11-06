<script setup lang="ts">
/** Lead / opportunity pipeline (M02-02): stage board, quote from a lead, lost reasons. */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { sumDecimals } from '@/lib/decimal';

interface Lead { id: number; title: string; party: string; client_id: number | null; prospect_name: string | null; stage: string; engagement_type: string; estimated_value: string; expected_close: string | null; source: string | null; notes: string | null; quotation_id: number | null; lost_reason: string | null; owner: string | null }
const props = defineProps<{ leads: Lead[]; stages: string[]; clients: { id: number; label: string }[]; can: { create: boolean; quote: boolean; project: boolean } }>();
const open = ref(false);
const editing = ref<Lead | null>(null);
const blank = { client_id: '' as number | '', prospect_name: '', title: '', engagement_type: 'project', estimated_value: '' as string | number | null, expected_close: '', source: '', notes: '' };
const form = useForm({ ...blank });
const columns = computed(() => props.stages.map((s) => ({ stage: s, leads: props.leads.filter((l) => l.stage === s), total: sumDecimals(props.leads.filter((l) => l.stage === s).map((l) => l.estimated_value)) })));
function edit(l: Lead | null) {
    editing.value = l;
    form.defaults(l ? { client_id: l.client_id ?? '', prospect_name: l.prospect_name ?? '', title: l.title, engagement_type: l.engagement_type, estimated_value: l.estimated_value, expected_close: l.expected_close ?? '', source: l.source ?? '', notes: l.notes ?? '' } : { ...blank });
    form.reset(); form.clearErrors(); open.value = true;
}
function save() {
    const t = form.transform((d) => ({ ...d, client_id: d.client_id || null, estimated_value: d.estimated_value === '' || d.estimated_value === null ? null : String(d.estimated_value), expected_close: d.expected_close || null }));
    const opts = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    if (editing.value) t.put(route('projects.leads.update', editing.value.id), opts); else t.post(route('projects.leads.store'), opts);
}
function move(l: Lead, stage: string) {
    const reason = stage === 'lost' ? window.prompt('Why was this lead lost?') : null;
    if (stage === 'lost' && !reason) return;
    router.post(route('projects.leads.stage', l.id), { stage, reason }, { preserveScroll: true });
}
const open_ = (s: string) => !['won', 'lost'].includes(s);
</script>

<template>
    <AppLayout
        title="Leads & pipeline"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Leads' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'projects.pipeline')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Pipeline report
            </Link>
            <Button
                v-if="can.create"
                data-test="add-lead"
                @click="edit(null)"
            >
                Add lead
            </Button>
        </template>
        <div class="grid gap-3 overflow-x-auto pb-2 lg:grid-cols-6">
            <section
                v-for="c in columns"
                :key="c.stage"
                class="min-w-[220px] rounded-lg border border-surface-200 bg-surface-50 p-2"
                :data-test="`stage-${c.stage}`"
            >
                <header class="mb-2 flex items-center justify-between px-1">
                    <StatusChip
                        :status="c.stage === 'won' ? 'complete' : c.stage === 'lost' ? 'rejected' : 'pending'"
                        :label="c.stage"
                    />
                    <span class="text-xs text-surface-500">{{ c.leads.length }} · <Amount :value="c.total" /></span>
                </header>
                <article
                    v-for="l in c.leads"
                    :key="l.id"
                    class="mb-2 rounded-md border border-surface-200 bg-white p-3 text-sm shadow-sm"
                >
                    <button
                        type="button"
                        class="text-left font-medium hover:text-brand-700"
                        @click="edit(l)"
                    >
                        {{ l.title }}
                    </button>
                    <p class="text-xs text-surface-500">
                        {{ l.party }} · {{ l.engagement_type }}
                    </p>
                    <p class="mt-1 font-semibold">
                        <Amount :value="l.estimated_value" />
                    </p>
                    <p
                        v-if="l.expected_close"
                        class="text-xs text-surface-500"
                    >
                        Close by {{ l.expected_close }}
                    </p>
                    <p
                        v-if="l.lost_reason"
                        class="text-xs text-red-700"
                    >
                        {{ l.lost_reason }}
                    </p>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <Link
                            v-if="l.quotation_id"
                            :href="route('commercial.quotations.show', l.quotation_id)"
                            class="rounded bg-brand-50 px-2 py-0.5 text-xs text-brand-800"
                        >
                            Quotation
                        </Link>
                        <Link
                            v-else-if="can.quote && open_(l.stage) && l.client_id"
                            :href="route('commercial.quotations.create', { lead_id: l.id })"
                            class="rounded bg-brand-600 px-2 py-0.5 text-xs text-white"
                            :data-test="`quote-${l.id}`"
                        >
                            Quote
                        </Link>
                        <select
                            v-if="open_(l.stage)"
                            :aria-label="`Move ${l.title}`"
                            class="rounded border border-surface-200 px-1 py-0.5 text-xs"
                            :value="l.stage"
                            @change="move(l, ($event.target as HTMLSelectElement).value)"
                        >
                            <option
                                v-for="s in stages"
                                :key="s"
                                :value="s"
                            >
                                {{ s }}
                            </option>
                        </select>
                    </div>
                </article>
            </section>
        </div>
        <Modal
            :open="open"
            :title="editing ? `Edit ${editing.title}` : 'Add lead'"
            size="md"
            @close="open = false"
        >
            <form
                id="lead-form"
                class="grid grid-cols-2 gap-3"
                @submit.prevent="save"
            >
                <FormField
                    id="l-title"
                    label="Opportunity"
                    :error="form.errors.title"
                    class="col-span-2"
                >
                    <input
                        id="l-title"
                        v-model="form.title"
                        data-autofocus
                        required
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="l-client"
                    label="Client"
                    :error="form.errors.client_id"
                >
                    <select
                        id="l-client"
                        v-model="form.client_id"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="">
                            — new prospect —
                        </option>
                        <option
                            v-for="c in clients"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    v-if="!form.client_id"
                    id="l-prospect"
                    label="Prospect name"
                    :error="form.errors.prospect_name"
                >
                    <input
                        id="l-prospect"
                        v-model="form.prospect_name"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="l-type"
                    label="Type"
                >
                    <select
                        id="l-type"
                        v-model="form.engagement_type"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                        <option value="project">
                            Project
                        </option><option value="training">
                            Training
                        </option><option value="retainer">
                            Retainer
                        </option>
                    </select>
                </FormField>
                <FormField
                    id="l-value"
                    label="Estimated value"
                    :error="form.errors.estimated_value"
                >
                    <MoneyInput
                        id="l-value"
                        v-model="form.estimated_value"
                    />
                </FormField>
                <FormField
                    id="l-close"
                    label="Expected close"
                >
                    <input
                        id="l-close"
                        v-model="form.expected_close"
                        type="date"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="l-source"
                    label="Source"
                >
                    <input
                        id="l-source"
                        v-model="form.source"
                        placeholder="Referral, tender, website…"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    >
                </FormField>
                <FormField
                    id="l-notes"
                    label="Notes"
                    class="col-span-2"
                >
                    <textarea
                        id="l-notes"
                        v-model="form.notes"
                        rows="3"
                        class="w-full rounded-md border border-surface-300 px-3 py-2"
                    />
                </FormField>
            </form>
            <template #footer>
                <Button
                    variant="secondary"
                    @click="open = false"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    form="lead-form"
                    :disabled="form.processing"
                >
                    Save lead
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
