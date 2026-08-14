<script setup lang="ts">
import {
    baselineWeights,
    cloneWeights,
    criteriaList,
    groupSum,
    weightGroups,
    type ScoringCriteriaRecord,
    type Weights,
} from '@/data/mockScoringCriteria';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Mock CRUD — there's no `scoring_criteria` table/controller yet (see CLAUDE.md). `criteriaList` is a
// shared module-level ref (data/mockScoringCriteria.ts), not a local copy — the CRUD here mutates the
// same store that รายภัย/เหตุการณ์ L&D read from, so this page's changes are actually visible elsewhere.
//
// Design carried over from the earlier discussion:
// - Exactly one set is "ค่าเริ่มต้น" (default) — used for new calculations going forward.
// - A set with any data already computed against it is "ล็อกแล้ว" (locked) — edits are blocked, only
//   "คัดลอก" (clone into a new, unlocked, non-default set) is allowed, so historical scores stay
//   reproducible even after weights change for future rounds.

function nowLabel() {
    const d = new Date();
    const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return `${String(d.getDate()).padStart(2, '0')} ${thaiMonths[d.getMonth()]} ${d.getFullYear() + 543}`;
}

// ── Modal state ──
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingId = ref<number | null>(null);
const form = ref<{ name: string; year: number; weights: Weights }>({ name: '', year: 2569, weights: baselineWeights() });

function openCreateModal() {
    modalMode.value = 'create';
    editingId.value = null;
    form.value = { name: '', year: new Date().getFullYear() + 543 + 1, weights: baselineWeights() }; // suggest next พ.ศ. year
    modalOpen.value = true;
}

function openEditModal(criteria: ScoringCriteriaRecord) {
    if (criteria.locked) return;
    modalMode.value = 'edit';
    editingId.value = criteria.id;
    form.value = { name: criteria.name, year: criteria.year, weights: cloneWeights(criteria.weights) };
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function submit() {
    if (modalMode.value === 'create') {
        const nextId = Math.max(0, ...criteriaList.value.map((c) => c.id)) + 1;
        criteriaList.value.push({
            id: nextId,
            name: form.value.name,
            year: form.value.year,
            isDefault: false,
            locked: false,
            createdAt: nowLabel(),
            weights: form.value.weights,
        });
    } else if (editingId.value !== null) {
        const target = criteriaList.value.find((c) => c.id === editingId.value);
        if (target) {
            target.name = form.value.name;
            target.year = form.value.year;
            target.weights = form.value.weights;
        }
    }
    closeModal();
}

function cloneCriteria(criteria: ScoringCriteriaRecord) {
    const nextId = Math.max(0, ...criteriaList.value.map((c) => c.id)) + 1;
    const copy: ScoringCriteriaRecord = {
        id: nextId,
        name: `${criteria.name} (สำเนา)`,
        year: criteria.year,
        isDefault: false,
        locked: false,
        createdAt: nowLabel(),
        weights: cloneWeights(criteria.weights),
    };
    criteriaList.value.push(copy);
    openEditModal(copy);
}

function setDefault(criteria: ScoringCriteriaRecord) {
    criteriaList.value.forEach((c) => (c.isDefault = c.id === criteria.id));
}

function destroyCriteria(criteria: ScoringCriteriaRecord) {
    if (criteria.locked || criteria.isDefault) return;
    if (!confirm(`ยืนยันลบชุดเกณฑ์ "${criteria.name}" ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้`)) return;
    criteriaList.value = criteriaList.value.filter((c) => c.id !== criteria.id);
}

const formTotals = computed(() => weightGroups.map((g) => ({ key: g.key, sum: groupSum(form.value.weights, g.key) })));
</script>

<template>
    <Head title="จัดการเกณฑ์คะแนน" />

    <AdminLayout title="จัดการเกณฑ์คะแนน">
        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 p-5">
                <div>
                    <h2 class="font-semibold text-slate-800">จัดการเกณฑ์คะแนน</h2>
                    <p class="mt-0.5 text-sm text-slate-400">ชุดน้ำหนักคะแนนสำหรับคำนวณ HEVC ทั้งหมด {{ criteriaList.length }} ชุด</p>
                </div>
                <button
                    type="button"
                    @click="openCreateModal"
                    class="flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700"
                >
                    <i class="fa-solid fa-plus"></i>
                    เพิ่มชุดเกณฑ์คะแนนใหม่
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-medium">ชื่อชุดเกณฑ์</th>
                            <th class="px-5 py-3 font-medium">ปีที่ใช้ (พ.ศ.)</th>
                            <th class="px-5 py-3 font-medium">สถานะ</th>
                            <th class="px-5 py-3 font-medium">สร้างเมื่อ</th>
                            <th class="px-5 py-3 font-medium">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="criteria in criteriaList" :key="criteria.id" class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-700">{{ criteria.name }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ criteria.year }}</td>
                            <td class="whitespace-nowrap px-5 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <span v-if="criteria.isDefault" class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-600">
                                        <i class="fa-solid fa-star mr-1 text-[10px]"></i>ค่าเริ่มต้น
                                    </span>
                                    <span
                                        v-if="criteria.locked"
                                        class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500"
                                        title="มีข้อมูลคำนวณด้วยเกณฑ์นี้แล้ว แก้ไขไม่ได้ ใช้ปุ่มคัดลอกเพื่อสร้างฉบับใหม่แทน"
                                    >
                                        <i class="fa-solid fa-lock mr-1 text-[10px]"></i>ล็อกแล้ว
                                    </span>
                                    <span
                                        v-if="!criteria.locked"
                                        class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-600"
                                    >
                                        แก้ไขได้
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-400">{{ criteria.createdAt }}</td>
                            <td class="whitespace-nowrap px-5 py-3">
                                <div class="flex items-center gap-3 text-slate-400">
                                    <button
                                        v-if="!criteria.isDefault"
                                        type="button"
                                        title="ตั้งเป็นค่าเริ่มต้น"
                                        @click="setDefault(criteria)"
                                        class="hover:text-blue-600"
                                    >
                                        <i class="fa-regular fa-star"></i>
                                    </button>
                                    <button
                                        type="button"
                                        :title="criteria.locked ? 'ล็อกแล้ว แก้ไขไม่ได้' : 'แก้ไข'"
                                        :disabled="criteria.locked"
                                        @click="openEditModal(criteria)"
                                        class="disabled:cursor-not-allowed disabled:opacity-30"
                                        :class="!criteria.locked && 'hover:text-blue-600'"
                                    >
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </button>
                                    <button type="button" title="คัดลอกเป็นชุดใหม่" @click="cloneCriteria(criteria)" class="hover:text-blue-600">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                    <button
                                        type="button"
                                        :title="criteria.locked || criteria.isDefault ? 'ลบไม่ได้ (ล็อกแล้วหรือเป็นค่าเริ่มต้น)' : 'ลบ'"
                                        :disabled="criteria.locked || criteria.isDefault"
                                        @click="destroyCriteria(criteria)"
                                        class="disabled:cursor-not-allowed disabled:opacity-30"
                                        :class="!(criteria.locked || criteria.isDefault) && 'hover:text-red-600'"
                                    >
                                        <i class="fa-regular fa-trash-can"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create / Edit Modal -->
        <div
            v-if="modalOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @click.self="closeModal"
        >
            <div class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ modalMode === 'create' ? 'เพิ่มชุดเกณฑ์คะแนนใหม่' : 'แก้ไขชุดเกณฑ์คะแนน' }}
                    </h3>
                    <button
                        type="button"
                        @click="closeModal"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="overflow-y-auto">
                    <div class="space-y-5 p-6">
                        <div class="grid grid-cols-3 gap-4">
                            <div class="col-span-2">
                                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ชื่อชุดเกณฑ์</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                                />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ปีที่ใช้ (พ.ศ.)</label>
                                <input
                                    v-model.number="form.year"
                                    type="number"
                                    required
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                                />
                            </div>
                        </div>

                        <div v-for="group in weightGroups" :key="group.key" class="rounded-xl border border-slate-100 p-4">
                            <div class="mb-3 flex items-center justify-between">
                                <p class="text-sm font-semibold text-slate-700">{{ group.title }}</p>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="
                                        Math.abs((formTotals.find((t) => t.key === group.key)?.sum ?? 0) - 1) < 0.001
                                            ? 'bg-emerald-50 text-emerald-600'
                                            : 'bg-amber-50 text-amber-600'
                                    "
                                >
                                    รวม {{ formTotals.find((t) => t.key === group.key)?.sum.toFixed(2) }}
                                    <span v-if="Math.abs((formTotals.find((t) => t.key === group.key)?.sum ?? 0) - 1) >= 0.001"
                                        >(ควรเท่ากับ 1.00)</span
                                    >
                                </span>
                            </div>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <div v-for="field in group.fields" :key="field.key">
                                    <label class="mb-1 block text-xs text-slate-500">{{ field.label }}</label>
                                    <input
                                        v-model.number="form.weights[group.key][field.key]"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="1"
                                        class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-slate-100 bg-white px-6 py-4">
                        <button
                            type="button"
                            @click="closeModal"
                            class="rounded-xl px-4 py-2 text-sm text-slate-500 hover:bg-slate-50 hover:text-slate-800"
                        >
                            ยกเลิก
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700"
                        >
                            บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
