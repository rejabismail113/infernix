<script setup lang="ts">
/**
 * Picker modal with tabs + search (reference: "Inventory" picker). Items come from the parent (usually a
 * server search endpoint that already applies permissions). Keyboard: ↑/↓ moves, Enter selects, Esc closes.
 * Search input is debounced and emitted so the parent can query the server.
 */
import { computed, ref, watch } from 'vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';

export interface PickerTab { key: string; label: string }
export interface PickerItem { id: number | string; label: string; meta?: string }

const props = withDefaults(defineProps<{
    open: boolean;
    title: string;
    tabs?: PickerTab[];
    activeTab?: string | null;
    items: PickerItem[];
    loading?: boolean;
    confirmLabel?: string;
    debounceMs?: number;
    /** filter `items` locally as well (for small static lists) */
    localFilter?: boolean;
}>(), { tabs: () => [], activeTab: null, loading: false, confirmLabel: 'Select', debounceMs: 250, localFilter: false });

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'select', item: PickerItem, tab: string | null): void;
    (e: 'update:activeTab', key: string): void;
    (e: 'search', term: string, tab: string | null): void;
}>();

const term = ref('');
const highlighted = ref(0);
const selected = ref<PickerItem | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;

const shown = computed(() => {
    if (!props.localFilter || term.value.trim() === '') return props.items;
    const t = term.value.trim().toLowerCase();
    return props.items.filter((i) => i.label.toLowerCase().includes(t) || (i.meta ?? '').toLowerCase().includes(t));
});

watch(term, (value) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => emit('search', value.trim(), props.activeTab), props.debounceMs);
    highlighted.value = 0;
});
watch(() => props.items, () => { highlighted.value = 0; selected.value = null; });
watch(() => props.open, (open) => { if (open) { term.value = ''; selected.value = null; } });

function switchTab(key: string) {
    emit('update:activeTab', key);
    emit('search', term.value.trim(), key);
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); highlighted.value = Math.min(highlighted.value + 1, shown.value.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted.value = Math.max(highlighted.value - 1, 0); }
    else if (e.key === 'Enter') {
        e.preventDefault();
        const item = shown.value[highlighted.value];
        if (item) confirm(item);
    }
}

function confirm(item: PickerItem | null) {
    if (!item) return;
    emit('select', item, props.activeTab);
    emit('close');
}
</script>

<template>
    <Modal
        :open="open"
        :title="title"
        size="lg"
        @close="emit('close')"
    >
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <div
                v-if="tabs.length"
                role="tablist"
                class="flex rounded-md bg-surface-100 p-0.5 text-sm"
            >
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === t.key"
                    :data-test="`tab-${t.key}`"
                    class="rounded px-3 py-1.5 font-medium"
                    :class="activeTab === t.key ? 'bg-brand-50 text-brand-700 shadow-sm' : 'text-surface-600 hover:text-surface-900'"
                    @click="switchTab(t.key)"
                >
                    {{ t.label }}
                </button>
            </div>
            <label class="relative ml-auto block w-full max-w-sm">
                <span class="sr-only">Search</span>
                <Icon
                    name="search"
                    :size="16"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-surface-400"
                />
                <input
                    v-model="term"
                    data-autofocus
                    data-test="picker-search"
                    type="search"
                    placeholder="Search"
                    class="w-full rounded-md border border-surface-300 py-2 pl-9 pr-3 text-sm"
                    role="combobox"
                    aria-controls="picker-list"
                    :aria-expanded="true"
                    :aria-activedescendant="shown[highlighted] ? `picker-item-${shown[highlighted].id}` : undefined"
                    @keydown="onKey"
                >
            </label>
        </div>

        <ul
            id="picker-list"
            role="listbox"
            class="max-h-80 overflow-y-auto rounded-lg border border-surface-200"
            :aria-busy="loading"
        >
            <li
                v-if="loading"
                class="px-3 py-6 text-center text-sm text-surface-400"
            >
                Searching…
            </li>
            <template v-else>
                <li
                    v-for="(item, i) in shown"
                    :id="`picker-item-${item.id}`"
                    :key="item.id"
                    role="option"
                    :aria-selected="selected?.id === item.id"
                    :data-test="`picker-item-${item.id}`"
                    class="flex cursor-pointer items-center justify-between border-b border-surface-100 px-3 py-2 text-sm last:border-0"
                    :class="[i === highlighted ? 'bg-brand-50' : 'hover:bg-surface-50', selected?.id === item.id ? 'font-semibold text-brand-700' : '']"
                    @mouseenter="highlighted = i"
                    @click="selected = item"
                    @dblclick="confirm(item)"
                >
                    <span>{{ item.label }}</span>
                    <span
                        v-if="item.meta"
                        class="text-xs text-surface-500"
                    >{{ item.meta }}</span>
                </li>
                <li
                    v-if="shown.length === 0"
                    class="px-3 py-6 text-center text-sm text-surface-400"
                    data-test="picker-empty"
                >
                    Nothing found.
                </li>
            </template>
        </ul>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-surface-300 px-4 py-2 text-sm hover:bg-surface-50"
                @click="emit('close')"
            >
                Cancel
            </button>
            <button
                type="button"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
                :disabled="!selected"
                data-test="picker-confirm"
                @click="confirm(selected)"
            >
                {{ confirmLabel }}
            </button>
        </template>
    </Modal>
</template>
