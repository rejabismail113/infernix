<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Button.vue';
import Icon from '@/Components/Icon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{ notifications: { id: string; title: string; body: string; url: string | null; event: string | null; read: boolean; created_at: string | null }[] }>();
</script>

<template>
    <AppLayout
        title="Notifications"
        :breadcrumbs="[{ label: 'Notifications' }]"
    >
        <template #actions>
            <Button
                variant="secondary"
                @click="router.post(route('notifications.read-all'))"
            >
                Mark all as read
            </Button>
        </template>
        <ul class="divide-y divide-surface-100 rounded-lg border border-surface-200 bg-white">
            <li
                v-for="n in notifications"
                :key="n.id"
                class="flex items-start gap-3 p-4"
                :class="{ 'bg-brand-50/40': !n.read }"
            >
                <Icon
                    name="bell"
                    :class="n.read ? 'text-surface-300' : 'text-brand-600'"
                />
                <div class="flex-1">
                    <a
                        :href="route('notifications.open', n.id)"
                        class="font-medium hover:underline"
                    >{{ n.title }}</a>
                    <p class="text-sm text-surface-600">
                        {{ n.body }}
                    </p>
                    <p class="text-xs text-surface-400">
                        {{ n.created_at?.slice(0, 16).replace('T', ' ') }}
                    </p>
                </div>
            </li>
            <li
                v-if="!notifications.length"
                class="p-6 text-center text-sm text-surface-500"
            >
                You're all caught up.
            </li>
        </ul>
    </AppLayout>
</template>
