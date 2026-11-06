<script setup lang="ts">
/** Staff memo / appointment letter / letter (M03-06). Appointment wording defaults from the staff member's rate agreement. */
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import type { DocDetail, PickOption } from '@/types/commercial';

const props = defineProps<{ type: string; label: string; document: DocDetail | null; staff: PickOption[]; clients: PickOption[] }>();
const doc = props.document;
const toStaff = props.type !== 'letter';
const cp = (doc?.counterparty ?? {}) as Record<string, string | null>;
const form = useForm({
    type: props.type, staff_profile_id: (toStaff ? doc?.counterparty_id ?? '' : '') as number | '', client_id: (doc?.counterparty_type === 'client' ? doc?.counterparty_id ?? '' : '') as number | '',
    recipient_name: doc?.counterparty_type === 'payee' ? cp.name ?? '' : '', recipient_address: doc?.counterparty_type === 'payee' ? cp.address ?? '' : '',
    subject: doc?.subject ?? '', body: doc?.body ?? '', effective_date: doc?.effective_date ?? '',
});
function submit() {
    const t = form.transform((d) => ({ ...d, staff_profile_id: toStaff ? d.staff_profile_id || null : undefined, client_id: toStaff ? undefined : d.client_id || null, effective_date: d.effective_date || null }));
    if (doc) t.put(route('commercial.generated.update', doc.id)); else t.post(route('commercial.generated.store'));
}
</script>

<template>
    <AppLayout
        :title="doc ? `Edit ${label.toLowerCase()}` : `New ${label.toLowerCase()}`"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Letters & memos', href: route('commercial.generated.index') }, { label: doc ? 'Edit' : 'New' }]"
    >
        <form
            class="space-y-5"
            @submit.prevent="submit"
        >
            <Card :title="label">
                <div class="grid gap-4 md:grid-cols-2">
                    <FormField
                        v-if="toStaff"
                        id="g-staff"
                        label="Staff member"
                        :error="form.errors.staff_profile_id"
                    >
                        <select
                            id="g-staff"
                            v-model="form.staff_profile_id"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                            <option
                                v-for="s in staff"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.label }}
                            </option>
                        </select>
                    </FormField>
                    <template v-else>
                        <FormField
                            id="g-client"
                            label="Client (optional)"
                        >
                            <select
                                id="g-client"
                                v-model="form.client_id"
                                class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                            >
                                <option value="">
                                    — other recipient —
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
                            id="g-name"
                            label="Recipient"
                            :error="fieldError(form.errors, 'general')"
                        >
                            <input
                                id="g-name"
                                v-model="form.recipient_name"
                                class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                            >
                        </FormField>
                        <FormField
                            v-if="!form.client_id"
                            id="g-address"
                            label="Recipient address"
                            class="md:col-span-2"
                        >
                            <input
                                id="g-address"
                                v-model="form.recipient_address"
                                class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                            >
                        </FormField>
                    </template>
                    <FormField
                        v-if="type !== 'memo'"
                        id="g-date"
                        :label="type === 'appointment_letter' ? 'Start date' : 'Effective date'"
                    >
                        <input
                            id="g-date"
                            v-model="form.effective_date"
                            type="date"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                    </FormField>
                    <FormField
                        id="g-subject"
                        label="Subject"
                        :error="form.errors.subject"
                        class="md:col-span-2"
                    >
                        <input
                            id="g-subject"
                            v-model="form.subject"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2 text-sm"
                        >
                    </FormField>
                    <FormField
                        id="g-body"
                        :label="type === 'appointment_letter' ? 'Wording (blank = generated from the rate agreement)' : 'Body'"
                        :error="form.errors.body"
                        class="md:col-span-2"
                    >
                        <textarea
                            id="g-body"
                            v-model="form.body"
                            rows="12"
                            class="w-full rounded-md border border-surface-300 px-3 py-2 font-mono text-sm"
                        />
                    </FormField>
                </div>
            </Card>
            <div class="flex gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-generated"
                >
                    Save draft
                </Button>
                <Link
                    :href="doc ? route('commercial.generated.show', doc.id) : route('commercial.generated.index')"
                    class="rounded-md px-4 py-2 text-sm text-surface-600 hover:bg-surface-100"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </AppLayout>
</template>
