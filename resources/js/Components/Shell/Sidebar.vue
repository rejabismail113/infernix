<script setup lang="ts">
/**
 * Permission-aware sidebar. The item list comes from the server (HandleInertiaRequests::nav), which uses
 * the same PermissionService as the routes — hiding an entry here is cosmetic; routes still return 403.
 */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from '@/Components/Icon.vue';

export interface NavItem { label: string; href: string; group: string; icon?: string }

const props = defineProps<{ items: NavItem[]; currentUrl: string; companyName?: string }>();
defineEmits<{ (e: 'navigate'): void }>();

const groups = computed(() => {
    const out: { name: string; items: NavItem[] }[] = [];
    for (const item of props.items) {
        let g = out.find((x) => x.name === item.group);
        if (!g) { g = { name: item.group, items: [] }; out.push(g); }
        g.items.push(item);
    }
    return out;
});

function path(href: string): string {
    try { return new URL(href, 'http://x').pathname; } catch { return href; }
}
const current = computed(() => path(props.currentUrl));
/** Longest matching prefix wins, so /documents/reports highlights "Document reports", not "Documents". */
const activeHref = computed(() => {
    let best: string | null = null;
    for (const item of props.items) {
        const p = path(item.href);
        const match = p === '/' ? current.value === '/' : current.value === p || current.value.startsWith(p + '/');
        if (match && (best === null || p.length > path(best).length)) best = item.href;
    }
    return best;
});
</script>

<template>
    <nav
        class="flex h-full flex-col"
        aria-label="Main"
    >
        <div class="flex h-16 items-center gap-2 px-4">
            <img
                src="/images/infernix-logo.svg"
                alt="Infernix Evolv"
                class="h-9"
            >
        </div>
        <div class="flex-1 space-y-4 overflow-y-auto px-3 pb-4">
            <div
                v-for="g in groups"
                :key="g.name"
            >
                <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-surface-400">
                    {{ g.name }}
                </p>
                <ul class="space-y-0.5">
                    <li
                        v-for="item in g.items"
                        :key="item.href"
                    >
                        <Link
                            :href="item.href"
                            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
                            :class="activeHref === item.href ? 'bg-brand-50 text-brand-700' : 'text-surface-600 hover:bg-surface-100 hover:text-surface-900'"
                            :aria-current="activeHref === item.href ? 'page' : undefined"
                            :data-test="`nav-${item.label}`"
                            @click="$emit('navigate')"
                        >
                            <Icon :name="item.icon ?? 'document'" />
                            {{ item.label }}
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
        <p
            v-if="companyName"
            class="border-t border-surface-100 px-6 py-3 text-xs text-surface-400"
        >
            {{ companyName }}
        </p>
    </nav>
</template>
