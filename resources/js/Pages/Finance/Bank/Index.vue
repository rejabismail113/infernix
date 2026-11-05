<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Amount from '@/Components/Finance/Amount.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ accounts: { id: number; label: string; gl: string; ledger_balance: string; unmatched: number }[] }>();
</script>

<template>
    <AppLayout
        title="Bank accounts"
        :breadcrumbs="[{ label: 'Finance', href: route('finance.overview') }, { label: 'Bank' }]"
    >
        <div class="grid gap-3 md:grid-cols-2">
            <Link
                v-for="a in accounts"
                :key="a.id"
                :href="route('finance.bank.show', a.id)"
                class="rounded-lg border border-surface-200 bg-white p-5 hover:border-brand-300"
            >
                <p class="font-semibold">
                    {{ a.label }}
                </p>
                <p class="text-xs text-surface-500">
                    GL {{ a.gl }}
                </p>
                <p class="mt-3 text-2xl font-semibold">
                    <Amount :value="a.ledger_balance" />
                </p>
                <p class="text-sm text-surface-500">
                    {{ a.unmatched }} unmatched statement lines
                </p>
            </Link>
        </div>
    </AppLayout>
</template>
