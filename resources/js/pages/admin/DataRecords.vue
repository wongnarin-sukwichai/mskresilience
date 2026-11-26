<script setup lang="ts">
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import Pager from '@/components/admin/Pager.vue';
import TorRef from '@/components/admin/TorRef.vue';
import { defaultWorkGroupTheme, workGroupIcons, workGroupThemes } from '@/data/workGroupIcons';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

// แก้ไขข้อมูล (จัดการข้อมูล module, admin+staff, DataRecordController; TOR 4.1.1(6)-2). One กลุ่มงาน at a time,
// only the groups this user may import. Add / edit / delete one row: the server checks it with the same rules as an
// import file (App\Import\RecordEditor) and records it as its own batch, so it shows in ประวัตินำเข้า and can be
// cancelled there. Locked fields (the row's key, community, an event's start) can't change: delete and add again.
interface Column {
    name: string;
    rule: string;
    description: string | null;
    required: boolean;
    locked: boolean;
}
interface RecordRow {
    id: number;
    values: Record<string, string | null>;
    batchId: number | null;
    updatedAt: string | null;
}

const props = defineProps<{
    groups: { value: string; label: string }[];
    group: string | null;
    columns: Column[];
    records: {
        data: RecordRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    } | null;
    filters: { community: string; date: string };
    communities: { code: string; name: string }[];
    needsApproval: boolean;
}>();

const HAZARDS = [
    { value: 'FLOOD', label: 'อุทกภัย' },
    { value: 'FIRE', label: 'อัคคีภัย' },
    { value: 'STORM', label: 'วาตภัย' },
    { value: 'HEAT', label: 'ความร้อน' },
    { value: 'PM25', label: 'PM2.5' },
];

const groupLabel = computed(() => props.groups.find((g) => g.value === props.group)?.label ?? '');
// COMMUNITY_NAME is only a readable helper in files; the form picks the community by code.
const formColumns = computed(() => props.columns.filter((c) => c.rule !== 'community_name'));
const communityName = (code: string | null) => props.communities.find((c) => c.code === code)?.name ?? '';

const filterForm = reactive({ ...props.filters });
function go(params: Record<string, string>) {
    const query = Object.fromEntries(Object.entries({ group: props.group ?? '', ...filterForm, ...params }).filter(([, v]) => v !== ''));
    router.get(route('data-records.index'), query, { preserveState: true, preserveScroll: true, replace: true });
}
function pickGroup(value: string) {
    filterForm.community = '';
    filterForm.date = '';
    go({ group: value, community: '', date: '' });
}

// ── Add / edit modal ──
const mode = ref<'add' | 'edit' | null>(null);
const editing = ref<RecordRow | null>(null);
const form = useForm<{ values: Record<string, string> }>({ values: {} });
const errors = computed(() => form.errors as Record<string, string>);

function openAdd() {
    editing.value = null;
    form.clearErrors();
    form.values = Object.fromEntries(formColumns.value.map((c) => [c.name, '']));
    if (filterForm.community) form.values.COMMUNITY_ID = filterForm.community;
    mode.value = 'add';
}
function openEdit(row: RecordRow) {
    editing.value = row;
    form.clearErrors();
    form.values = Object.fromEntries(formColumns.value.map((c) => [c.name, row.values[c.name] ?? '']));
    mode.value = 'edit';
}
function close() {
    if (form.processing) return;
    mode.value = null;
    editing.value = null;
}
function save() {
    if (!props.group) return;
    const options = { preserveScroll: true, onSuccess: () => close() };
    if (mode.value === 'add') form.post(route('data-records.store', props.group), options);
    else if (editing.value) form.put(route('data-records.update', [props.group, editing.value.id]), options);
}

const isLocked = (c: Column) => mode.value === 'edit' && c.locked;

// hazard_list ↔ checkboxes
function hasHazard(name: string, key: string) {
    return (form.values[name] ?? '').split(',').includes(key);
}
function toggleHazard(name: string, key: string) {
    const set = new Set((form.values[name] ?? '').split(',').filter(Boolean));
    if (set.has(key)) set.delete(key);
    else set.add(key);
    form.values[name] = HAZARDS.map((h) => h.value)
        .filter((h) => set.has(h))
        .join(',');
}

// ── Delete ──
const confirmingDelete = ref(false);
const deleting = ref(false);
function deleteRow() {
    if (!props.group || !editing.value) return;
    router.delete(route('data-records.destroy', [props.group, editing.value.id]), {
        preserveScroll: true,
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
        },
        onSuccess: () => close(),
        onError: (e) => {
            if (e._) form.setError('_', e._);
        },
    });
}

const rowLabel = computed(() => {
    const v = editing.value?.values;
    if (!v) return '';
    const date = v.ASSESSMENT_DATE ?? v.EVENT_START;
    const shown = date ? date.split('-').reverse().join('/') : null;
    return [v.EVENT_ID, v.COMMUNITY_ID, communityName(v.COMMUNITY_ID ?? null), shown].filter(Boolean).join(' · ');
});

function displayCell(c: Column, value: string | null) {
    if (value === null || value === '') return '–';
    if (c.rule === 'date' || c.rule === 'optional_date') {
        const [y, m, d] = value.split('-');
        return `${d}/${m}/${y}`;
    }
    return value;
}

const themeOf = (value: string) => workGroupThemes[value] ?? defaultWorkGroupTheme;
const inputClass =
    'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="แก้ไขข้อมูล" />

    <AdminLayout title="แก้ไขข้อมูล">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">แก้ไขข้อมูล<TorRef :refs="['4.1.1(6)-2', 'คุณลักษณะ 3-2']" /></h1>
            <p class="mt-1 text-sm text-slate-500">
                เพิ่ม แก้ไข หรือลบข้อมูลทีละแถวในกลุ่มงานที่คุณได้รับสิทธิ์ ระบบตรวจค่าด้วยกฎเดียวกับการนำเข้าไฟล์
                ทุกการเปลี่ยนแปลงมีในประวัตินำเข้าและยกเลิกได้
            </p>
        </div>

        <div v-if="!groups.length" class="rounded-2xl border border-slate-100 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
            <i class="fa-solid fa-lock mb-2 block text-xl text-slate-300"></i>
            ยังไม่ได้รับสิทธิ์แก้ไขข้อมูลกลุ่มงานใด กรุณาติดต่อผู้ดูแลระบบ
        </div>

        <template v-else>
            <!-- Group chips -->
            <div class="mb-4 flex flex-wrap gap-2">
                <button
                    v-for="g in groups"
                    :key="g.value"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium transition"
                    :class="
                        g.value === group ? [themeOf(g.value).tile, 'ring-2 ring-blue-500'] : 'bg-white text-slate-600 shadow-sm hover:bg-slate-50'
                    "
                    @click="pickGroup(g.value)"
                >
                    <i :class="workGroupIcons[g.value] ?? 'fa-solid fa-table'"></i>{{ g.label }}
                </button>
            </div>

            <p v-if="needsApproval" class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <i class="fa-solid fa-hourglass-half mr-1.5"></i>ระบบเปิดโหมดอนุมัติ การเพิ่ม แก้ไข หรือลบ จะส่งให้ผู้ดูแลระบบอนุมัติก่อน
                ข้อมูลจะเปลี่ยนเมื่อได้รับอนุมัติ
            </p>

            <!-- Filters -->
            <div class="mb-4 flex flex-wrap items-end gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                <label class="block min-w-[14rem]">
                    <span class="mb-1 block text-xs font-medium text-slate-600">ชุมชน</span>
                    <select v-model="filterForm.community" :class="inputClass" @change="go({})">
                        <option value="">ทุกชุมชน</option>
                        <option v-for="c in communities" :key="c.code" :value="c.code">{{ c.code }} {{ c.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-600">{{
                        group === 'disaster_event' ? 'วันที่เริ่มเหตุการณ์' : 'วันที่ประเมิน'
                    }}</span>
                    <input v-model="filterForm.date" type="date" :class="inputClass" @change="go({})" />
                </label>
                <button
                    v-if="filterForm.community || filterForm.date"
                    type="button"
                    class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                    @click="
                        filterForm.community = '';
                        filterForm.date = '';
                        go({ community: '', date: '' });
                    "
                >
                    ล้างตัวกรอง
                </button>
                <span class="ml-auto text-xs text-slate-500">พบ {{ records?.total ?? 0 }} แถว</span>
                <button type="button" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="openAdd">
                    <i class="fa-solid fa-plus mr-1.5"></i>เพิ่มแถว
                </button>
            </div>

            <!-- Records -->
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <div v-if="records?.data.length" class="max-h-[560px] overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 z-10 bg-white">
                            <tr class="border-b border-slate-100 text-left text-xs text-slate-500">
                                <th
                                    v-for="c in formColumns"
                                    :key="c.name"
                                    class="whitespace-nowrap bg-white px-3 py-2 font-mono font-medium"
                                    :title="c.description ?? ''"
                                >
                                    {{ c.name }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in records.data"
                                :key="r.id"
                                class="cursor-pointer border-b border-slate-50 text-slate-700 hover:bg-blue-50/50"
                                title="คลิกเพื่อแก้ไขหรือลบ"
                                @click="openEdit(r)"
                            >
                                <td
                                    v-for="c in formColumns"
                                    :key="c.name"
                                    class="max-w-[14rem] truncate whitespace-nowrap px-3 py-2"
                                    :class="{ 'font-medium': c.locked }"
                                >
                                    {{ displayCell(c, r.values[c.name]) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="py-10 text-center text-sm text-slate-500">
                    <i class="fa-solid fa-table mb-2 block text-xl text-slate-300"></i>
                    {{ filterForm.community || filterForm.date ? 'ไม่พบข้อมูลที่ตรงกับเงื่อนไข' : `ยังไม่มีข้อมูล${groupLabel}` }}
                </div>
                <Pager v-if="records" :page="records" />
                <p class="mt-3 text-xs text-slate-400">
                    ต้องการแก้หลายแถวพร้อมกัน ใช้เมนู
                    <Link :href="route('data-import.index')" class="text-blue-600 hover:underline">นำเข้าข้อมูล</Link>
                    · ดูหรือยกเลิกการแก้ไขที่
                    <Link :href="route('import-history.index')" class="text-blue-600 hover:underline">ประวัตินำเข้า</Link>
                </p>
            </div>
        </template>

        <!-- Add / edit modal -->
        <div v-if="mode" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4" @click.self="close">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between bg-blue-600 px-6 py-4">
                    <h3 class="text-base font-semibold text-white">
                        {{ mode === 'add' ? `เพิ่มข้อมูล${groupLabel}` : `แก้ไขข้อมูล${groupLabel}` }}
                        <span v-if="mode === 'edit'" class="ml-1 text-sm font-normal text-blue-100">{{ rowLabel }}</span>
                    </h3>
                    <button type="button" class="text-blue-100 hover:text-white" aria-label="ปิด" @click="close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="save">
                    <div class="grid min-h-0 flex-1 grid-cols-1 gap-4 overflow-y-auto px-6 py-5 sm:grid-cols-2">
                        <p v-if="errors._" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 sm:col-span-2">
                            <i class="fa-solid fa-circle-exclamation mr-1.5"></i>{{ errors._ }}
                        </p>
                        <p v-if="mode === 'edit'" class="text-xs text-slate-500 sm:col-span-2">
                            <i class="fa-solid fa-lock mr-1 text-slate-400"></i>ช่องสีเทาแก้ไม่ได้ ถ้ากรอกผิด ให้ลบแถวนี้แล้วเพิ่มใหม่ · ช่องว่าง =
                            ไม่มีข้อมูล
                        </p>

                        <label
                            v-for="c in formColumns"
                            :key="c.name"
                            class="block"
                            :class="{ 'sm:col-span-2': c.rule === 'long_text' || c.rule === 'hazard_list' }"
                        >
                            <span class="mb-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xs font-semibold text-slate-700">{{ c.name }}</span>
                                <span v-if="c.required" class="text-red-500">*</span>
                            </span>
                            <span v-if="c.description" class="mb-1.5 block text-[11px] leading-snug text-slate-400">{{ c.description }}</span>

                            <div v-if="isLocked(c)" class="rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-600">
                                {{ displayCell(c, form.values[c.name]) }}
                                <span v-if="c.rule === 'community'" class="text-slate-400">{{ communityName(form.values[c.name]) }}</span>
                            </div>
                            <select v-else-if="c.rule === 'community'" v-model="form.values[c.name]" :class="inputClass">
                                <option value="">เลือกชุมชน</option>
                                <option v-for="o in communities" :key="o.code" :value="o.code">{{ o.code }} {{ o.name }}</option>
                            </select>
                            <input
                                v-else-if="c.rule === 'date' || c.rule === 'optional_date'"
                                v-model="form.values[c.name]"
                                type="date"
                                :class="inputClass"
                            />
                            <select v-else-if="c.rule === 'hazard'" v-model="form.values[c.name]" :class="inputClass">
                                <option value="">เลือกภัย</option>
                                <option v-for="h in HAZARDS" :key="h.value" :value="h.value">
                                    {{ h.label }} ({{ h.value === 'PM25' ? 'PM2.5' : h.value }})
                                </option>
                            </select>
                            <div v-else-if="c.rule === 'hazard_list'" class="flex flex-wrap gap-2">
                                <label
                                    v-for="h in HAZARDS"
                                    :key="h.value"
                                    class="flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-700"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="hasHazard(c.name, h.value)"
                                        class="h-3.5 w-3.5"
                                        @change="toggleHazard(c.name, h.value)"
                                    />
                                    {{ h.label }}
                                </label>
                            </div>
                            <select v-else-if="c.rule === 'yes_no'" v-model="form.values[c.name]" :class="inputClass">
                                <option value="">ไม่มีข้อมูล</option>
                                <option value="ใช่">ใช่</option>
                                <option value="ไม่ใช่">ไม่ใช่</option>
                            </select>
                            <select v-else-if="c.rule === 'score'" v-model="form.values[c.name]" :class="inputClass">
                                <option value="">ไม่มีข้อมูล</option>
                                <option v-for="n in 6" :key="n" :value="String(n - 1)">{{ n - 1 }}</option>
                            </select>
                            <textarea
                                v-else-if="c.rule === 'long_text'"
                                v-model="form.values[c.name]"
                                rows="3"
                                maxlength="2000"
                                :class="inputClass"
                            ></textarea>
                            <input
                                v-else-if="c.rule === 'count' || c.rule === 'decimal' || c.rule === 'percent'"
                                v-model="form.values[c.name]"
                                type="number"
                                min="0"
                                :max="c.rule === 'percent' ? 100 : undefined"
                                :step="c.rule === 'count' ? 1 : 0.01"
                                :class="inputClass"
                            />
                            <input
                                v-else
                                v-model="form.values[c.name]"
                                type="text"
                                :maxlength="c.rule === 'event_id' ? 6 : 255"
                                :placeholder="c.rule === 'event_id' ? 'เช่น EV0001' : ''"
                                :class="inputClass"
                            />
                            <span v-if="errors[c.name]" class="mt-1 block text-xs text-red-600">{{ errors[c.name] }}</span>
                        </label>
                    </div>

                    <div class="flex items-center gap-2 border-t border-slate-100 px-6 py-4">
                        <button
                            v-if="mode === 'edit'"
                            type="button"
                            class="rounded-xl border border-red-200 px-4 py-2 text-sm text-red-600 hover:bg-red-50"
                            @click="confirmingDelete = true"
                        >
                            <i class="fa-solid fa-trash-can mr-1.5"></i>ลบแถวนี้
                        </button>
                        <span v-if="needsApproval" class="text-xs text-amber-700"><i class="fa-solid fa-hourglass-half mr-1"></i>จะส่งรออนุมัติ</span>
                        <button
                            type="button"
                            class="ml-auto rounded-xl px-5 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
                            @click="close"
                        >
                            ยกเลิก
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            {{ form.processing ? 'กำลังบันทึก...' : needsApproval ? 'ส่งรออนุมัติ' : 'บันทึก' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmDialog
            :open="confirmingDelete"
            :title="`ลบแถว ${rowLabel}?`"
            :message="
                needsApproval
                    ? 'คำขอลบจะส่งให้ผู้ดูแลระบบอนุมัติก่อน'
                    : 'แถวนี้จะถูกลบออกจากระบบทันที ถ้าลบผิด ยกเลิกรายการลบได้ที่ประวัตินำเข้า (แถวจะกลับมาเหมือนเดิม)'
            "
            :confirm-label="needsApproval ? 'ส่งคำขอลบ' : 'ยืนยันลบ'"
            :processing="deleting"
            @confirm="deleteRow"
            @cancel="confirmingDelete = false"
        />
    </AdminLayout>
</template>
