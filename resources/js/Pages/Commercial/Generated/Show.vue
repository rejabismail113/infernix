<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Icon from '@/Components/Icon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { DocDetail } from '@/types/commercial';

const props = defineProps<{ document: DocDetail; label: string; verifyUrl: string | null; can: { edit: boolean; issue: boolean; void: boolean; regenerate: boolean } }>();
const d = props.document;
const cp = (d.counterparty ?? {}) as Record<string, string | null>;
const voidIt = () => { const r = window.prompt('Why void this document? The number is kept.'); if (r) router.post(route('commercial.generated.void', d.id), { reason: r }); };
</script>

<template>
    <AppLayout
        :title="`${label} ${d.number}`"
        :status="d.status"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Letters & memos', href: route('commercial.generated.index') }, { label: d.number }]"
    >
        <template #actions>
            <a
                v-if="d.pdf_document_id"
                :href="route('documents.download', { document: d.pdf_document_id })"
                class="inline-flex items-center gap-1.5 rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
            ><Icon
                name="download"
                :size="16"
            /> PDF</a>
            <a
                v-if="d.void_pdf_document_id"
                :href="route('documents.download', { document: d.void_pdf_document_id })"
                class="rounded-md border border-red-300 px-3 py-2 text-sm text-red-700"
            >VOID copy</a>
            <Link
                v-if="can.edit"
                :href="route('commercial.generated.edit', d.id)"
                class="rounded-md border border-surface-300 px-3 py-2 text-sm hover:bg-surface-100"
            >
                Edit
            </Link>
            <Button
                v-if="can.issue"
                data-test="issue-generated"
                @click="router.post(route('commercial.generated.issue', d.id))"
            >
                Issue
            </Button>
            <Button
                v-if="can.regenerate"
                variant="secondary"
                @click="router.post(route('commercial.generated.regenerate', d.id))"
            >
                Generate PDF
            </Button>
            <Button
                v-if="can.void"
                variant="secondary"
                @click="voidIt"
            >
                Void
            </Button>
        </template>
        <Card>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-surface-500">
                        To
                    </dt><dd class="font-medium">
                        {{ cp.name }}
                    </dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Issued
                    </dt><dd>{{ d.issue_date ?? 'not yet' }}</dd>
                </div>
                <div v-if="d.effective_date">
                    <dt class="text-surface-500">
                        Effective
                    </dt><dd>{{ d.effective_date }}</dd>
                </div>
                <div v-if="verifyUrl">
                    <dt class="text-surface-500">
                        Public verification
                    </dt><dd>
                        <a
                            :href="verifyUrl"
                            target="_blank"
                            rel="noopener"
                            class="break-all text-brand-700 hover:underline"
                        >{{ verifyUrl }}</a>
                    </dd>
                </div>
            </dl>
        </Card>
        <Card :title="d.subject ?? ''">
            <p class="whitespace-pre-line text-sm">
                {{ d.body }}
            </p>
            <p
                v-if="d.status === 'void'"
                class="mt-3 rounded bg-red-50 p-2 text-sm text-red-800"
            >
                VOID — {{ d.void_reason }}
            </p>
        </Card>
    </AppLayout>
</template>
