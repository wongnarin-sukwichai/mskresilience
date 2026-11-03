<script setup lang="ts">
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import ImportDataViewer from '@/components/admin/ImportDataViewer.vue';
import { defaultWorkGroupTheme, workGroupIcons, workGroupThemes } from '@/data/workGroupIcons';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Import history table shared by ประวัตินำเข้า (ImportHistory.vue) and จัดการประวัตินำเข้า (ImportBatches.vue).
// Rows come from App\Import\ImportHistory::row(), as a Laravel paginator. The งาน badge uses the same colors as
// the นำเข้าข้อมูล cards (workGroupThemes). Cancelling (ImportHistoryController::cancel) deletes the rows a batch
// added and puts back the values it replaced; refusals come back as the layout's red toast.
export type ImportHistoryRow = {
    id: number;
    group: { value: string; label: string };
    createdAt: string;
    user: string | null;
    filename: string;
    rowCount: number;
    replacedCount: number;
    communityCount: number;
    cancelledAt: string | null;
    cancelledBy: string | null;
    fileDeletedAt: string | null;
    fileDeletedBy: string | null;
    fileBytes: number | null;
    deletedFileBytes: number | null;
    canCancel: boolean;
    cancelBlocked: string | null;
};
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    batches: Paginated<ImportHistoryRow>;
    cancelHours: number;
    emptyText: string;
    // จัดการประวัตินำเข้า only: a checkbox per row whose file is still kept, for deleting files (v-model:selected).
    selectable?: boolean;
    // จัดการประวัตินำเข้า only: the ขนาดไฟล์ column.
    showFileSize?: boolean;
}>();

function formatBytes(bytes: number) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}
const selected = defineModel<number[]>('selected', { default: () => [] });

const selectableIds = computed(() => props.batches.data.filter((h) => !h.fileDeletedAt).map((h) => h.id));
const allSelected = computed(() => selectableIds.value.length > 0 && selectableIds.value.every((id) => selected.value.includes(id)));

function toggleAll() {
    selected.value = allSelected.value ? [] : [...selectableIds.value];
}

function toggle(id: number) {
    selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id];
}

const themeOf = (value: string) => workGroupThemes[value] ?? defaultWorkGroupTheme;
const iconOf = (value: string) => workGroupIcons[value] ?? 'fa-solid fa-file-excel';

function formatDateTime(iso: string) {
    return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
}

// "ดูข้อมูล" viewer (ImportDataViewer): the batch's kept file, read back as-is.
const viewingId = ref<number | null>(null);

const cancelTarget = ref<ImportHistoryRow | null>(null);
const cancelling = ref(false);

function cancelBatch() {
    if (!cancelTarget.value) return;
    router.delete(route('import-history.cancel', cancelTarget.value.id), {
        preserveScroll: true,
        onStart: () => (cancelling.value = true),
        onFinish: () => {
            cancelling.value = false;
            cancelTarget.value = null;
        },
    });
}
</script>

<template>
    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
        <div v-if="batches.data.length" class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-left text-xs text-slate-500">
                        <th v-if="selectable" class="w-8 py-2 pr-2">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-blue-600"
                                title="เลือกทุกไฟล์ในหน้านี้"
                                :checked="allSelected"
                                :disabled="!selectableIds.length"
                                @change="toggleAll"
                            />
                        </th>
                        <th class="py-2 pr-4 font-medium">เลขอ้างอิง</th>
                        <th class="py-2 pr-4 font-medium">งาน</th>
                        <th class="py-2 pr-4 font-medium">วันที่นำเข้า</th>
                        <th class="py-2 pr-4 font-medium">ผู้นำเข้า</th>
                        <th class="py-2 pr-4 font-medium">ไฟล์</th>
                        <th class="py-2 pr-4 text-right font-medium">แถว</th>
                        <th class="py-2 pr-4 text-right font-medium">ชุมชน</th>
                        <th class="py-2 pr-4 text-right font-medium">แทนที่ข้อมูลเดิม</th>
                        <th v-if="showFileSize" class="whitespace-nowrap py-2 pr-4 text-right font-medium">ขนาดไฟล์</th>
                        <th class="py-2 pr-4 font-medium">สถานะ</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="h in batches.data"
                        :key="h.id"
                        class="border-b border-slate-50 last:border-0"
                        :class="h.cancelledAt ? 'text-slate-400' : 'text-slate-700'"
                    >
                        <td v-if="selectable" class="py-2 pr-2">
                            <input
                                v-if="!h.fileDeletedAt"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-blue-600"
                                :checked="selected.includes(h.id)"
                                @change="toggle(h.id)"
                            />
                        </td>
                        <td class="py-2 pr-4 text-xs text-slate-400">#{{ h.id }}</td>
                        <td class="py-2 pr-4">
                            <span
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2 py-1 text-xs font-medium"
                                :class="[themeOf(h.group.value).tile, { 'opacity-60': h.cancelledAt }]"
                            >
                                <i :class="iconOf(h.group.value)"></i>{{ h.group.label }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap py-2 pr-4">{{ formatDateTime(h.createdAt) }}</td>
                        <td class="py-2 pr-4">{{ h.user ?? '–' }}</td>
                        <td class="max-w-xs py-2 pr-4">
                            <span class="block truncate" :class="{ 'line-through': h.cancelledAt }" :title="h.filename">{{ h.filename }}</span>
                            <span
                                v-if="h.fileDeletedAt"
                                class="mt-0.5 inline-block rounded bg-slate-100 px-1.5 text-[11px] text-slate-500"
                                :title="`ลบโดย ${h.fileDeletedBy ?? '–'} เมื่อ ${formatDateTime(h.fileDeletedAt)}`"
                            >
                                <i class="fa-solid fa-trash-can mr-1"></i>ลบไฟล์แล้ว
                            </span>
                        </td>
                        <td class="py-2 pr-4 text-right">{{ h.rowCount }}</td>
                        <td class="py-2 pr-4 text-right">{{ h.communityCount }}</td>
                        <td class="py-2 pr-4 text-right">{{ h.replacedCount }}</td>
                        <td v-if="showFileSize" class="whitespace-nowrap py-2 pr-4 text-right">
                            <span v-if="h.fileBytes !== null">{{ formatBytes(h.fileBytes) }}</span>
                            <span v-else-if="h.fileDeletedAt" class="text-slate-400 line-through" title="ลบไฟล์แล้ว">
                                {{ h.deletedFileBytes !== null ? formatBytes(h.deletedFileBytes) : '–' }}
                            </span>
                            <span v-else class="text-red-500" title="ไม่พบไฟล์บนเซิร์ฟเวอร์">ไม่พบไฟล์</span>
                        </td>
                        <td class="whitespace-nowrap py-2 pr-4">
                            <span
                                v-if="h.cancelledAt"
                                class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-500"
                                :title="`ยกเลิกโดย ${h.cancelledBy ?? '–'} เมื่อ ${formatDateTime(h.cancelledAt)}`"
                            >
                                ยกเลิกแล้ว
                            </span>
                            <span v-else class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">ใช้งาน</span>
                        </td>
                        <td class="whitespace-nowrap py-2 text-right">
                            <button
                                type="button"
                                title="ดูข้อมูลที่นำเข้าจากไฟล์นี้"
                                class="mr-1.5 whitespace-nowrap rounded-lg border border-blue-200 px-2.5 py-1 text-xs text-blue-600 hover:bg-blue-50"
                                @click="viewingId = h.id"
                            >
                                <i class="fa-solid fa-eye mr-1"></i>ดูข้อมูล
                            </button>
                            <button
                                v-if="h.canCancel"
                                type="button"
                                :disabled="!!h.cancelBlocked"
                                :title="h.cancelBlocked ?? 'ยกเลิกรายการนำเข้านี้'"
                                class="whitespace-nowrap rounded-lg border border-red-200 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400 disabled:hover:bg-transparent"
                                @click="cancelTarget = h"
                            >
                                <i class="fa-solid fa-rotate-left mr-1"></i>ยกเลิก
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="py-10 text-center text-sm text-slate-500">
            <i class="fa-solid fa-clock-rotate-left mb-2 block text-xl text-slate-300"></i>
            {{ emptyText }}
        </div>

        <div v-if="batches.last_page > 1" class="mt-4 flex items-center justify-between text-xs text-slate-500">
            <span>แสดง {{ batches.from }}–{{ batches.to }} จาก {{ batches.total }} รายการ</span>
            <div class="flex items-center gap-2">
                <component
                    :is="batches.prev_page_url ? Link : 'span'"
                    :href="batches.prev_page_url ?? undefined"
                    preserve-scroll
                    class="rounded-lg border border-slate-200 px-3 py-1"
                    :class="batches.prev_page_url ? 'hover:bg-slate-50' : 'opacity-40'"
                >
                    <i class="fa-solid fa-chevron-left"></i>
                </component>
                <span>หน้า {{ batches.current_page }} / {{ batches.last_page }}</span>
                <component
                    :is="batches.next_page_url ? Link : 'span'"
                    :href="batches.next_page_url ?? undefined"
                    preserve-scroll
                    class="rounded-lg border border-slate-200 px-3 py-1"
                    :class="batches.next_page_url ? 'hover:bg-slate-50' : 'opacity-40'"
                >
                    <i class="fa-solid fa-chevron-right"></i>
                </component>
            </div>
        </div>

        <p class="mt-3 text-xs text-slate-400">
            ยกเลิกแล้ว แถวที่รายการนั้นเพิ่มเข้ามาจะถูกลบ ส่วนแถวที่ไปทับข้อมูลเดิมจะได้ค่าเดิมคืน ต้องยกเลิกจากรายการล่าสุดก่อน
            เจ้าหน้าที่ยกเลิกได้เฉพาะรายการของตัวเอง ภายใน {{ cancelHours }} ชั่วโมง
        </p>
    </div>

    <ImportDataViewer :batch-id="viewingId" @close="viewingId = null" />

    <ConfirmDialog
        :open="!!cancelTarget"
        :title="`ยกเลิกรายการนำเข้า #${cancelTarget?.id ?? ''}?`"
        :message="
            cancelTarget
                ? `ข้อมูล${cancelTarget.group.label} ${cancelTarget.rowCount} แถวจากไฟล์ ${cancelTarget.filename} จะถูกนำออก` +
                  (cancelTarget.replacedCount ? ` และ ${cancelTarget.replacedCount} แถวที่เคยทับไว้จะกลับเป็นค่าเดิม` : '') +
                  ' ประวัติจะยังอยู่ พร้อมชื่อผู้ยกเลิก'
                : ''
        "
        confirm-label="ยืนยันยกเลิก"
        processing-label="กำลังยกเลิก..."
        :processing="cancelling"
        @confirm="cancelBatch"
        @cancel="cancelTarget = null"
    />
</template>
