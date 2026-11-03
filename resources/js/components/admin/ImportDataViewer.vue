<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import type { ImportHistoryRow } from './ImportHistoryTable.vue';

// "ดูข้อมูล" modal for one import (owner decision 2026-11-03, instead of downloading the file). It fetches
// ImportHistoryController::data: the kept file read back as-is (App\Import\ImportFileReader, NOT re-validated),
// with each row's current status. Opened from ImportHistoryTable.
type RowStatus = 'active' | 'replaced' | 'cancelled' | 'unknown';
type ViewerData = {
    batch: ImportHistoryRow;
    maxRows: number;
    fileDeleted: boolean;
    missing: boolean;
    unreadable: boolean;
    headers: { name: string; used: boolean }[];
    rows: { row: number; cells: (string | number | null)[]; status: RowStatus; by: number | null }[];
    total: number;
    counts: Partial<Record<RowStatus, number>>;
};

const props = defineProps<{ batchId: number | null }>();
const emit = defineEmits<{ close: [] }>();

const data = ref<ViewerData | null>(null);
const loading = ref(false);
const failed = ref(false);
const statusFilter = ref<RowStatus | 'all'>('all');

const statusMeta: Record<RowStatus, { label: string; badge: string }> = {
    active: { label: 'ใช้งาน', badge: 'bg-emerald-50 text-emerald-700' },
    replaced: { label: 'ถูกทับแล้ว', badge: 'bg-amber-50 text-amber-700' },
    cancelled: { label: 'ยกเลิกแล้ว', badge: 'bg-slate-100 text-slate-500' },
    unknown: { label: 'ไม่พบในระบบ', badge: 'bg-red-50 text-red-600' },
};

watch(
    () => props.batchId,
    async (id) => {
        data.value = null;
        failed.value = false;
        statusFilter.value = 'all';
        if (id === null) return;
        loading.value = true;
        try {
            const response = await fetch(route('import-history.data', id), { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(String(response.status));
            if (props.batchId === id) data.value = await response.json();
        } catch {
            if (props.batchId === id) failed.value = true;
        } finally {
            if (props.batchId === id) loading.value = false;
        }
    },
);

const visibleRows = computed(() =>
    !data.value ? [] : statusFilter.value === 'all' ? data.value.rows : data.value.rows.filter((r) => r.status === statusFilter.value),
);
const statusChips = computed(() =>
    data.value ? (Object.keys(statusMeta) as RowStatus[]).filter((s) => data.value!.counts[s]).map((s) => ({ s, n: data.value!.counts[s]! })) : [],
);

function formatDateTime(iso: string) {
    return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
}

function closeOnEscape(event: KeyboardEvent) {
    if (event.key === 'Escape' && props.batchId !== null) emit('close');
}
onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape));
</script>

<template>
    <div
        v-if="batchId !== null"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
        @click.self="emit('close')"
    >
        <div class="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div class="flex items-center justify-between bg-blue-600 px-6 py-4">
                <h3 class="text-base font-semibold text-white">
                    ข้อมูลที่นำเข้า #{{ batchId }}<template v-if="data"> · {{ data.batch.group.label }}</template>
                </h3>
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-full text-blue-100 hover:bg-white/15 hover:text-white"
                    @click="emit('close')"
                >
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <div v-if="loading" class="p-12 text-center text-sm text-slate-500"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังอ่านไฟล์...</div>
            <div v-else-if="failed" class="p-12 text-center text-sm text-red-600">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i>โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่
            </div>

            <template v-else-if="data">
                <!-- Batch recap -->
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-slate-100 px-6 py-3 text-xs text-slate-500">
                    <span><i class="fa-solid fa-file-excel mr-1 text-emerald-600"></i>{{ data.batch.filename }}</span>
                    <span>นำเข้าเมื่อ {{ formatDateTime(data.batch.createdAt) }} โดย {{ data.batch.user ?? '–' }}</span>
                    <span>{{ data.batch.rowCount }} แถว · {{ data.batch.communityCount }} ชุมชน</span>
                    <span v-if="data.batch.cancelledAt" class="rounded-md bg-slate-100 px-2 py-0.5 text-slate-600">
                        ยกเลิกแล้วโดย {{ data.batch.cancelledBy ?? '–' }} เมื่อ {{ formatDateTime(data.batch.cancelledAt) }}
                    </span>
                </div>

                <div v-if="data.fileDeleted" class="p-12 text-center text-sm text-slate-500">
                    <i class="fa-solid fa-trash-can mb-2 block text-xl text-slate-300"></i>
                    ไฟล์ต้นฉบับของรายการนี้ถูกลบแล้ว
                    <template v-if="data.batch.fileDeletedAt">
                        โดย {{ data.batch.fileDeletedBy ?? '–' }} เมื่อ {{ formatDateTime(data.batch.fileDeletedAt) }}
                    </template>
                    <span class="mt-1 block text-xs text-slate-400">ข้อมูลที่นำเข้ายังอยู่ในระบบตามปกติ</span>
                </div>
                <div v-else-if="data.missing || data.unreadable" class="p-12 text-center text-sm text-red-600">
                    <i class="fa-solid fa-triangle-exclamation mb-2 block text-xl"></i>
                    {{ data.missing ? 'ไม่พบไฟล์ต้นฉบับของรายการนี้บนเซิร์ฟเวอร์ กรุณาแจ้งผู้ดูแลระบบ' : 'อ่านไฟล์ต้นฉบับของรายการนี้ไม่ได้' }}
                </div>

                <template v-else>
                    <div class="flex flex-wrap items-center gap-2 px-6 pt-4 text-xs">
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1"
                            :class="statusFilter === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            @click="statusFilter = 'all'"
                        >
                            ทั้งหมด {{ data.total }}
                        </button>
                        <button
                            v-for="c in statusChips"
                            :key="c.s"
                            type="button"
                            class="rounded-lg px-2.5 py-1"
                            :class="statusFilter === c.s ? 'bg-blue-600 text-white' : [statusMeta[c.s].badge, 'hover:opacity-80']"
                            @click="statusFilter = c.s"
                        >
                            {{ statusMeta[c.s].label }} {{ c.n }}
                        </button>
                        <span class="ml-auto text-slate-400">แสดงตามที่อยู่ในไฟล์ ไม่ได้ตรวจซ้ำ · คอลัมน์สีเทา = ระบบไม่ได้ใช้</span>
                    </div>

                    <div class="m-6 mt-3 min-h-0 flex-1 overflow-auto rounded-xl border border-slate-200">
                        <table class="min-w-full border-separate border-spacing-0 text-xs">
                            <thead>
                                <tr>
                                    <th
                                        class="sticky left-0 top-0 z-20 border-b border-r border-slate-200 bg-slate-100 px-3 py-2 text-left font-medium text-slate-500"
                                    >
                                        แถว
                                    </th>
                                    <th
                                        class="sticky top-0 z-10 border-b border-r border-slate-200 bg-slate-100 px-3 py-2 text-left font-medium text-slate-500"
                                    >
                                        สถานะแถว
                                    </th>
                                    <th
                                        v-for="h in data.headers"
                                        :key="h.name"
                                        class="sticky top-0 z-10 whitespace-nowrap border-b border-r border-slate-200 px-3 py-2 text-left font-semibold last:border-r-0"
                                        :class="h.used ? 'bg-slate-100 text-slate-700' : 'bg-slate-50 text-slate-400'"
                                    >
                                        {{ h.name }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in visibleRows" :key="r.row">
                                    <td class="sticky left-0 z-[5] border-b border-r border-slate-100 bg-white px-3 py-1.5 text-slate-400">
                                        {{ r.row }}
                                    </td>
                                    <td class="whitespace-nowrap border-b border-r border-slate-100 bg-white px-3 py-1.5">
                                        <span class="rounded-md px-2 py-0.5" :class="statusMeta[r.status].badge">
                                            {{ statusMeta[r.status].label }}<template v-if="r.by"> โดย #{{ r.by }}</template>
                                        </span>
                                    </td>
                                    <td
                                        v-for="(cell, i) in r.cells"
                                        :key="i"
                                        class="whitespace-nowrap border-b border-r border-slate-100 px-3 py-1.5 last:border-r-0"
                                        :class="[
                                            data.headers[i]?.used ? 'text-slate-700' : 'bg-slate-50/60 text-slate-400',
                                            { 'text-slate-300': cell === null },
                                        ]"
                                    >
                                        {{ cell ?? '–' }}
                                    </td>
                                </tr>
                                <tr v-if="!visibleRows.length">
                                    <td :colspan="data.headers.length + 2" class="px-3 py-8 text-center text-slate-400">ไม่มีแถวในสถานะนี้</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="data.total > data.rows.length" class="-mt-4 px-6 pb-4 text-xs text-amber-700">
                        แสดง {{ data.rows.length }} แถวแรกจาก {{ data.total }} แถว
                    </p>
                </template>
            </template>
        </div>
    </div>
</template>
