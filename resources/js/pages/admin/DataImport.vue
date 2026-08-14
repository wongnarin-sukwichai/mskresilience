<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';

// Fully mock single-page import flow — no backend endpoint parses the file yet (see CLAUDE.md: this menu
// ships with mock data first). The uploaded file's name/size are read for real via the browser File API for
// a realistic feel, but nothing is parsed or persisted. All steps render on one page; only the อัปโหลดไฟล์
// card shows at first. After a file is "uploaded" (simulated loading delay), the remaining cards reveal
// themselves in sequence with a fade-in, and the timeline at top tracks whichever step's card(s) are
// currently revealed — there's no manual next/back wizard navigation anymore.
//
// Scope note: only the 5 per-hazard sheets (อุทกภัย/อัคคีภัย/วาตภัย/คลื่นความร้อน/PM 2.5) are importable here.
// ภัยพิบัติรวม's input columns belong to "จัดการชุมชน" (one-time setup per community) and เหตุการณ์_LD belongs
// to "ข้อมูลเหตุการณ์" (logged per incident) — both are separate CRUD forms, not bulk xlsx import, per team
// decision. 01_พจนานุกรม/02_เกณฑ์คะแนน/00_คำแนะนำ are reference-only sheets and can never be selected.

const steps = [
    { n: 1, label: 'เลือกไฟล์' },
    { n: 2, label: 'เลือกแผ่นงาน' },
    { n: 3, label: 'ตรวจสอบโครงสร้าง' },
    { n: 4, label: 'จับคู่ฟิลด์ข้อมูล' },
    { n: 5, label: 'ยืนยันและนำเข้า' },
];

// How many steps' worth of cards are currently revealed on the page — also drives the timeline highlight.
const revealedStep = ref(1);
const importDone = ref(false);

// ── ไฟล์ ──
const selectedFile = ref<File | null>(null);
const pendingFile = ref<File | null>(null);
const isUploading = ref(false);
const uploadedAt = ref('');
const fileInputEl = ref<HTMLInputElement | null>(null);
const fileError = ref('');
const isDragOver = ref(false);

let revealTimers: ReturnType<typeof setTimeout>[] = [];
function clearRevealTimers() {
    revealTimers.forEach(clearTimeout);
    revealTimers = [];
}
onUnmounted(clearRevealTimers);

function formatFileSize(bytes: number) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

function nowLabel() {
    const d = new Date();
    const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return `${d.getDate()} ${thaiMonths[d.getMonth()]} ${d.getFullYear() + 543} ${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function pickFile() {
    fileInputEl.value?.click();
}

// Simulates an upload (spinner in the อัปโหลดไฟล์ card), then cascades the remaining cards into view one by
// one with a fade-in, advancing revealedStep — and the timeline with it — as each one appears.
function startUpload(file: File) {
    fileError.value = '';
    clearRevealTimers();
    revealedStep.value = 1;
    selectedFile.value = null;
    pendingFile.value = file;
    isUploading.value = true;

    revealTimers.push(
        setTimeout(() => {
            selectedFile.value = pendingFile.value;
            uploadedAt.value = nowLabel();
            isUploading.value = false;
            scheduleReveal();
        }, 1000),
    );
}

function scheduleReveal() {
    const cascade: [number, number][] = [
        [2, 700],
        [3, 1500],
        [4, 2300],
        [5, 3100],
    ];
    cascade.forEach(([step, delay]) => {
        revealTimers.push(setTimeout(() => (revealedStep.value = step), delay));
    });
}

function validateAndUpload(file: File) {
    if (!/\.(xlsx|csv)$/i.test(file.name)) {
        fileError.value = 'รองรับเฉพาะไฟล์ Excel (.xlsx) หรือ CSV (.csv) เท่านั้น';
        return;
    }
    startUpload(file);
}

function handleFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    validateAndUpload(file);
}

function handleDrop(event: DragEvent) {
    isDragOver.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    validateAndUpload(file);
}

function removeFile() {
    clearRevealTimers();
    selectedFile.value = null;
    pendingFile.value = null;
    isUploading.value = false;
    revealedStep.value = 1;
    if (fileInputEl.value) fileInputEl.value.value = '';
}

// ── แผ่นงาน ──
interface SheetRow {
    key: string;
    name: string;
    rows: number;
    selectable: boolean;
    checked: boolean;
    note?: string;
}

const sheets = ref<SheetRow[]>([
    { key: 'flood', name: 'อุทกภัย', rows: 33, selectable: true, checked: true },
    { key: 'fire', name: 'อัคคีภัย', rows: 30, selectable: true, checked: true },
    { key: 'wind', name: 'วาตภัย', rows: 28, selectable: true, checked: true },
    { key: 'heat', name: 'คลื่นความร้อน', rows: 25, selectable: true, checked: true },
    { key: 'pm25', name: 'PM 2.5', rows: 0, selectable: true, checked: false, note: 'ไม่พบแถวข้อมูลในชีตนี้' },
    { key: 'dict', name: '01_พจนานุกรม', rows: 46, selectable: false, checked: false, note: 'อ้างอิง' },
    { key: 'scoring', name: '02_เกณฑ์คะแนน', rows: 23, selectable: false, checked: false, note: 'อ้างอิง' },
    { key: 'guide', name: '00_คำแนะนำ', rows: 11, selectable: false, checked: false, note: 'อ้างอิง' },
]);

const selectedSheets = computed(() => sheets.value.filter((s) => s.selectable && s.checked));

// ── ตรวจสอบโครงสร้าง — errors are tied to what got selected above (e.g. a 0-row sheet), so the default
// selection sails through while picking an empty sheet demonstrates a real block. ──
const validationChecks = computed(() => {
    const checks: { label: string; status: 'pass' | 'warn' | 'error' }[] = [
        { label: 'โครงสร้างคอลัมน์ครบถ้วน', status: 'pass' },
        { label: 'พบ COMMUNITY_ID ตรงกับชุมชนที่มีอยู่ในระบบ', status: 'pass' },
        { label: 'ตรวจรูปแบบวันที่ (ASSESSMENT_DATE, PERIOD_START/END)', status: 'warn' },
        { label: 'ข้อมูลหลักฐาน/วันที่ข้อมูลบางแถวไม่ครบ (SOURCE/EVIDENCE/DATA_DATE)', status: 'warn' },
    ];
    if (selectedSheets.value.some((s) => s.rows === 0)) {
        checks.push({ label: 'พบชีตที่เลือกไว้แต่ไม่มีแถวข้อมูลในไฟล์ (PM 2.5)', status: 'error' });
    }
    return checks;
});

const validationStyle = {
    pass: { icon: 'fa-solid fa-circle-check', color: 'text-emerald-600', label: 'ผ่าน' },
    warn: { icon: 'fa-solid fa-triangle-exclamation', color: 'text-amber-600', label: 'คำเตือน' },
    error: { icon: 'fa-solid fa-circle-xmark', color: 'text-red-600', label: 'ต้องตรวจสอบ' },
};

const previewRows = [
    { COMMUNITY_ID: 'R001', HAZARD_NAME: 'อุทกภัย', H_RAW: 4, E_PERCENT: '14.7%', HEVC_100: 61.3, DATA_QUALITY: 'พร้อมใช้เป็นค่าร่วม' },
    { COMMUNITY_ID: 'R003', HAZARD_NAME: 'อุทกภัย', H_RAW: 2, E_PERCENT: '9.1%', HEVC_100: 42.8, DATA_QUALITY: 'คำเตือน' },
    { COMMUNITY_ID: 'R004', HAZARD_NAME: 'อุทกภัย', H_RAW: 1, E_PERCENT: '5.4%', HEVC_100: 29.5, DATA_QUALITY: 'พร้อมใช้เป็นค่าร่วม' },
    { COMMUNITY_ID: 'R005', HAZARD_NAME: 'อุทกภัย', H_RAW: 3, E_PERCENT: '18.2%', HEVC_100: 55.0, DATA_QUALITY: 'พร้อมใช้เป็นค่าร่วม' },
];

const errorCount = computed(() => validationChecks.value.filter((c) => c.status === 'error').length);
const warnCount = computed(() => validationChecks.value.filter((c) => c.status === 'warn').length);

// ── จับคู่ฟิลด์ข้อมูล ──
const fieldMappings = [
    { source: 'COMMUNITY_ID', target: 'COMMUNITY_ID' },
    { source: 'HAZARD_CODE', target: 'HAZARD_CODE' },
    { source: 'HAZARD_NAME', target: 'HAZARD_NAME' },
    { source: 'H_RAW', target: 'H_RAW' },
    { source: 'E_PERCENT', target: 'E_PERCENT' },
    { source: 'HEVC_100', target: 'HEVC_100' },
    { source: 'RISK_LEVEL', target: 'RISK_LEVEL' },
    { source: 'DATA_QUALITY', target: 'DATA_QUALITY' },
];

function confirmImport() {
    if (errorCount.value > 0) return;
    importDone.value = true;
}

function startOver() {
    importDone.value = false;
    removeFile();
}

const totalImportRows = computed(() => selectedSheets.value.reduce((sum, s) => sum + s.rows, 0));
</script>

<template>
    <Head title="นำเข้าข้อมูล" />

    <AdminLayout title="นำเข้าข้อมูล">
        <div class="mb-6">
            <h1 class="text-lg font-bold text-slate-800">นำเข้าข้อมูลเข้าสู่ระบบ</h1>
            <p class="mt-1 text-sm text-slate-500">รองรับการนำเข้าไฟล์ Excel/CSV สำหรับข้อมูลรายภัย (H-E-V-C) รายชีต</p>
        </div>

        <!-- Step indicator — reflects revealedStep, not a manually-navigable wizard -->
        <div class="flex items-center p-5 mb-6 overflow-x-auto bg-white border shadow-sm rounded-2xl border-slate-100">
            <template v-for="(step, i) in steps" :key="step.n">
                <div
                    class="flex items-center gap-2 px-2 py-1 text-sm font-medium rounded-lg shrink-0"
                    :class="revealedStep === step.n ? 'text-blue-600' : revealedStep > step.n ? 'text-emerald-600' : 'text-slate-400'"
                >
                    <span
                        class="flex items-center justify-center text-xs font-bold rounded-full h-7 w-7"
                        :class="
                            revealedStep === step.n
                                ? 'bg-blue-600 text-white'
                                : revealedStep > step.n
                                  ? 'bg-emerald-100 text-emerald-600'
                                  : 'bg-slate-100 text-slate-400'
                        "
                    >
                        <i v-if="revealedStep > step.n" class="fa-solid fa-check text-[10px]"></i>
                        <template v-else>{{ step.n }}</template>
                    </span>
                    {{ step.label }}
                </div>
                <div
                    v-if="i < steps.length - 1"
                    class="mx-2 h-px min-w-[12px] flex-1 transition-colors duration-500"
                    :class="revealedStep > step.n ? 'bg-emerald-200' : 'bg-slate-200'"
                ></div>
            </template>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <!-- Main content — single page, cards reveal progressively after upload -->
            <div class="space-y-6 xl:col-span-3">
                <div v-if="importDone" class="p-8 text-center border rounded-2xl border-emerald-100 bg-emerald-50">
                    <span class="flex items-center justify-center mx-auto mb-3 text-2xl rounded-full h-14 w-14 bg-emerald-100 text-emerald-600">
                        <i class="fa-solid fa-check"></i>
                    </span>
                    <h3 class="text-base font-semibold text-emerald-800">นำเข้าข้อมูลสำเร็จ (ตัวอย่าง)</h3>
                    <p class="mt-1 text-sm text-emerald-700">
                        นำเข้า {{ totalImportRows }} แถว จาก {{ selectedSheets.length }} ชีต — ระบบยังไม่ได้บันทึกลงฐานข้อมูลจริง (โหมดสาธิต)
                    </p>
                    <button
                        type="button"
                        @click="startOver"
                        class="px-5 py-2 mt-4 text-sm font-medium text-white rounded-xl bg-emerald-600 hover:bg-emerald-700"
                    >
                        นำเข้าไฟล์อื่น
                    </button>
                </div>

                <template v-else>
                    <!-- Row 1: อัปโหลดไฟล์ / แผ่นงานที่พบ / ผลตรวจสอบไฟล์ -->
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <!-- อัปโหลดไฟล์ — always visible, the only card shown before an upload starts -->
                        <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-100">
                            <h2 class="mb-4 font-semibold text-slate-800">อัปโหลดไฟล์</h2>

                            <div v-if="isUploading" class="flex flex-col items-center justify-center p-10 text-center border rounded-xl border-slate-200">
                                <span class="mb-3 border-4 border-blue-100 rounded-full h-9 w-9 animate-spin border-t-blue-600"></span>
                                <p class="text-sm font-medium text-slate-600">กำลังอัปโหลดไฟล์...</p>
                                <p class="max-w-full mt-1 text-xs truncate text-slate-400">{{ pendingFile?.name }}</p>
                            </div>

                            <div
                                v-else-if="!selectedFile"
                                class="flex flex-col items-center justify-center p-10 text-center transition-colors border-2 border-dashed cursor-pointer rounded-xl"
                                :class="isDragOver ? 'border-blue-400 bg-blue-50' : 'border-slate-200 hover:bg-slate-50'"
                                @click="pickFile"
                                @dragover.prevent="isDragOver = true"
                                @dragleave.prevent="isDragOver = false"
                                @drop.prevent="handleDrop"
                            >
                                <span class="flex items-center justify-center w-12 h-12 mb-3 text-xl text-blue-600 rounded-full bg-blue-50">
                                    <i class="fa-solid fa-cloud-arrow-up"></i>
                                </span>
                                <p class="text-sm font-medium text-slate-600">ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์</p>
                                <p class="mt-1 text-xs text-slate-400">รองรับไฟล์ Excel (.xlsx), CSV (.csv) ขนาดไม่เกิน 50 MB</p>
                            </div>

                            <div v-else class="flex items-center gap-3 rounded-xl border border-slate-200 p-4">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                    <i class="fa-solid fa-file-excel"></i>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-700">{{ selectedFile.name }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ formatFileSize(selectedFile.size) }} · อัปโหลดเมื่อ {{ uploadedAt }}</p>
                                </div>
                                <span
                                    class="flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-600"
                                >
                                    <i class="fa-solid fa-check"></i>
                                    อัปโหลดสำเร็จ
                                </span>
                            </div>

                            <p v-if="fileError" class="mt-2 text-xs text-red-600">{{ fileError }}</p>
                            <input ref="fileInputEl" type="file" accept=".xlsx,.csv" class="hidden" @change="handleFileChange" />

                            <div v-if="selectedFile && !isUploading" class="flex gap-3 mt-4">
                                <button
                                    type="button"
                                    @click="pickFile"
                                    class="flex items-center gap-2 px-4 py-2 text-sm border rounded-xl border-slate-200 text-slate-600 hover:bg-slate-50"
                                >
                                    <i class="text-xs fa-solid fa-arrows-rotate"></i>
                                    เปลี่ยนไฟล์
                                </button>
                                <button
                                    type="button"
                                    @click="removeFile"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 border border-red-200 rounded-xl hover:bg-red-50"
                                >
                                    <i class="text-xs fa-regular fa-trash-can"></i>
                                    ลบไฟล์
                                </button>
                            </div>
                        </div>

                        <!-- แผ่นงานที่พบในไฟล์ -->
                        <Transition name="fade-rise">
                            <div v-if="revealedStep >= 2" class="p-5 bg-white border shadow-sm rounded-2xl border-slate-100">
                                <h2 class="mb-1 font-semibold text-slate-800">แผ่นงานที่พบในไฟล์</h2>
                                <p class="mb-3 text-xs text-slate-400">เลือกเฉพาะชีตรายภัยที่ต้องการนำเข้าในรอบนี้</p>
                                <div class="overflow-y-auto divide-y max-h-72 divide-slate-100">
                                    <label
                                        v-for="sheet in sheets"
                                        :key="sheet.key"
                                        class="flex items-center gap-3 py-2.5"
                                        :class="sheet.selectable ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'"
                                    >
                                        <input
                                            type="checkbox"
                                            v-model="sheet.checked"
                                            :disabled="!sheet.selectable"
                                            class="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                                        />
                                        <span class="flex items-center justify-center text-xs rounded-lg h-7 w-7 bg-slate-100 text-slate-500">
                                            <i class="fa-solid fa-table"></i>
                                        </span>
                                        <span class="flex-1 min-w-0">
                                            <span class="block text-sm font-medium truncate text-slate-700">{{ sheet.name }}</span>
                                            <span class="block text-xs text-slate-400">{{ sheet.rows }} แถว</span>
                                        </span>
                                        <span
                                            v-if="sheet.note"
                                            class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="sheet.selectable ? 'bg-amber-50 text-amber-600' : 'bg-slate-100 text-slate-400'"
                                        >
                                            {{ sheet.note }}
                                        </span>
                                    </label>
                                </div>
                                <p class="mt-3 text-xs font-medium text-slate-500">
                                    เลือกแล้ว {{ selectedSheets.length }} จาก {{ sheets.filter((s) => s.selectable).length }} แผ่นงาน
                                </p>
                            </div>
                        </Transition>

                        <!-- ผลตรวจสอบไฟล์ -->
                        <Transition name="fade-rise">
                            <div v-if="revealedStep >= 3" class="p-5 bg-white border shadow-sm rounded-2xl border-slate-100">
                                <h2 class="mb-3 font-semibold text-slate-800">ผลตรวจสอบไฟล์</h2>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="rounded-xl bg-slate-50 p-2.5">
                                        <p class="text-[10px] text-slate-400">จำนวนแผ่นงาน</p>
                                        <p class="mt-0.5 text-lg font-bold text-slate-800">{{ selectedSheets.length }}</p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-2.5">
                                        <p class="text-[10px] text-slate-400">ข้อมูลพร้อมนำเข้า</p>
                                        <p class="mt-0.5 text-lg font-bold text-emerald-600">{{ totalImportRows }}</p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-2.5">
                                        <p class="text-[10px] text-slate-400">ข้อผิดพลาด</p>
                                        <p class="mt-0.5 text-lg font-bold text-red-600">{{ errorCount }}</p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-2.5">
                                        <p class="text-[10px] text-slate-400">คำเตือน</p>
                                        <p class="mt-0.5 text-lg font-bold text-amber-600">{{ warnCount }}</p>
                                    </div>
                                </div>
                                <h3 class="mt-4 mb-2 text-xs font-semibold text-slate-500">ผลการตรวจสอบโครงสร้างไฟล์</h3>
                                <ul class="space-y-2">
                                    <li v-for="check in validationChecks" :key="check.label" class="flex items-start justify-between gap-2 text-xs">
                                        <span class="flex items-center gap-1.5 text-slate-600">
                                            <i :class="[validationStyle[check.status].icon, validationStyle[check.status].color]"></i>
                                            {{ check.label }}
                                        </span>
                                        <span class="font-semibold shrink-0" :class="validationStyle[check.status].color">{{
                                            validationStyle[check.status].label
                                        }}</span>
                                    </li>
                                </ul>
                                <p v-if="errorCount > 0" class="mt-3 text-xs text-red-600">
                                    <i class="mr-1 fa-solid fa-circle-info"></i>
                                    ต้องแก้ไขข้อผิดพลาดในไฟล์ต้นฉบับก่อนจึงจะนำเข้าได้
                                </p>
                            </div>
                        </Transition>
                    </div>

                    <!-- Row 2: ตัวอย่างข้อมูลก่อนนำเข้า / จับคู่ฟิลด์ข้อมูล -->
                    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
                        <Transition name="fade-rise">
                            <div v-if="revealedStep >= 3" class="p-5 bg-white border shadow-sm rounded-2xl border-slate-100 xl:col-span-3">
                                <h2 class="mb-4 font-semibold text-slate-800">ตัวอย่างข้อมูลก่อนนำเข้า (อุทกภัย)</h2>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm text-left">
                                        <thead class="text-xs tracking-wider uppercase bg-slate-50 text-slate-400">
                                            <tr>
                                                <th class="px-3 py-2 font-medium">COMMUNITY_ID</th>
                                                <th class="px-3 py-2 font-medium">HAZARD_NAME</th>
                                                <th class="px-3 py-2 font-medium">H_RAW</th>
                                                <th class="px-3 py-2 font-medium">E_PERCENT</th>
                                                <th class="px-3 py-2 font-medium">HEVC_100</th>
                                                <th class="px-3 py-2 font-medium">DATA_QUALITY</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <tr v-for="row in previewRows" :key="row.COMMUNITY_ID">
                                                <td class="px-3 py-2 whitespace-nowrap text-slate-700">{{ row.COMMUNITY_ID }}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ row.HAZARD_NAME }}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ row.H_RAW }}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ row.E_PERCENT }}</td>
                                                <td class="px-3 py-2 font-medium whitespace-nowrap text-slate-700">{{ row.HEVC_100 }}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-slate-500">{{ row.DATA_QUALITY }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </Transition>

                        <Transition name="fade-rise">
                            <div v-if="revealedStep >= 4" class="p-5 bg-white border shadow-sm rounded-2xl border-slate-100 xl:col-span-2">
                                <h2 class="mb-1 font-semibold text-slate-800">จับคู่ฟิลด์ข้อมูล</h2>
                                <p class="mb-4 text-xs text-slate-400">ระบบจับคู่ชื่อฟิลด์จากไฟล์กับโครงสร้างของระบบให้อัตโนมัติ</p>
                                <div class="space-y-2">
                                    <div
                                        v-for="map in fieldMappings"
                                        :key="map.source"
                                        class="flex items-center justify-between gap-2 px-3 py-2 text-xs border rounded-lg border-slate-100 bg-slate-50"
                                    >
                                        <span class="font-mono truncate text-slate-600">{{ map.source }}</span>
                                        <i class="fa-solid fa-arrow-right shrink-0 text-[10px] text-slate-300"></i>
                                        <span class="font-mono truncate text-slate-600">{{ map.target }}</span>
                                        <span class="shrink-0 text-emerald-500"><i class="fa-solid fa-link"></i></span>
                                    </div>
                                </div>
                                <p class="mt-4 text-xs font-medium text-emerald-600">
                                    จับคู่แล้ว {{ fieldMappings.length }} จาก {{ fieldMappings.length }} ฟิลด์
                                </p>
                            </div>
                        </Transition>
                    </div>

                    <!-- ยืนยันและนำเข้า -->
                    <Transition name="fade-rise">
                        <div v-if="revealedStep >= 5" class="flex flex-col items-stretch justify-end gap-3 sm:flex-row">
                            <button
                                type="button"
                                class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
                            >
                                <i class="fa-regular fa-floppy-disk mr-1.5"></i>
                                บันทึกเป็นแบบร่าง
                            </button>
                            <button
                                type="button"
                                class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
                            >
                                <i class="fa-solid fa-download mr-1.5"></i>
                                ดาวน์โหลดแม่แบบ
                            </button>
                            <button
                                type="button"
                                @click="confirmImport"
                                :disabled="errorCount > 0"
                                class="rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                <i class="fa-solid fa-check mr-1.5"></i>
                                ยืนยันนำเข้าข้อมูล
                            </button>
                        </div>
                    </Transition>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.fade-rise-enter-active {
    transition:
        opacity 0.4s ease,
        transform 0.4s ease;
}
.fade-rise-enter-from {
    opacity: 0;
    transform: translateY(10px);
}
.fade-rise-leave-active {
    transition: opacity 0.15s ease;
}
.fade-rise-leave-to {
    opacity: 0;
}
</style>
