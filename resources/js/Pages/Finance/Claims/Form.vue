<script setup lang="ts">
import { fieldError } from '@/lib/errors';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { ClaimRow, Option } from '@/types/finance';

interface ClaimTypeOpt { id: number; code: string; label: string; receipt_required: boolean; is_medical: boolean; rate: string | null; unit: string | null }
const props = defineProps<{ claim: ClaimRow | null; claimTypes: ClaimTypeOpt[]; categories: Option[]; costCentres: Option[] }>();
interface Line { id?: number; claim_type_id: number | ''; incurred_on: string; description: string; amount: string; distance_km: string; origin: string; destination: string; clinical_note: string; status?: string; rejection_reason?: string | null }
const today = new Date().toISOString().slice(0, 10);
const blank = (): Line => ({ claim_type_id: props.claimTypes[0]?.id ?? '', incurred_on: today, description: '', amount: '', distance_km: '', origin: '', destination: '', clinical_note: '' });
const form = useForm({
    purpose: props.claim?.purpose ?? '',
    cost_centre_id: (props.claim?.cost_centre_id ?? '') as number | '',
    lines: (props.claim?.lines ?? []).map((l) => ({ id: l.id, claim_type_id: l.claim_type_id, incurred_on: l.incurred_on, description: l.description, amount: l.amount, distance_km: l.distance_km ?? '', origin: l.origin ?? '', destination: l.destination ?? '', clinical_note: l.clinical_note ?? '', status: l.status, rejection_reason: l.rejection_reason })) as Line[],
});
if (form.lines.length === 0) form.lines.push(blank());
const type = (id: number | '') => props.claimTypes.find((t) => t.id === id);
function submit() {
    const payload = (d: typeof form.data extends () => infer R ? R : never) => ({ ...d, cost_centre_id: d.cost_centre_id || null, lines: d.lines.map((l) => ({ ...l, amount: type(l.claim_type_id)?.code === 'mileage' ? null : l.amount, distance_km: type(l.claim_type_id)?.code === 'mileage' ? l.distance_km : null })) });
    if (props.claim) form.transform(payload).put(route('finance.claims.update', props.claim.id));
    else form.transform(payload).post(route('finance.claims.store'));
}
</script>

<template>
    <AppLayout
        :title="claim ? `Edit ${claim.claim_no}` : 'New claim'"
        :status="claim?.status"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Claims', href: route('finance.claims.index') }, { label: claim ? claim.claim_no : 'New' }]"
    >
        <Card description="Save as a draft, attach receipts on the next page, then submit for approval. Mileage is calculated from the approved rate.">
            <form
                class="space-y-5"
                @submit.prevent="submit"
            >
                <div class="grid gap-3 md:grid-cols-3">
                    <FormField
                        id="purpose"
                        label="Purpose"
                        :error="form.errors.purpose"
                        class="md:col-span-2"
                    >
                        <input
                            id="purpose"
                            v-model="form.purpose"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        id="cost_centre_id"
                        label="Cost centre"
                    >
                        <select
                            id="cost_centre_id"
                            v-model="form.cost_centre_id"
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                            <option value="">
                                —
                            </option>
                            <option
                                v-for="c in costCentres"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.label }}
                            </option>
                        </select>
                    </FormField>
                </div>
                <fieldset
                    v-for="(l, i) in form.lines"
                    :key="l.id ?? `new-${i}`"
                    class="rounded-lg border p-4"
                    :class="l.status === 'rejected' ? 'border-red-300 bg-red-50/40' : 'border-surface-200'"
                >
                    <legend class="px-1 text-sm font-semibold">
                        Line {{ i + 1 }}
                    </legend>
                    <p
                        v-if="l.status === 'rejected'"
                        class="mb-2 text-sm text-red-700"
                    >
                        Returned: {{ l.rejection_reason }}
                    </p>
                    <div class="grid gap-3 md:grid-cols-4">
                        <FormField
                            :id="`type-${i}`"
                            label="Type"
                            :error="fieldError(form.errors, `lines.${i}.claim_type_id`)"
                        >
                            <select
                                :id="`type-${i}`"
                                v-model="l.claim_type_id"
                                class="w-full rounded-md border border-surface-300 px-3 py-2"
                            >
                                <option
                                    v-for="t in claimTypes"
                                    :key="t.id"
                                    :value="t.id"
                                >
                                    {{ t.label }}
                                </option>
                            </select>
                        </FormField>
                        <FormField
                            :id="`date-${i}`"
                            label="Date"
                        >
                            <input
                                :id="`date-${i}`"
                                v-model="l.incurred_on"
                                type="date"
                                :max="today"
                                required
                                class="w-full rounded-md border border-surface-300 px-3 py-2"
                            >
                        </FormField>
                        <FormField
                            :id="`desc-${i}`"
                            label="Description"
                            class="md:col-span-2"
                        >
                            <input
                                :id="`desc-${i}`"
                                v-model="l.description"
                                required
                                class="w-full rounded-md border border-surface-300 px-3 py-2"
                            >
                        </FormField>
                        <template v-if="type(l.claim_type_id)?.code === 'mileage'">
                            <FormField
                                :id="`from-${i}`"
                                label="From"
                            >
                                <input
                                    :id="`from-${i}`"
                                    v-model="l.origin"
                                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                                >
                            </FormField>
                            <FormField
                                :id="`to-${i}`"
                                label="To"
                            >
                                <input
                                    :id="`to-${i}`"
                                    v-model="l.destination"
                                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                                >
                            </FormField>
                            <FormField
                                :id="`km-${i}`"
                                :label="`Distance (km) · RM ${type(l.claim_type_id)?.rate ?? '?'} / km`"
                                :error="fieldError(form.errors, `lines.${i}.distance_km`)"
                            >
                                <input
                                    :id="`km-${i}`"
                                    v-model="l.distance_km"
                                    inputmode="decimal"
                                    required
                                    class="w-full rounded-md border border-surface-300 px-3 py-2"
                                >
                            </FormField>
                        </template>
                        <FormField
                            v-else
                            :id="`amt-${i}`"
                            label="Amount"
                            :error="fieldError(form.errors, `lines.${i}.amount`)"
                        >
                            <MoneyInput
                                :id="`amt-${i}`"
                                v-model="l.amount"
                            />
                        </FormField>
                        <FormField
                            v-if="type(l.claim_type_id)?.is_medical"
                            :id="`clin-${i}`"
                            label="Clinical note (visible to HR/Owner only)"
                            class="md:col-span-4"
                        >
                            <textarea
                                :id="`clin-${i}`"
                                v-model="l.clinical_note"
                                rows="2"
                                class="w-full rounded-md border border-surface-300 px-3 py-2"
                            />
                        </FormField>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-xs text-surface-500">
                        <span>{{ type(l.claim_type_id)?.receipt_required ? 'Receipt required — attach after saving.' : 'No receipt required.' }}</span>
                        <button
                            v-if="form.lines.length > 1"
                            type="button"
                            class="text-red-700 hover:underline"
                            @click="form.lines.splice(i, 1)"
                        >
                            Remove line
                        </button>
                    </div>
                </fieldset>
                <button
                    type="button"
                    class="text-sm font-medium text-brand-700"
                    @click="form.lines.push(blank())"
                >
                    + Add line
                </button>
                <p
                    v-if="fieldError(form.errors, 'general')"
                    class="text-sm text-red-600"
                    role="alert"
                >
                    {{ fieldError(form.errors, 'general') }}
                </p>
                <div>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                    >
                        Save draft
                    </Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
