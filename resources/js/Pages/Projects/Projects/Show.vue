<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import StatusChip from '@/Components/StatusChip.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Project { id: number; code: string; name: string; client: string; engagement_type: string; contract_value: string; status_delivery: string; status_payment: string; source_quotation_id: number | null; scope: string | null }
defineProps<{ project: Project; can: { invoice: boolean; quotation: boolean } }>();
</script>

<template>
    <AppLayout
        :title="`${project.code} ${project.name}`"
        :status="project.status_delivery"
        :breadcrumbs="[{ label: 'Sales' }, { label: 'Projects', href: route('projects.projects.index') }, { label: project.code }]"
    >
        <template #actions>
            <Link
                v-if="can.invoice"
                :href="route('commercial.invoices.create', { project_id: project.id })"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
                New invoice
            </Link>
        </template>
        <Card title="Project">
            <dl class="grid gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-surface-500">
                        Client
                    </dt><dd class="font-medium">
                        {{ project.client }}
                    </dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Type
                    </dt><dd>{{ project.engagement_type }}</dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Contract value
                    </dt><dd class="text-lg font-semibold">
                        <Amount :value="project.contract_value" />
                    </dd>
                </div>
                <div>
                    <dt class="text-surface-500">
                        Billing
                    </dt><dd>
                        <StatusChip
                            :status="project.status_payment === 'paid' ? 'paid' : 'pending'"
                            :label="project.status_payment.replace('_', ' ')"
                        />
                    </dd>
                </div>
                <div v-if="project.source_quotation_id && can.quotation">
                    <dt class="text-surface-500">
                        Source quotation
                    </dt><dd>
                        <Link
                            :href="route('commercial.quotations.show', project.source_quotation_id)"
                            class="text-brand-700 hover:underline"
                        >
                            Open quotation
                        </Link>
                    </dd>
                </div>
            </dl>
            <p
                v-if="project.scope"
                class="mt-4 whitespace-pre-line text-sm text-surface-700"
            >
                {{ project.scope }}
            </p>
        </Card>
    </AppLayout>
</template>
