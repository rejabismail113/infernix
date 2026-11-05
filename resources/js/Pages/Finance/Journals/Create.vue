<script setup lang="ts">
import { fieldError } from '@/lib/errors';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import FormField from '@/Components/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fromMinorUnits, isDecimalString, toMinorUnits } from '@/lib/money';
import type { Option } from '@/types/finance';

defineProps<{ accounts: (Option & { type: string })[] }>();
interface Line { account_id: number | ''; debit: string; credit: string; description: string }
const blank = (): Line => ({ account_id: '', debit: '', credit: '', description: '' });
const form = useForm<{ date: string; memo: string; lines: Line[] }>({ date: new Date().toISOString().slice(0, 10), memo: '', lines: [blank(), blank()] });
const sum = (k: 'debit' | 'credit') => form.lines.reduce((s, l) => s + (isDecimalString(l[k]) ? toMinorUnits(l[k]) : 0), 0);
const debits = computed(() => sum('debit'));
const credits = computed(() => sum('credit'));
const balanced = computed(() => debits.value === credits.value && debits.value > 0);
</script>

<template>
    <AppLayout
        title="Adjusting journal"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Journals', href: route('finance.journals.index') }, { label: 'New' }]"
    >
        <Card description="Saved as a draft; it posts only after someone else approves it. Debits must equal credits.">
            <form
                class="space-y-4"
                @submit.prevent="form.post(route('finance.journals.store'))"
            >
                <div class="grid gap-3 md:grid-cols-3">
                    <FormField
                        id="date"
                        label="Posting date"
                        :error="form.errors.date"
                    >
                        <input
                            id="date"
                            v-model="form.date"
                            type="date"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                    <FormField
                        id="memo"
                        label="Reason / memo"
                        :error="form.errors.memo"
                        class="md:col-span-2"
                    >
                        <input
                            id="memo"
                            v-model="form.memo"
                            required
                            class="w-full rounded-md border border-surface-300 px-3 py-2"
                        >
                    </FormField>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs text-surface-500">
                        <tr>
                            <th class="py-1">
                                Account
                            </th><th class="w-40 py-1">
                                Debit
                            </th><th class="w-40 py-1">
                                Credit
                            </th><th class="py-1">
                                Description
                            </th><th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(l, i) in form.lines"
                            :key="i"
                        >
                            <td class="py-1 pr-2">
                                <select
                                    v-model="l.account_id"
                                    required
                                    :aria-label="`Line ${i + 1} account`"
                                    class="w-full rounded-md border border-surface-300 px-2 py-2"
                                >
                                    <option
                                        value=""
                                        disabled
                                    >
                                        Choose account…
                                    </option>
                                    <option
                                        v-for="a in accounts"
                                        :key="a.id"
                                        :value="a.id"
                                    >
                                        {{ a.label }}
                                    </option>
                                </select>
                            </td>
                            <td class="py-1 pr-2">
                                <MoneyInput
                                    v-model="l.debit"
                                    placeholder=""
                                />
                            </td>
                            <td class="py-1 pr-2">
                                <MoneyInput
                                    v-model="l.credit"
                                    placeholder=""
                                />
                            </td>
                            <td class="py-1 pr-2">
                                <input
                                    v-model="l.description"
                                    :aria-label="`Line ${i + 1} description`"
                                    class="w-full rounded-md border border-surface-300 px-2 py-2"
                                >
                            </td>
                            <td>
                                <button
                                    v-if="form.lines.length > 2"
                                    type="button"
                                    class="text-sm text-red-700"
                                    :aria-label="`Remove line ${i + 1}`"
                                    @click="form.lines.splice(i, 1)"
                                >
                                    ✕
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t font-semibold">
                            <td class="py-2">
                                <button
                                    type="button"
                                    class="text-sm font-medium text-brand-700"
                                    @click="form.lines.push(blank())"
                                >
                                    + Add line
                                </button>
                            </td>
                            <td><Amount :value="fromMinorUnits(debits)" /></td>
                            <td><Amount :value="fromMinorUnits(credits)" /></td>
                            <td colspan="2">
                                <span :class="balanced ? 'text-emerald-700' : 'text-red-700'">{{ balanced ? 'Balanced' : 'Not balanced' }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <p
                    v-if="fieldError(form.errors, 'general')"
                    class="text-sm text-red-600"
                    role="alert"
                >
                    {{ fieldError(form.errors, 'general') }}
                </p>
                <Button
                    type="submit"
                    :disabled="!balanced || form.processing"
                >
                    Save draft for approval
                </Button>
            </form>
        </Card>
    </AppLayout>
</template>
