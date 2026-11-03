<script setup lang="ts">
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import ImportHistoryTable, { type ImportHistoryRow, type Paginated } from '@/components/admin/ImportHistoryTable.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

// จัดการประวัตินำเข้า (ตั้งค่าระบบ module, admin, ImportBatchManagementController): every import, filtered
// server-side by importer name, งาน, import date range and status, so the filters cover all pages. Admin can
// tick rows and delete their kept files to free space (any batch; the data stays).
const props = defineProps<{
    batches: Paginated<ImportHistoryRow>;
    filters: { user: string; group: string; from: string; to: string; status: string };
    groups: { value: string; label: string }[];
    cancelHours: number;
    // Kept original files over all batches (not the filters). `missing` = batch ids whose file isn't on disk
    // although nobody deleted it.
    storage: {
        files: number;
        bytes: number;
        activeFiles: number;
        cancelledFiles: number;
        deletedFiles: number;
        deletedBytes: number;
        missing: number[];
    };
}>();

const statusOptions = [
    { value: 'active', label: 'ใช้งาน' },
    { value: 'cancelled', label: 'ยกเลิกแล้ว' },
    { value: 'file_kept', label: 'ยังเก็บไฟล์อยู่' },
    { value: 'file_deleted', label: 'ลบไฟล์แล้ว' },
];

// File deletion: the selection is per page, and cleared whenever the list reloads.
const selected = ref<number[]>([]);
watch(
    () => props.batches,
    () => (selected.value = []),
);
const selectedRows = computed(() => props.batches.data.filter((h) => selected.value.includes(h.id)));
const selectedBytes = computed(() => selectedRows.value.reduce((sum, h) => sum + (h.fileBytes ?? 0), 0));
const selectedActive = computed(() => selectedRows.value.filter((h) => !h.cancelledAt).length);
const confirmingDelete = ref(false);
const deleting = ref(false);

function deleteFiles() {
    router.post(
        route('import-batches.delete-files'),
        { ids: selected.value },
        {
            preserveScroll: true,
            onStart: () => (deleting.value = true),
            onFinish: () => {
                deleting.value = false;
                confirmingDelete.value = false;
            },
        },
    );
}

function formatBytes(bytes: number) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

const form = reactive({ ...props.filters });
const hasFilters = computed(() => Object.values(props.filters).some((v) => v !== ''));

function search() {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get(route('import-batches.index'), query, { preserveState: true, preserveScroll: true, replace: true });
}

function clearFilters() {
    Object.assign(form, { user: '', group: '', from: '', to: '', status: '' });
    search();
}

const inputClass =
    'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="จัดการประวัตินำเข้า" />

    <AdminLayout title="จัดการประวัตินำเข้า">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">จัดการประวัตินำเข้า</h1>
            <p class="mt-1 text-sm text-slate-500">รายการนำเข้าข้อมูลทั้งหมดของทุกงาน ค้นหาด้วยชื่อผู้นำเข้า งาน หรือวันที่นำเข้า</p>
        </div>

        <!-- Kept original files (kept forever; back this folder up separately from the DB) -->
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="flex items-center gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <i class="fa-solid fa-file-excel text-xl"></i>
                </span>
                <div>
                    <p class="text-xs text-slate-500">ไฟล์ต้นฉบับที่เก็บไว้</p>
                    <p class="text-2xl font-bold text-slate-800">
                        {{ storage.files.toLocaleString() }} <span class="text-sm font-normal">ไฟล์</span>
                    </p>
                    <p class="text-xs text-slate-400">ใช้งาน {{ storage.activeFiles }} · ยกเลิกแล้ว {{ storage.cancelledFiles }}</p>
                </div>
            </div>
            <div class="flex items-center gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                    <i class="fa-solid fa-hard-drive text-xl"></i>
                </span>
                <div>
                    <p class="text-xs text-slate-500">พื้นที่ที่ใช้</p>
                    <p class="text-2xl font-bold text-slate-800">{{ formatBytes(storage.bytes) }}</p>
                    <p class="text-xs text-slate-400">
                        <template v-if="storage.deletedFiles">
                            ลบไปแล้ว {{ storage.deletedFiles }} ไฟล์ · คืนพื้นที่ {{ formatBytes(storage.deletedBytes) }}
                        </template>
                        <template v-else>เก็บที่ storage/app/private/imports</template>
                    </p>
                </div>
            </div>
            <div
                class="flex items-center gap-4 rounded-2xl border p-5 shadow-sm"
                :class="storage.missing.length ? 'border-red-100 bg-red-50' : 'border-slate-100 bg-white'"
            >
                <span
                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl"
                    :class="storage.missing.length ? 'bg-red-100 text-red-600' : 'bg-slate-50 text-slate-400'"
                >
                    <i class="fa-solid text-xl" :class="storage.missing.length ? 'fa-triangle-exclamation' : 'fa-circle-check'"></i>
                </span>
                <div class="min-w-0">
                    <p class="text-xs text-slate-500">ไฟล์ที่หาไม่พบ</p>
                    <p class="text-2xl font-bold" :class="storage.missing.length ? 'text-red-700' : 'text-slate-800'">
                        {{ storage.missing.length }} <span class="text-sm font-normal">ไฟล์</span>
                    </p>
                    <p v-if="storage.missing.length" class="truncate text-xs text-red-600" :title="storage.missing.map((id) => `#${id}`).join(', ')">
                        รายการ {{ storage.missing.map((id) => `#${id}`).join(', ') }}
                    </p>
                    <p v-else class="text-xs text-slate-400">ทุกรายการมีไฟล์ครบ</p>
                </div>
            </div>
        </div>

        <form class="mb-5 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm" @submit.prevent="search">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">ชื่อผู้นำเข้า</span>
                    <input v-model="form.user" type="search" placeholder="พิมพ์ชื่อ" :class="inputClass" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">งาน</span>
                    <select v-model="form.group" :class="inputClass" @change="search">
                        <option value="">ทุกงาน</option>
                        <option v-for="g in groups" :key="g.value" :value="g.value">{{ g.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">นำเข้าตั้งแต่วันที่</span>
                    <input v-model="form.from" type="date" :max="form.to || undefined" :class="inputClass" @change="search" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">ถึงวันที่</span>
                    <input v-model="form.to" type="date" :min="form.from || undefined" :class="inputClass" @change="search" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">สถานะ</span>
                    <select v-model="form.status" :class="inputClass" @change="search">
                        <option value="">ทุกสถานะ</option>
                        <option v-for="s in statusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </label>
            </div>
            <div class="mt-4 flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">พบ {{ batches.total }} รายการ</span>
                <div class="flex gap-2">
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 hover:bg-slate-50"
                        @click="clearFilters"
                    >
                        ล้างตัวกรอง
                    </button>
                    <button type="submit" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i class="fa-solid fa-magnifying-glass mr-1.5"></i>ค้นหา
                    </button>
                </div>
            </div>
        </form>

        <ImportHistoryTable
            v-model:selected="selected"
            selectable
            show-file-size
            :batches="batches"
            :cancel-hours="cancelHours"
            :empty-text="hasFilters ? 'ไม่พบรายการที่ตรงกับเงื่อนไข' : 'ยังไม่มีการนำเข้าข้อมูล'"
        />

        <!-- Selection bar for deleting kept files -->
        <div
            v-if="selected.length"
            class="sticky bottom-4 z-30 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-3 shadow-lg"
        >
            <span class="text-sm text-slate-700">
                เลือก <b>{{ selected.length }}</b> ไฟล์ · {{ formatBytes(selectedBytes) }}
                <span v-if="selectedActive" class="ml-1 text-xs text-red-600">(รายการที่ยังใช้งาน {{ selectedActive }})</span>
            </span>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 hover:bg-slate-50"
                    @click="selected = []"
                >
                    ยกเลิกการเลือก
                </button>
                <button
                    type="button"
                    class="rounded-xl bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                    @click="confirmingDelete = true"
                >
                    <i class="fa-solid fa-trash-can mr-1.5"></i>ลบไฟล์ที่เลือก
                </button>
            </div>
        </div>

        <ConfirmDialog
            :open="confirmingDelete"
            :title="`ลบไฟล์ต้นฉบับ ${selected.length} ไฟล์?`"
            :message="
                `คืนพื้นที่ประมาณ ${formatBytes(selectedBytes)} ข้อมูลที่นำเข้าและประวัติยังอยู่ แต่จะกด “ดูข้อมูล” ของรายการเหล่านี้ไม่ได้อีก และกู้ไฟล์คืนไม่ได้` +
                (selectedActive ? ` · มี ${selectedActive} รายการที่ข้อมูลยังใช้งานอยู่ ไฟล์ของรายการเหล่านี้คือหลักฐานต้นฉบับ` : '')
            "
            confirm-label="ยืนยันลบไฟล์"
            :processing="deleting"
            @confirm="deleteFiles"
            @cancel="confirmingDelete = false"
        />
    </AdminLayout>
</template>
