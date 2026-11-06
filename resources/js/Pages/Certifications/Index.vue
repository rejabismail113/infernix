<script setup lang="ts">
/**
 * Certifications, memberships and renewals (M09). Reminders go out at 90/60/30 days; the renewal fee is a LINK
 * to the finance record that paid it (M09-04) — never a second expense.
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Amount from '@/Components/Finance/Amount.vue';
import FinanceLinker from '@/Components/Finance/FinanceLinker.vue';
import LinkedRecords from '@/Components/Finance/LinkedRecords.vue';
import SchemaFields from '@/Components/Form/SchemaFields.vue';
import Modal from '@/Components/Modal.vue';
import StatusChip from '@/Components/StatusChip.vue';
import DataTable from '@/Components/Table/DataTable.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fieldError } from '@/lib/errors';
import { blanksToNull, setField } from '@/lib/forms';
import type { LinkedDoc } from '@/types/finance';
import type { FieldDef, FinanceRecordSnapshot, Option } from '@/types/operations';
import type { TableColumn } from '@/types/table';

interface Cert { id: number; holder_type: string; staff_profile_id: number | null; holder: string; name: string; issuer: string; category: string | null; credential_no: string | null; issue_date: string | null; expiry_date: string | null; no_expiry: boolean; verification_url: string | null; status: string; notes: string | null; documents: LinkedDoc[]; can_edit: boolean }
interface Member { id: number; holder_type: string; staff_profile_id: number | null; holder: string; organisation: string; membership_no: string | null; tier: string | null; period_start: string; period_end: string; auto_renew: boolean; annual_fee: string | null; status: string; notes: string | null; can_edit: boolean }
interface Renewal { id: number; type: string; renewable_id: number; name: string; holder: string | null; due_date: string; days: number; status: string; renewed_on: string | null; new_expiry_date: string | null; note: string | null; costs: FinanceRecordSnapshot[]; paid: string }
const props = defineProps<{ tab: string; certifications: Cert[]; memberships: Member[]; renewals: Renewal[]; staff: Option[]; can: { create_cert: boolean; create_membership: boolean; link_finance: boolean } }>();
const tab = ref<string>(['certifications', 'memberships', 'renewals'].includes(props.tab) ? props.tab : 'certifications');
const staffOptions = computed(() => props.staff.map((s) => ({ value: s.id, label: s.label })));
const holderFields = computed<FieldDef[]>(() => [{ key: 'holder_type', label: 'Holder', type: 'select', options: [{ value: 'staff', label: 'Staff member' }, { value: 'company', label: 'Company' }], required: true },
    { key: 'staff_profile_id', label: 'Staff member', type: 'select', options: staffOptions.value }]);
const certCols: TableColumn[] = [{ key: 'name', label: 'Certification', sortable: true }, { key: 'issuer', label: 'Issuer', sortable: true }, { key: 'holder', label: 'Holder', sortable: true },
    { key: 'credential_no', label: 'Credential no' }, { key: 'expiry_date', label: 'Expires', sortable: true }, { key: 'status', label: 'Status' }];
const memCols: TableColumn[] = [{ key: 'organisation', label: 'Organisation', sortable: true }, { key: 'holder', label: 'Holder' }, { key: 'membership_no', label: 'Membership no' },
    { key: 'period_end', label: 'Renews', sortable: true }, { key: 'annual_fee', label: 'Annual fee', numeric: true }, { key: 'status', label: 'Status' }];

const certOpen = ref(false); const certEditing = ref<Cert | null>(null);
const cert = useForm({ holder_type: 'staff', staff_profile_id: '' as number | '', name: '', issuer: '', category: '', credential_no: '', issue_date: '', expiry_date: '', no_expiry: false, verification_url: '', notes: '' });
function openCert(c: Cert | null) {
    certEditing.value = c;
    cert.defaults(c ? { holder_type: c.holder_type, staff_profile_id: c.staff_profile_id ?? '', name: c.name, issuer: c.issuer, category: c.category ?? '', credential_no: c.credential_no ?? '', issue_date: c.issue_date ?? '', expiry_date: c.expiry_date ?? '', no_expiry: c.no_expiry, verification_url: c.verification_url ?? '', notes: c.notes ?? '' }
        : { holder_type: 'staff', staff_profile_id: '', name: '', issuer: '', category: '', credential_no: '', issue_date: '', expiry_date: '', no_expiry: false, verification_url: '', notes: '' });
    cert.reset(); cert.clearErrors(); certOpen.value = true;
}
const saveCert = () => {
    const t = cert.transform((d) => blanksToNull(d, ['staff_profile_id', 'issue_date', 'expiry_date', 'verification_url']));
    const opts = { preserveScroll: true, onSuccess: () => { certOpen.value = false; } };
    if (certEditing.value) t.put(route('certifications.certifications.update', certEditing.value.id), opts); else t.post(route('certifications.certifications.store'), opts);
};
const memOpen = ref(false); const memEditing = ref<Member | null>(null);
const mem = useForm({ holder_type: 'company', staff_profile_id: '' as number | '', organisation: '', membership_no: '', tier: '', period_start: '', period_end: '', auto_renew: false, annual_fee: '', notes: '' });
function openMem(m: Member | null) {
    memEditing.value = m;
    mem.defaults(m ? { holder_type: m.holder_type, staff_profile_id: m.staff_profile_id ?? '', organisation: m.organisation, membership_no: m.membership_no ?? '', tier: m.tier ?? '', period_start: m.period_start, period_end: m.period_end, auto_renew: m.auto_renew, annual_fee: m.annual_fee ?? '', notes: m.notes ?? '' }
        : { holder_type: 'company', staff_profile_id: '', organisation: '', membership_no: '', tier: '', period_start: '', period_end: '', auto_renew: false, annual_fee: '', notes: '' });
    mem.reset(); mem.clearErrors(); memOpen.value = true;
}
const saveMem = () => {
    const t = mem.transform((d) => blanksToNull(d, ['staff_profile_id', 'annual_fee']));
    const opts = { preserveScroll: true, onSuccess: () => { memOpen.value = false; } };
    if (memEditing.value) t.put(route('certifications.memberships.update', memEditing.value.id), opts); else t.post(route('certifications.memberships.store'), opts);
};
const renewing = ref<Renewal | null>(null);
const rn = useForm({ renewed_on: new Date().toISOString().slice(0, 10), new_expiry_date: '', note: '' });
const doRenew = () => rn.post(route('certifications.renewals.renew', renewing.value?.id ?? 0), { preserveScroll: true, onSuccess: () => { renewing.value = null; } });
const lapse = (r: Renewal) => { const note = window.prompt('Why is it lapsing?'); if (note) router.post(route('certifications.renewals.lapse', r.id), { note }, { preserveScroll: true }); };
const tone = (days: number) => (days < 0 ? 'expired' : days <= 30 ? 'rejected' : days <= 60 ? 'pending' : days <= 90 ? 'submitted' : 'active');
</script>

<template>
    <AppLayout
        title="Certifications & memberships"
        :breadcrumbs="[{ label: 'People' }, { label: 'Certifications' }]"
    >
        <template #actions>
            <Link
                :href="route('reports.view', 'certifications.expiring')"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Expiring report
            </Link>
            <Button
                v-if="can.create_cert && tab === 'certifications'"
                data-test="add-cert"
                @click="openCert(null)"
            >
                Add certification
            </Button>
            <Button
                v-if="can.create_membership && tab === 'memberships'"
                data-test="add-membership"
                @click="openMem(null)"
            >
                Add membership
            </Button>
        </template>
        <nav
            class="mb-4 flex gap-4 border-b border-surface-200 text-sm"
            role="tablist"
        >
            <button
                v-for="t in ['certifications', 'memberships', 'renewals']"
                :key="t"
                type="button"
                role="tab"
                :aria-selected="tab === t"
                :data-test="`tab-${t}`"
                class="-mb-px border-b-2 px-1 py-2 capitalize"
                :class="tab === t ? 'border-brand-600 font-medium text-brand-700' : 'border-transparent text-surface-500 hover:text-surface-800'"
                @click="tab = t"
            >
                {{ t }}
            </button>
        </nav>

        <DataTable
            v-if="tab === 'certifications'"
            :columns="certCols"
            :rows="certifications"
            prefs-key="certs"
            empty-text="No certifications recorded."
        >
            <template #cell-name="{ row }">
                <button
                    type="button"
                    class="text-left font-medium text-brand-700 hover:underline"
                    :disabled="!row.can_edit"
                    @click="openCert(row)"
                >
                    {{ row.name }}
                </button>
                <a
                    v-if="row.verification_url"
                    :href="row.verification_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ml-2 text-xs text-surface-500 hover:underline"
                >verify</a>
            </template>
            <template #cell-expiry_date="{ row }">
                {{ row.no_expiry ? 'No expiry' : row.expiry_date }}
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>

        <DataTable
            v-if="tab === 'memberships'"
            :columns="memCols"
            :rows="memberships"
            prefs-key="memberships"
            empty-text="No memberships recorded."
        >
            <template #cell-organisation="{ row }">
                <button
                    type="button"
                    class="text-left font-medium text-brand-700 hover:underline"
                    :disabled="!row.can_edit"
                    @click="openMem(row)"
                >
                    {{ row.organisation }}
                </button>
            </template>
            <template #cell-annual_fee="{ row }">
                <Amount :value="row.annual_fee" />
            </template>
            <template #cell-status="{ row }">
                <StatusChip :status="row.status" />
            </template>
        </DataTable>

        <div
            v-if="tab === 'renewals'"
            class="space-y-3"
        >
            <Card
                v-for="r in renewals"
                :key="r.id"
                :title="`${r.name} — ${r.holder ?? ''}`"
                :description="`${r.type} · due ${r.due_date}`"
            >
                <template #actions>
                    <StatusChip
                        :status="r.status === 'open' ? tone(r.days) : r.status"
                        :label="r.status === 'open' ? (r.days < 0 ? `overdue ${-r.days}d` : `${r.days} days`) : r.status"
                    />
                    <Button
                        v-if="r.status === 'open'"
                        :data-test="`renew-${r.id}`"
                        @click="renewing = r; rn.reset()"
                    >
                        Record renewal
                    </Button>
                    <Button
                        v-if="r.status === 'open'"
                        variant="secondary"
                        @click="lapse(r)"
                    >
                        Let lapse
                    </Button>
                </template>
                <p
                    v-if="r.renewed_on"
                    class="text-sm"
                >
                    Renewed {{ r.renewed_on }} until {{ r.new_expiry_date }}. {{ r.note }}
                </p>
                <p class="text-sm text-surface-600">
                    Renewal paid (linked): <Amount :value="r.paid" />
                </p>
                <LinkedRecords
                    v-if="can.link_finance"
                    :records="r.costs"
                    empty="No renewal fee linked."
                />
                <FinanceLinker
                    v-if="can.link_finance && r.status !== 'cancelled'"
                    class="mt-2"
                    :link-url="route('certifications.renewals.link', r.id)"
                    :types="['bill', 'transaction', 'claim']"
                    label="Link the renewal fee"
                />
            </Card>
            <p
                v-if="!renewals.length"
                class="text-sm text-surface-500"
            >
                No renewals scheduled.
            </p>
        </div>

        <Modal
            :open="certOpen"
            :title="certEditing ? `Edit ${certEditing.name}` : 'Add certification'"
            @close="certOpen = false"
        >
            <form
                id="cert-form"
                class="space-y-2"
                @submit.prevent="saveCert"
            >
                <SchemaFields
                    :fields="[...holderFields, { key: 'name', label: 'Certification', type: 'text', required: true }, { key: 'issuer', label: 'Issuer', type: 'text', required: true },
                              { key: 'category', label: 'Category', type: 'text' }, { key: 'credential_no', label: 'Credential no', type: 'text' }, { key: 'issue_date', label: 'Issued', type: 'date' },
                              { key: 'expiry_date', label: 'Expires', type: 'date' }, { key: 'no_expiry', label: 'No expiry', type: 'checkbox' }, { key: 'verification_url', label: 'Verification URL', type: 'url', span: 2 }, { key: 'notes', label: 'Notes', type: 'textarea' }]"
                    :values="cert"
                    :errors="cert.errors"
                    id-prefix="ce"
                    @set="(k, v) => setField(cert, k, v)"
                />
                <p
                    v-if="fieldError(cert.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(cert.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="cert-form"
                    :disabled="cert.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>

        <Modal
            :open="memOpen"
            :title="memEditing ? `Edit ${memEditing.organisation}` : 'Add membership'"
            @close="memOpen = false"
        >
            <form
                id="mem-form"
                class="space-y-2"
                @submit.prevent="saveMem"
            >
                <SchemaFields
                    :fields="[...holderFields, { key: 'organisation', label: 'Organisation', type: 'text', required: true }, { key: 'membership_no', label: 'Membership no', type: 'text' }, { key: 'tier', label: 'Tier', type: 'text' },
                              { key: 'annual_fee', label: 'Annual fee (info)', type: 'money' }, { key: 'period_start', label: 'From', type: 'date', required: true }, { key: 'period_end', label: 'Until', type: 'date', required: true },
                              { key: 'auto_renew', label: 'Auto-renews', type: 'checkbox' }, { key: 'notes', label: 'Notes', type: 'textarea' }]"
                    :values="mem"
                    :errors="mem.errors"
                    id-prefix="me"
                    @set="(k, v) => setField(mem, k, v)"
                />
                <p
                    v-if="fieldError(mem.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(mem.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="mem-form"
                    :disabled="mem.processing"
                >
                    Save
                </Button>
            </template>
        </Modal>

        <Modal
            :open="renewing !== null"
            :title="`Record renewal: ${renewing?.name ?? ''}`"
            size="md"
            @close="renewing = null"
        >
            <form
                id="renew-form"
                class="space-y-2"
                @submit.prevent="doRenew"
            >
                <SchemaFields
                    :fields="[{ key: 'renewed_on', label: 'Renewed on', type: 'date', required: true }, { key: 'new_expiry_date', label: 'New expiry', type: 'date', required: true }, { key: 'note', label: 'Note', type: 'textarea' }]"
                    :values="rn"
                    :errors="rn.errors"
                    id-prefix="rn"
                    @set="(k, v) => setField(rn, k, v)"
                />
                <p class="text-xs text-surface-500">
                    Record the fee in Finance (bill, expense or claim) and link it here — no expense is created by the renewal.
                </p>
                <p
                    v-if="fieldError(rn.errors, 'general')"
                    class="text-sm text-red-700"
                    role="alert"
                >
                    {{ fieldError(rn.errors, 'general') }}
                </p>
            </form>
            <template #footer>
                <Button
                    type="submit"
                    form="renew-form"
                    :disabled="rn.processing"
                >
                    Save renewal
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
