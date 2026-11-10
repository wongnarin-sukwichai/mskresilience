<script setup lang="ts">
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { CriteriaSection, CriteriaTab, CriteriaWeights, ScoringCriteriaSet } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    sets: ScoringCriteriaSet[];
    dataYears: number[];
    currentFiscalYear: number;
    schema: CriteriaTab[];
}>();

// ---------------------------------------------------------------------------------------------------------
// Selected set + editor form. The form is (re)loaded from props only on explicit selection or after a
// successful action — never via a props watcher, which would wipe the admin's edits when a save comes back
// with validation errors.
// ---------------------------------------------------------------------------------------------------------
// Sets come sorted by start_fiscal_year. A fiscal year resolves to the set with the latest start ≤ it, or the
// earliest set for older years (App\Scoring\CriteriaSets::resolve).
function setForYear(year: number): ScoringCriteriaSet | null {
    return [...props.sets].reverse().find((s) => s.start_fiscal_year <= year) ?? props.sets[0] ?? null;
}

function defaultSelectedId(): number | null {
    return setForYear(props.currentFiscalYear)?.id ?? null;
}

const selectedId = ref<number | null>(defaultSelectedId());
const selectedSet = computed(() => props.sets.find((s) => s.id === selectedId.value) ?? null);
const inUse = computed(() => (selectedSet.value?.used_years.length ?? 0) > 0);
const isLocked = computed(() => !selectedSet.value || selectedSet.value.is_locked || inUse.value);

function coverageText(set: ScoringCriteriaSet): string {
    if (set.end_fiscal_year === null) return `ปีงบ ${set.start_fiscal_year} เป็นต้นไป`;
    if (set.end_fiscal_year === set.start_fiscal_year) return `ปีงบ ${set.start_fiscal_year}`;
    return `ปีงบ ${set.start_fiscal_year}–${set.end_fiscal_year}`;
}

const form = useForm({
    name: '',
    start_fiscal_year: 0 as number,
    weights: {} as CriteriaWeights,
});

function loadSelected() {
    const set = selectedSet.value;
    if (!set) return;
    form.defaults({ name: set.name, start_fiscal_year: set.start_fiscal_year, weights: JSON.parse(JSON.stringify(set.weights)) });
    form.reset();
    form.clearErrors();
}
loadSelected();

const activeTab = ref(props.schema[0]?.key ?? '');
const currentTab = computed(() => props.schema.find((t) => t.key === activeTab.value) ?? props.schema[0]);

// Switching to another set with unsaved edits asks first.
const pendingSelectId = ref<number | null>(null);

function selectSet(id: number) {
    if (id === selectedId.value) return;
    if (form.isDirty) {
        pendingSelectId.value = id;
        return;
    }
    selectedId.value = id;
    loadSelected();
}

function confirmDiscardAndSwitch() {
    if (pendingSelectId.value !== null) selectedId.value = pendingSelectId.value;
    pendingSelectId.value = null;
    loadSelected();
}

// ---------------------------------------------------------------------------------------------------------
// Client-side validation — mirrors App\Scoring\CriteriaSchema::validate() so problems show while typing;
// the server re-checks everything on save.
// ---------------------------------------------------------------------------------------------------------
function cellValue(section: CriteriaSection, rowKey: string, colKey: string): unknown {
    return form.weights[section.key]?.[rowKey]?.[colKey];
}

function cellError(section: CriteriaSection, rowKey: string, colKey: string): string | null {
    const serverError = (form.errors as Record<string, string>)[`weights.${section.key}.${rowKey}.${colKey}`];
    if (section.fixed) return null;
    const value = cellValue(section, rowKey, colKey);
    if (typeof value !== 'number' || Number.isNaN(value)) return 'กรุณากรอกตัวเลข';
    if (value < 0 || value > 1) return 'ค่าต้องอยู่ระหว่าง 0 ถึง 1';
    if (Math.abs(value * 10000 - Math.round(value * 10000)) > 1e-6) return 'ทศนิยมได้ไม่เกิน 4 ตำแหน่ง';
    return serverError ?? null;
}

function rowSum(section: CriteriaSection, row: CriteriaSection['rows'][number]): number {
    return Object.keys(row.cells)
        .filter((col) => row.cells[col] !== null)
        .reduce((sum, col) => {
            const value = cellValue(section, row.key, col);
            return sum + (typeof value === 'number' && !Number.isNaN(value) ? value : 0);
        }, 0);
}

function rowSumOk(section: CriteriaSection, row: CriteriaSection['rows'][number]): boolean {
    return !section.sum || Math.abs(rowSum(section, row) - 1) <= 0.00005;
}

function rowHasProblem(section: CriteriaSection, row: CriteriaSection['rows'][number]): boolean {
    if (section.fixed) return false;
    const cellProblem = Object.keys(row.cells).some((col) => row.cells[col] !== null && cellError(section, row.key, col));
    return cellProblem || !rowSumOk(section, row);
}

function tabHasProblem(tab: CriteriaTab): boolean {
    return tab.sections.some((section) => section.rows.some((row) => rowHasProblem(section, row)));
}

const formHasProblem = computed(
    () => props.schema.some(tabHasProblem) || form.name.trim() === '' || !Number.isInteger(form.start_fiscal_year) || form.start_fiscal_year < 2500,
);

function formatSum(value: number): string {
    const rounded = Number(value.toFixed(4));
    const decimals = (rounded.toString().split('.')[1] ?? '').length;
    return rounded.toFixed(Math.max(2, decimals));
}

function sumDiffText(section: CriteriaSection, row: CriteriaSection['rows'][number]): string {
    const diff = Number((rowSum(section, row) - 1).toFixed(4));
    return diff > 0 ? `เกิน ${formatSum(diff)}` : `ขาด ${formatSum(-diff)}`;
}

function warnAboveHit(section: CriteriaSection, colKey: string): boolean {
    const value = cellValue(section, 'ALL', colKey);
    return section.warnAbove !== null && typeof value === 'number' && value > section.warnAbove;
}

// ---------------------------------------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------------------------------------
function save() {
    if (!selectedSet.value) return;
    form.put(route('scoring-criteria.update', selectedSet.value.id), {
        preserveScroll: true,
        onSuccess: () => loadSelected(),
    });
}

function postAction(name: 'lock' | 'unlock') {
    if (!selectedSet.value) return;
    router.post(route(`scoring-criteria.${name}`, selectedSet.value.id), {}, { preserveScroll: true, onSuccess: () => loadSelected() });
}

// Clone
const cloneOpen = ref(false);
const cloneForm = useForm({ name: '', start_fiscal_year: 0 as number, copy_from: 0 });

// A new set can only start after every year that already has data (otherwise that data would move to it).
const suggestedStartYear = computed(() => Math.max(...props.sets.map((s) => s.start_fiscal_year), ...props.dataYears) + 1);

function openClone() {
    if (!selectedSet.value) return;
    cloneForm.clearErrors();
    cloneForm.name = `${selectedSet.value.name} (สำเนา)`;
    cloneForm.start_fiscal_year = suggestedStartYear.value;
    cloneForm.copy_from = selectedSet.value.id;
    cloneOpen.value = true;
}

function submitClone() {
    const newName = cloneForm.name.trim();
    cloneForm.post(route('scoring-criteria.store'), {
        preserveScroll: true,
        onSuccess: () => {
            cloneOpen.value = false;
            const created = props.sets.find((s) => s.name === newName);
            if (created) {
                selectedId.value = created.id;
                loadSelected();
            }
        },
    });
}

// Delete
const deleteOpen = ref(false);
const deleting = ref(false);
const canDelete = computed(() => !isLocked.value && props.sets.length > 1);
const deleteBlockedReason = computed(() => {
    if (inUse.value) return 'ชุดนี้มีข้อมูลใช้คำนวณแล้ว ลบไม่ได้';
    if (selectedSet.value?.is_locked) return 'ปลดล็อกก่อนจึงจะลบได้';
    if (props.sets.length <= 1) return 'ต้องมีชุดเกณฑ์อย่างน้อย 1 ชุด';
    return '';
});

function destroySet() {
    if (!selectedSet.value) return;
    deleting.value = true;
    router.delete(route('scoring-criteria.destroy', selectedSet.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
            selectedId.value = defaultSelectedId();
            loadSelected();
        },
        onFinish: () => (deleting.value = false),
    });
}

function formatDate(value: string) {
    return new Date(value).toLocaleDateString('th-TH-u-ca-buddhist', { year: 'numeric', month: 'short', day: 'numeric' });
}

const inputClass =
    'w-24 rounded-lg border px-2.5 py-1.5 text-right font-mono text-sm focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500';
</script>

<template>
    <Head title="จัดการเกณฑ์คะแนน" />

    <AdminLayout title="จัดการเกณฑ์คะแนน">
        <div class="grid gap-5 xl:grid-cols-4">
            <!-- Set list -->
            <div class="h-fit overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-1">
                <div class="border-b border-slate-100 p-5">
                    <h2 class="font-semibold text-slate-800">ชุดเกณฑ์คะแนน</h2>
                    <p class="mt-0.5 text-sm text-slate-400">ทั้งหมด {{ sets.length }} ชุด · แต่ละชุดใช้กับข้อมูลตามปีงบประมาณ</p>
                </div>
                <ul class="divide-y divide-slate-100">
                    <li v-for="set in sets" :key="set.id">
                        <button
                            type="button"
                            class="flex w-full flex-col gap-1.5 px-5 py-3.5 text-left transition-colors"
                            :class="set.id === selectedId ? 'bg-blue-50/70' : 'hover:bg-slate-50'"
                            @click="selectSet(set.id)"
                        >
                            <span class="text-sm font-medium" :class="set.id === selectedId ? 'text-blue-700' : 'text-slate-700'">{{
                                set.name
                            }}</span>
                            <span class="text-xs font-medium text-slate-500"><i class="fa-regular fa-calendar mr-1"></i>{{ coverageText(set) }}</span>
                            <span class="flex flex-wrap items-center gap-1.5 text-xs">
                                <span
                                    v-if="set.used_years.length"
                                    class="rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-600"
                                    :title="`มีข้อมูลปีงบ ${set.used_years.join(', ')}`"
                                >
                                    <i class="fa-solid fa-database mr-0.5"></i>มีข้อมูลใช้แล้ว
                                </span>
                                <span
                                    v-if="set.is_locked || set.used_years.length"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-500"
                                >
                                    <i class="fa-solid fa-lock mr-0.5"></i>ล็อก
                                </span>
                                <span v-else class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-600">
                                    <i class="fa-solid fa-lock-open mr-0.5"></i>แก้ไขได้
                                </span>
                                <span class="text-slate-400">แก้ไข {{ formatDate(set.updated_at) }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Editor -->
            <div v-if="selectedSet" class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-3">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5">
                    <div class="min-w-0 flex-1">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ชื่อชุดเกณฑ์</label>
                        <input
                            v-model="form.name"
                            type="text"
                            :disabled="isLocked"
                            class="w-full max-w-md rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 text-sm font-medium text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500"
                        />
                        <p v-if="form.errors.name" class="mt-1.5 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div class="w-48">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">เริ่มใช้ปีงบประมาณ (พ.ศ.)</label>
                        <input
                            v-model.number="form.start_fiscal_year"
                            type="number"
                            step="1"
                            min="2500"
                            max="2700"
                            :disabled="isLocked"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 font-mono text-sm font-medium text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500"
                        />
                        <p v-if="form.errors.start_fiscal_year" class="mt-1.5 text-xs text-red-600">{{ form.errors.start_fiscal_year }}</p>
                        <p v-else class="mt-1.5 text-[11px] text-slate-400">
                            {{ coverageText(selectedSet) }}<template v-if="selectedSet.id === sets[0]?.id"> และข้อมูลก่อนหน้านั้น</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-if="!inUse"
                            type="button"
                            :disabled="form.isDirty"
                            :title="form.isDirty ? 'บันทึกหรือยกเลิกการแก้ไขก่อน' : ''"
                            @click="postAction(selectedSet.is_locked ? 'unlock' : 'lock')"
                            class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <i :class="selectedSet.is_locked ? 'fa-solid fa-lock-open' : 'fa-solid fa-lock'"></i>
                            {{ selectedSet.is_locked ? 'ปลดล็อก' : 'ล็อก' }}
                        </button>
                        <button
                            type="button"
                            @click="openClone"
                            class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                        >
                            <i class="fa-regular fa-copy"></i>คัดลอก
                        </button>
                        <button
                            type="button"
                            :disabled="!canDelete"
                            :title="deleteBlockedReason"
                            @click="deleteOpen = true"
                            class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
                        >
                            <i class="fa-regular fa-trash-can"></i>ลบ
                        </button>
                    </div>
                </div>

                <div v-if="inUse" class="flex items-start gap-2 border-b border-emerald-100 bg-emerald-50/60 px-5 py-2.5 text-sm text-emerald-800">
                    <i class="fa-solid fa-lock mt-1 text-xs"></i>
                    <span
                        >ชุดนี้ใช้คำนวณข้อมูลปีงบประมาณ {{ selectedSet.used_years.join(', ') }} แล้ว จึงล็อกอัตโนมัติ ดูได้อย่างเดียว —
                        หากต้องการค่าใหม่ ให้กด "คัดลอก" แล้วตั้งปีเริ่มใช้เป็นปีถัดไป</span
                    >
                </div>
                <div v-else-if="isLocked" class="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-5 py-2.5 text-sm text-slate-500">
                    <i class="fa-solid fa-lock text-xs"></i>
                    ชุดนี้ถูกล็อกอยู่ ดูได้อย่างเดียว — กด "ปลดล็อก" เพื่อแก้ไข
                </div>

                <!-- Tabs -->
                <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-3 pt-3">
                    <button
                        v-for="tab in schema"
                        :key="tab.key"
                        type="button"
                        class="relative shrink-0 rounded-t-lg px-3.5 py-2 text-sm font-medium transition-colors"
                        :class="tab.key === activeTab ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800'"
                        @click="activeTab = tab.key"
                    >
                        {{ tab.label }}
                        <span
                            v-if="tabHasProblem(tab)"
                            class="absolute right-1 top-1 h-2 w-2 rounded-full bg-red-500 ring-2 ring-white"
                            title="มีค่าที่ไม่ถูกต้องในแท็บนี้"
                        ></span>
                    </button>
                </div>

                <!-- Sections of the current tab -->
                <div class="space-y-6 p-5">
                    <div v-for="section in currentTab.sections" :key="section.key">
                        <h3 class="text-sm font-semibold text-slate-800">{{ section.title }}</h3>
                        <p v-if="section.description" class="mt-0.5 text-xs text-slate-400">{{ section.description }}</p>

                        <!-- List layout: one row, items stacked -->
                        <div v-if="section.layout === 'list'" class="mt-3 max-w-xl overflow-hidden rounded-xl border border-slate-100">
                            <div
                                v-for="col in section.columns"
                                :key="col.key"
                                class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-2.5 last:border-b-0"
                            >
                                <div class="min-w-0">
                                    <p class="text-sm text-slate-700">{{ col.label }}</p>
                                    <p class="font-mono text-[11px] text-slate-400">{{ col.key }}</p>
                                </div>
                                <div class="text-right">
                                    <div
                                        v-if="section.fixed"
                                        class="w-24 rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-1.5 text-right font-mono text-sm text-slate-500"
                                    >
                                        {{ formatSum(section.rows[0].cells[col.key] ?? 0) }}
                                    </div>
                                    <input
                                        v-else
                                        v-model.number="form.weights[section.key]['ALL'][col.key]"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        max="1"
                                        :disabled="isLocked"
                                        :class="[
                                            inputClass,
                                            cellError(section, 'ALL', col.key)
                                                ? 'border-red-300 bg-red-50 text-red-700 focus:ring-red-100'
                                                : 'border-slate-200 bg-slate-50 text-slate-800 focus:border-blue-500 focus:ring-blue-100',
                                        ]"
                                    />
                                    <p v-if="cellError(section, 'ALL', col.key)" class="mt-1 text-[11px] text-red-600">
                                        {{ cellError(section, 'ALL', col.key) }}
                                    </p>
                                    <p v-else-if="warnAboveHit(section, col.key)" class="mt-1 max-w-[220px] text-[11px] text-amber-600">
                                        <i class="fa-solid fa-triangle-exclamation mr-0.5"></i>เกิน {{ section.warnAbove }} —
                                        ชุมชนที่ศักยภาพสูงอาจได้ค่าความเสี่ยงติดลบ
                                    </p>
                                </div>
                            </div>
                            <div v-if="section.sum" class="flex items-center justify-between bg-slate-50 px-4 py-2.5 text-sm">
                                <span class="font-medium text-slate-500">ผลรวม</span>
                                <span
                                    class="font-mono font-semibold"
                                    :class="rowSumOk(section, section.rows[0]) ? 'text-emerald-600' : 'text-red-600'"
                                >
                                    {{ formatSum(rowSum(section, section.rows[0])) }}
                                    <i
                                        :class="rowSumOk(section, section.rows[0]) ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'"
                                        class="ml-1"
                                    ></i>
                                    <span v-if="!rowSumOk(section, section.rows[0])" class="ml-1 text-xs font-normal">
                                        ({{ sumDiffText(section, section.rows[0]) }})
                                    </span>
                                </span>
                            </div>
                            <p v-if="form.errors[`weights.${section.key}.ALL` as keyof typeof form.errors]" class="px-4 pb-2.5 text-xs text-red-600">
                                {{ form.errors[`weights.${section.key}.ALL` as keyof typeof form.errors] }}
                            </p>
                        </div>

                        <!-- Matrix layout: hazard × component -->
                        <div v-else class="mt-3 overflow-x-auto rounded-xl border border-slate-100">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">ภัย</th>
                                        <th
                                            v-for="col in section.columns"
                                            :key="col.key"
                                            class="whitespace-nowrap px-3 py-2.5 text-center font-medium"
                                        >
                                            {{ col.label }}
                                        </th>
                                        <th class="whitespace-nowrap px-4 py-2.5 text-right font-medium">ผลรวม</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="row in section.rows" :key="row.key">
                                        <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-700">{{ row.label }}</td>
                                        <td v-for="col in section.columns" :key="col.key" class="px-3 py-2 text-center align-top">
                                            <span
                                                v-if="row.cells[col.key] === null"
                                                class="inline-block w-24 py-1.5 text-slate-300"
                                                title="ไม่มีในสูตรของภัยนี้"
                                                >–</span
                                            >
                                            <template v-else>
                                                <input
                                                    v-model.number="form.weights[section.key][row.key][col.key]"
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    max="1"
                                                    :disabled="isLocked"
                                                    :class="[
                                                        inputClass,
                                                        cellError(section, row.key, col.key)
                                                            ? 'border-red-300 bg-red-50 text-red-700 focus:ring-red-100'
                                                            : 'border-slate-200 bg-slate-50 text-slate-800 focus:border-blue-500 focus:ring-blue-100',
                                                    ]"
                                                />
                                                <p v-if="cellError(section, row.key, col.key)" class="mt-1 text-[11px] text-red-600">
                                                    {{ cellError(section, row.key, col.key) }}
                                                </p>
                                            </template>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right align-top">
                                            <span
                                                class="font-mono font-semibold"
                                                :class="rowSumOk(section, row) ? 'text-emerald-600' : 'text-red-600'"
                                            >
                                                {{ formatSum(rowSum(section, row)) }}
                                                <i
                                                    :class="rowSumOk(section, row) ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'"
                                                    class="ml-1"
                                                ></i>
                                            </span>
                                            <p v-if="!rowSumOk(section, row)" class="text-[11px] text-red-600">{{ sumDiffText(section, row) }}</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Save bar -->
                <div v-if="!isLocked" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-3.5">
                    <p class="text-sm" :class="formHasProblem ? 'text-red-600' : 'text-slate-400'">
                        <template v-if="formHasProblem"
                            ><i class="fa-solid fa-circle-exclamation mr-1"></i>มีค่าที่ไม่ถูกต้อง ดูแท็บที่มีจุดแดง</template
                        >
                        <template v-else-if="form.isDirty">มีการแก้ไขที่ยังไม่บันทึก</template>
                        <template v-else>ทุกกลุ่มผลรวมถูกต้อง</template>
                    </p>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            :disabled="!form.isDirty || form.processing"
                            @click="loadSelected"
                            class="rounded-xl px-4 py-2 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            ยกเลิกการแก้ไข
                        </button>
                        <button
                            type="button"
                            :disabled="formHasProblem || !form.isDirty || form.processing"
                            @click="save"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ form.processing ? 'กำลังบันทึก...' : 'บันทึก' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-else class="rounded-2xl border border-slate-100 bg-white p-10 text-center text-sm text-slate-400 shadow-sm xl:col-span-3">
                ยังไม่มีชุดเกณฑ์คะแนนในระบบ
            </div>
        </div>

        <!-- Clone modal -->
        <div
            v-if="cloneOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @click.self="cloneOpen = false"
        >
            <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex items-center justify-between bg-blue-600 px-6 py-4">
                    <h3 class="text-base font-semibold text-white">คัดลอกชุดเกณฑ์</h3>
                    <button
                        type="button"
                        @click="cloneOpen = false"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-blue-100 hover:bg-white/15 hover:text-white"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
                <form @submit.prevent="submitClone" class="space-y-4 p-6">
                    <p class="text-sm text-slate-500">
                        สร้างชุดใหม่โดยคัดลอกค่าทั้งหมดจาก "<span class="font-medium text-slate-700">{{ selectedSet?.name }}</span
                        >" ชุดใหม่จะแก้ไขได้จนกว่าจะมีข้อมูลในปีงบประมาณที่ชุดนี้ครอบคลุม
                    </p>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ชื่อชุดใหม่</label>
                        <input
                            v-model="cloneForm.name"
                            type="text"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                        <p v-if="cloneForm.errors.name" class="mt-1.5 text-xs text-red-600">{{ cloneForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">เริ่มใช้ปีงบประมาณ (พ.ศ.)</label>
                        <input
                            v-model.number="cloneForm.start_fiscal_year"
                            type="number"
                            step="1"
                            min="2500"
                            max="2700"
                            required
                            class="w-40 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 font-mono text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                        <p v-if="cloneForm.errors.start_fiscal_year" class="mt-1.5 text-xs text-red-600">{{ cloneForm.errors.start_fiscal_year }}</p>
                        <p v-else class="mt-1.5 text-[11px] text-slate-400">
                            ปีงบประมาณ = ต.ค.–ก.ย. ข้อมูลตั้งแต่ปีนี้ไปจะคำนวณด้วยชุดใหม่<template v-if="dataYears.length">
                                (ต้องหลังปีที่มีข้อมูลแล้ว: {{ dataYears.join(', ') }})</template
                            >
                        </p>
                    </div>
                    <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                        <button
                            type="button"
                            @click="cloneOpen = false"
                            class="rounded-xl px-4 py-2 text-sm text-slate-500 hover:bg-slate-50 hover:text-slate-800"
                        >
                            ยกเลิก
                        </button>
                        <button
                            type="submit"
                            :disabled="cloneForm.processing"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ cloneForm.processing ? 'กำลังคัดลอก...' : 'คัดลอก' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmDialog
            :open="deleteOpen"
            title="ยืนยันการลบชุดเกณฑ์"
            :message="`ต้องการลบ “${selectedSet?.name}” ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้`"
            confirm-label="ลบชุดเกณฑ์"
            :processing="deleting"
            @confirm="destroySet"
            @cancel="deleteOpen = false"
        />

        <ConfirmDialog
            :open="pendingSelectId !== null"
            title="ทิ้งการแก้ไขที่ยังไม่บันทึก?"
            message="ชุดเกณฑ์ที่เปิดอยู่มีการแก้ไขที่ยังไม่ได้บันทึก หากเปลี่ยนไปชุดอื่น การแก้ไขจะหายไป"
            confirm-label="ทิ้งการแก้ไข"
            @confirm="confirmDiscardAndSwitch"
            @cancel="pendingSelectId = null"
        />
    </AdminLayout>
</template>
