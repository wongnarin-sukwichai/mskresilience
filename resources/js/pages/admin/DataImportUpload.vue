<script setup lang="ts">
import { workGroupIcons } from '@/data/workGroupIcons';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

// Import page for one กลุ่มงาน — the server only renders this page if User::canImport(group). Flow
// (DataImportController): upload → validate (nothing saved; the result comes back as `preview`) → staff
// confirms → live, with no admin review. Only groups with an importer (`importReady`) accept uploads so far.
type Issue = { row: number | null; column: string | null; message: string };
type CheckStatus = 'ok' | 'warning' | 'error' | 'skipped';
type Preview = {
    token: string;
    filename: string;
    checks: { label: string; status: CheckStatus; detail: string }[];
    fatal: boolean;
    columns: { name: string; found: boolean; errors: number; blanks: number }[];
    extraColumns: string[];
    rows: { row: number; values: Record<string, string | number | null> }[];
    errors: Issue[];
    warnings: Issue[];
    summary: {
        rowCount: number;
        blankCount: number;
        replaceCount: number;
        replacedBatches: { createdAt: string | null; user: string | null; rows: number }[];
        included: string[];
        missing: string[];
    };
};
type HistoryItem = {
    id: number;
    createdAt: string;
    user: string | null;
    filename: string;
    rowCount: number;
    replacedCount: number;
    communityCount: number;
};

// Past imports (and cancelling them) live in the ประวัตินำเข้า menu (pages/admin/ImportHistory.vue).
const props = defineProps<{
    group: { value: string; label: string; sheet: string };
    columns: string[];
    descriptions: (string | null)[];
    exampleRows: (string | number | null)[][];
    importReady: boolean;
    preview: Preview | null;
    lastImport: HistoryItem | null;
}>();

// "ตัวอย่างข้อมูล" viewer: web-only demo rows (App\Import\ImportExamples), never part of the template.
const DATE_COLUMNS = ['ASSESSMENT_DATE', 'EVENT_START', 'EVENT_END'];
const exampleOpen = ref(false);

function formatCell(column: string, value: string | number) {
    if (DATE_COLUMNS.includes(column) && typeof value === 'string') {
        const [y, m, d] = value.split('-');
        return `${d}/${m}/${y}`;
    }
    return value;
}

function closeExampleOnEscape(event: KeyboardEvent) {
    if (event.key === 'Escape') exampleOpen.value = false;
}

onMounted(() => document.addEventListener('keydown', closeExampleOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeExampleOnEscape));

const steps = computed(() => [
    { title: 'ดาวน์โหลดแบบฟอร์ม', detail: 'ไฟล์ Excel ที่มีหัวคอลัมน์ตรงตามที่ระบบต้องการ', ready: true },
    {
        title: 'กรอกข้อมูลในชีตแรก',
        detail: 'เลือกรหัสชุมชน (COMMUNITY_ID) จากรายการ ดูชื่อชุมชนได้ในชีต "รายชื่อชุมชน" ชี้เมาส์ที่หัวคอลัมน์เพื่อดูคำอธิบาย ช่องที่ไม่มีข้อมูลให้เว้นว่างไว้ (ไม่ต้องใส่ 0)',
        ready: true,
    },
    { title: 'อัปโหลดไฟล์', detail: 'ระบบตรวจชื่อคอลัมน์ รหัสชุมชน และค่าในแต่ละช่อง', ready: props.importReady },
    { title: 'ตรวจผลและยืนยัน', detail: 'ดูข้อผิดพลาด/คำเตือน และชุมชนที่มีหรือขาด แล้วกดยืนยันเพื่อนำข้อมูลเข้าระบบ', ready: props.importReady },
]);

const ACCEPTED_EXTENSIONS = ['.xlsx', '.xls'];

const uploadForm = useForm<{ file: File | null }>({ file: null });
const selectedFile = computed(() => uploadForm.file);
const fileError = ref('');
const isDragOver = ref(false);
const fileInputEl = ref<HTMLInputElement | null>(null);

function acceptFile(file: File | undefined) {
    if (!file) return;
    const name = file.name.toLowerCase();
    if (!ACCEPTED_EXTENSIONS.some((ext) => name.endsWith(ext))) {
        fileError.value = 'รองรับเฉพาะไฟล์ Excel (.xlsx, .xls)';
        return;
    }
    fileError.value = '';
    uploadForm.clearErrors();
    uploadForm.file = file;
}

function handleFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    acceptFile(input.files?.[0]);
    input.value = '';
}

function handleDrop(event: DragEvent) {
    isDragOver.value = false;
    acceptFile(event.dataTransfer?.files?.[0]);
}

function removeFile() {
    uploadForm.reset();
    fileError.value = '';
}

function submitUpload() {
    uploadForm.post(route('data-import.validate', props.group.value), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
}

// --- Validation result ----------------------------------------------------------------------------------
// `preview.checks` (ImportValidator::checks()) is revealed one line at a time: each line "runs" with a spinner,
// then shows its real ✓/⚠/✗ result. Owner decisions 2026-11-02: ~10–15s in total, so it reads as thorough
// and staff slow down, with NO skip button. A fatal stop (unreadable file, header errors) is a short list
// and is shown at FATAL_STEP_MS per line, so a broken file isn't made to wait. The summary, issues and
// confirm button appear only after the last line.
const TOTAL_REVEAL_MS = 12000;
const MIN_STEP_MS = 300;
const FATAL_STEP_MS = 800;
const revealed = ref(0);
let revealTimer: ReturnType<typeof setTimeout> | null = null;

function stopReveal() {
    if (revealTimer) clearTimeout(revealTimer);
    revealTimer = null;
}

const revealDone = computed(() => !!props.preview && revealed.value >= props.preview.checks.length);

// While running, the checks scroll inside a fixed-height box (like a live log) so a 35-column sheet doesn't
// stretch the page. Once done, they collapse to a count summary plus only the non-✓ lines, and "แสดงทั้งหมด"
// opens the full list as a grid.
const checksBox = ref<HTMLElement | null>(null);
const showAllChecks = ref(false);

function scrollChecksToEnd() {
    nextTick(() => checksBox.value?.scrollTo({ top: checksBox.value.scrollHeight, behavior: 'smooth' }));
}

const checkCounts = computed(() => {
    const counts: Record<CheckStatus, number> = { ok: 0, warning: 0, error: 0, skipped: 0 };
    props.preview?.checks.forEach((c) => counts[c.status]++);
    return counts;
});
const problemChecks = computed(() => props.preview?.checks.filter((c) => c.status !== 'ok') ?? []);
const checkIcon: Record<CheckStatus, string> = {
    ok: 'fa-circle-check text-emerald-600',
    warning: 'fa-triangle-exclamation text-amber-500',
    error: 'fa-circle-xmark text-red-600',
    skipped: 'fa-circle-minus text-slate-400',
};
const checkDetailClass: Record<CheckStatus, string> = {
    ok: 'text-emerald-700',
    warning: 'text-amber-700',
    error: 'text-red-600',
    skipped: 'text-slate-400',
};

// Confirm dialog (owner decisions 2026-11-02): recap, a required "checked" tick, a prominent replace warning
// naming the earlier imports, and a note that the importer's name is recorded.
const confirmOpen = ref(false);
const confirmChecked = ref(false);
const assessmentDates = computed(() =>
    [...new Set(props.preview?.rows.map((r) => r.values.ASSESSMENT_DATE).filter((d): d is string => typeof d === 'string'))].sort(),
);

function openConfirm() {
    confirmChecked.value = false;
    confirmOpen.value = true;
}
const canConfirm = computed(() => !!props.preview && props.preview.errors.length === 0 && props.preview.rows.length > 0);

// Cell highlighting in the preview table: "row|column" → error/warning.
const issueAt = computed(() => {
    const map = new Map<string, 'error' | 'warning'>();
    props.preview?.warnings.forEach((w) => w.row && w.column && map.set(`${w.row}|${w.column}`, 'warning'));
    props.preview?.errors.forEach((e) => e.row && e.column && map.set(`${e.row}|${e.column}`, 'error'));
    return map;
});
const rowsWithErrors = computed(() => new Set(props.preview?.errors.filter((e) => e.row && !e.column).map((e) => e.row)));

// Parsed-data table: a fixed-height box that scrolls both ways, with the header row and the แถว/COMMUNITY_ID
// columns sticky. COMMUNITY_ID is moved to the front so it can stay pinned. When the file has problems, the
// table opens on "เฉพาะแถวที่มีปัญหา".
const problemRowNumbers = computed(
    () => new Set([...(props.preview?.errors ?? []), ...(props.preview?.warnings ?? [])].filter((i) => i.row && i.row > 1).map((i) => i.row)),
);
const tableFilter = ref<'problems' | 'all'>('all');
const tableColumns = computed(() => {
    const cols = props.preview?.columns.map((c) => c.name) ?? [];
    return cols.includes('COMMUNITY_ID') ? ['COMMUNITY_ID', ...cols.filter((c) => c !== 'COMMUNITY_ID')] : cols;
});
// Sticky cells need an opaque background, so every cell gets one.
function cellClass(row: number, col: string) {
    const issue = issueAt.value.get(`${row}|${col}`);
    if (issue === 'error') return 'bg-red-100 text-red-700';
    if (issue === 'warning') return 'bg-amber-50 text-amber-700';
    return rowsWithErrors.value.has(row) ? 'bg-red-50 text-slate-700' : 'bg-white text-slate-700';
}
const tableRows = computed(() =>
    tableFilter.value === 'problems' ? (props.preview?.rows.filter((r) => problemRowNumbers.value.has(r.row)) ?? []) : (props.preview?.rows ?? []),
);

// Declared after everything it touches: `immediate` runs it during setup.
watch(
    () => props.preview?.token,
    () => {
        stopReveal();
        revealed.value = 0;
        showAllChecks.value = false;
        tableFilter.value = problemRowNumbers.value.size ? 'problems' : 'all';
        const checks = props.preview?.checks ?? [];
        const stepMs = props.preview?.fatal ? FATAL_STEP_MS : Math.max(MIN_STEP_MS, TOTAL_REVEAL_MS / Math.max(checks.length, 1));
        const tick = () => {
            revealed.value++;
            scrollChecksToEnd();
            if (revealed.value < checks.length) revealTimer = setTimeout(tick, stepMs);
        };
        if (checks.length) revealTimer = setTimeout(tick, stepMs);
    },
    { immediate: true },
);
onUnmounted(stopReveal);

// --- Saving, then the success card (owner decision 2026-11-02) ---------------------------------------------
// Confirm sends the request immediately. The dialog then shows the server's real save steps one at a time,
// about SAVE_TOTAL_MS in total, then closes onto the success card (`lastImport`). A step can only turn ✓ once
// the server has answered with success, so the list never claims something that hasn't happened. If the save
// fails, the dialog closes and AdminLayout's red toast shows the error.
const SAVE_TOTAL_MS = 6000;
const confirming = ref(false);
const saveSteps = ref<string[]>([]);
const saveStep = ref(0);
const saveServerDone = ref(false);
const successVisible = ref(false);
let saveTimer: ReturnType<typeof setTimeout> | null = null;

function stopSaveTimer() {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = null;
}
onUnmounted(stopSaveTimer);

function confirmImport() {
    if (!props.preview || !confirmChecked.value) return;
    const s = props.preview.summary;
    saveSteps.value = [
        'ตรวจสอบไฟล์อีกครั้งก่อนบันทึก',
        `บันทึกข้อมูล ${s.rowCount} แถว จาก ${s.included.length} ชุมชน` + (s.replaceCount ? ` (แทนที่ข้อมูลเดิม ${s.replaceCount} แถว)` : ''),
        'จัดเก็บไฟล์ต้นฉบับ',
        'บันทึกประวัติการนำเข้า',
    ];
    saveStep.value = 0;
    saveServerDone.value = false;
    confirming.value = true;
    successVisible.value = false;

    const stepMs = SAVE_TOTAL_MS / saveSteps.value.length;
    const advance = () => {
        if (!saveServerDone.value) {
            saveTimer = setTimeout(advance, 200); // hold on the current line until the server has answered
            return;
        }
        saveStep.value++;
        if (saveStep.value < saveSteps.value.length) {
            saveTimer = setTimeout(advance, stepMs);
        } else {
            saveTimer = setTimeout(() => {
                confirming.value = false;
                confirmOpen.value = false;
                successVisible.value = true;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }, 600);
        }
    };
    saveTimer = setTimeout(advance, stepMs);

    router.post(
        route('data-import.confirm', props.group.value),
        { token: props.preview.token },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                if (page.props.lastImport) {
                    saveServerDone.value = true;
                } else {
                    failSave(); // redirected back with an error flash
                }
            },
            onError: () => failSave(),
        },
    );
}

function failSave() {
    stopSaveTimer();
    confirming.value = false;
    confirmOpen.value = false;
}

function importAnother() {
    successVisible.value = false;
}

function cancelImport() {
    router.delete(route('data-import.cancel', props.group.value), { preserveScroll: true });
}

function formatDateTime(iso: string) {
    return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
}

function formatFileSize(bytes: number) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <Head :title="`นำเข้าข้อมูล ${group.label}`" />

    <AdminLayout title="นำเข้าข้อมูล">
        <Link :href="route('data-import.index')" class="mb-4 inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-blue-600">
            <i class="fa-solid fa-arrow-left text-xs"></i>เลือกงานอื่น
        </Link>

        <div class="mb-6 flex items-center gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white">
                <i :class="[workGroupIcons[group.value] ?? 'fa-solid fa-file-excel', 'text-xl']"></i>
            </span>
            <div>
                <h1 class="text-lg font-bold text-slate-800">นำเข้าข้อมูล{{ group.label }}</h1>
                <p class="mt-0.5 text-sm text-slate-500">
                    ใช้ข้อมูลจากชีต <span class="font-mono text-slate-700">{{ group.sheet }}</span> ในไฟล์นำเข้าข้อมูล
                </p>
            </div>
        </div>

        <div class="mb-5 grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="mb-4 font-semibold text-slate-800">ขั้นตอนการนำเข้า</h2>
                <ol class="space-y-3">
                    <li v-for="(step, i) in steps" :key="step.title" class="flex gap-3">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                            :class="step.ready ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-400'"
                        >
                            {{ i + 1 }}
                        </span>
                        <div>
                            <p class="text-sm font-medium" :class="step.ready ? 'text-slate-800' : 'text-slate-400'">
                                {{ step.title }}
                                <span v-if="!step.ready" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-normal">
                                    อยู่ระหว่างการพัฒนา
                                </span>
                            </p>
                            <p class="mt-0.5 text-xs" :class="step.ready ? 'text-slate-500' : 'text-slate-400'">{{ step.detail }}</p>
                        </div>
                    </li>
                </ol>
            </div>

            <div class="flex flex-col rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <div class="mb-1 flex items-center justify-between gap-3">
                    <h2 class="font-semibold text-slate-800">แบบฟอร์มนำเข้าข้อมูล</h2>
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-emerald-600 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50"
                        @click="exampleOpen = true"
                    >
                        <i class="fa-solid fa-eye"></i>ตัวอย่างข้อมูล
                    </button>
                </div>
                <p class="mb-3 text-xs text-slate-500">คอลัมน์ในแบบฟอร์ม ({{ columns.length }} คอลัมน์)</p>
                <div class="mb-4 flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
                    <span v-for="col in columns" :key="col" class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-[11px] text-slate-600">
                        {{ col }}
                    </span>
                </div>
                <a
                    :href="route('data-import.template', group.value)"
                    class="mt-auto inline-flex items-center justify-center gap-2 self-start rounded-xl bg-emerald-600 px-5 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                >
                    <i class="fa-solid fa-file-arrow-down"></i>ดาวน์โหลดแบบฟอร์ม (.xlsx)
                </a>
            </div>
        </div>

        <!-- Success card, after the saving steps -->
        <div v-if="successVisible && lastImport" class="rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm">
            <div class="flex flex-col items-center text-center">
                <span class="mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                    <i class="fa-solid fa-circle-check text-4xl"></i>
                </span>
                <h2 class="text-lg font-bold text-slate-800">นำเข้าข้อมูลสำเร็จ</h2>
                <p class="mt-1 text-sm text-slate-500">ข้อมูล{{ group.label }}เข้าระบบและแสดงผลแล้ว</p>
                <span class="mt-2 rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-600"
                    >เลขอ้างอิงการนำเข้า #{{ lastImport.id }}</span
                >
            </div>
            <dl class="mx-auto mt-5 grid max-w-xl grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-3 text-center">
                    <dt class="text-xs text-slate-500">นำเข้า</dt>
                    <dd class="text-lg font-bold text-slate-800">{{ lastImport.rowCount }} <span class="text-sm font-normal">แถว</span></dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3 text-center">
                    <dt class="text-xs text-slate-500">ชุมชน</dt>
                    <dd class="text-lg font-bold text-slate-800">{{ lastImport.communityCount }}</dd>
                </div>
                <div class="col-span-2 rounded-xl bg-slate-50 p-3 text-center sm:col-span-1">
                    <dt class="text-xs text-slate-500">แทนที่ข้อมูลเดิม</dt>
                    <dd class="text-lg font-bold text-slate-800">{{ lastImport.replacedCount }} <span class="text-sm font-normal">แถว</span></dd>
                </div>
            </dl>
            <p class="mt-4 text-center text-xs text-slate-500">
                <i class="fa-solid fa-file-excel mr-1 text-emerald-600"></i>{{ lastImport.filename }} · นำเข้าเมื่อ
                {{ formatDateTime(lastImport.createdAt) }} โดย {{ lastImport.user ?? '–' }}
            </p>
            <div class="mt-5 flex justify-center gap-3">
                <Link
                    :href="route('import-history.index')"
                    class="rounded-xl border border-slate-200 bg-white px-5 py-2 text-sm text-slate-600 hover:bg-slate-50"
                >
                    <i class="fa-solid fa-clock-rotate-left mr-1.5"></i>ดูประวัตินำเข้า
                </Link>
                <button
                    type="button"
                    class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    @click="importAnother"
                >
                    <i class="fa-solid fa-plus mr-1.5"></i>นำเข้าไฟล์ใหม่
                </button>
            </div>
        </div>

        <!-- Upload (hidden while a validated file waits for confirmation) -->
        <div v-else-if="!preview" class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold text-slate-800">อัปโหลดไฟล์</h2>

            <div v-if="!importReady" class="rounded-xl bg-slate-50 p-6 text-center text-sm text-slate-500">
                <i class="fa-solid fa-person-digging mb-2 block text-xl text-slate-400"></i>
                การนำเข้าข้อมูล{{ group.label }}อยู่ระหว่างการพัฒนา ตอนนี้ดาวน์โหลดแบบฟอร์มและดูตัวอย่างข้อมูลได้แล้ว
            </div>

            <div
                v-else-if="!selectedFile"
                class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-12 text-center transition-colors"
                :class="isDragOver ? 'border-blue-400 bg-blue-50' : 'border-slate-200 hover:bg-slate-50'"
                role="button"
                tabindex="0"
                @click="fileInputEl?.click()"
                @keydown.enter="fileInputEl?.click()"
                @dragover.prevent="isDragOver = true"
                @dragleave.prevent="isDragOver = false"
                @drop.prevent="handleDrop"
            >
                <span class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                    <i class="fa-solid fa-cloud-arrow-up text-xl"></i>
                </span>
                <p class="text-sm font-medium text-slate-700">ลากไฟล์มาวาง หรือคลิกเพื่อเลือกไฟล์</p>
                <p class="mt-1 text-xs text-slate-400">รองรับไฟล์ Excel (.xlsx, .xls)</p>
            </div>

            <div v-else class="flex items-center gap-3 rounded-xl border border-slate-200 p-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                    <i class="fa-solid fa-file-excel text-lg"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-slate-700">{{ selectedFile.name }}</p>
                    <p class="text-xs text-slate-400">{{ formatFileSize(selectedFile.size) }}</p>
                </div>
                <button
                    type="button"
                    title="ลบไฟล์"
                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-red-50 hover:text-red-600"
                    @click="removeFile"
                >
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <input ref="fileInputEl" type="file" accept=".xlsx,.xls" class="hidden" @change="handleFileChange" />
            <p v-if="fileError || uploadForm.errors.file" class="mt-2 text-xs text-red-600">{{ fileError || uploadForm.errors.file }}</p>

            <div v-if="importReady" class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                <p class="text-xs text-slate-400">ระบบจะตรวจไฟล์ก่อน ยังไม่บันทึกจนกว่าคุณจะกดยืนยัน</p>
                <button
                    type="button"
                    :disabled="!selectedFile || uploadForm.processing"
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="submitUpload"
                >
                    <i v-if="uploadForm.processing" class="fa-solid fa-spinner fa-spin"></i>
                    {{ uploadForm.processing ? 'กำลังตรวจสอบ…' : 'ตรวจสอบไฟล์' }}
                </button>
            </div>
        </div>

        <!-- Validation result -->
        <div v-else class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-slate-800">ผลการตรวจสอบไฟล์</h2>
                    <p class="mt-0.5 text-xs text-slate-500"><i class="fa-solid fa-file-excel mr-1 text-emerald-600"></i>{{ preview.filename }}</p>
                </div>
                <span v-if="!revealDone" class="flex items-center gap-1.5 text-xs text-blue-600">
                    <i class="fa-solid fa-spinner fa-spin"></i>กำลังตรวจสอบ {{ Math.min(revealed + 1, preview.checks.length) }}/{{
                        preview.checks.length
                    }}
                </span>
            </div>

            <!-- Step-by-step checks -->
            <div class="mb-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div
                    class="h-full rounded-full bg-blue-600 transition-all duration-300"
                    :style="{ width: `${(revealed / Math.max(preview.checks.length, 1)) * 100}%` }"
                ></div>
            </div>
            <!-- While running: a fixed-height box that follows the latest line -->
            <ol v-if="!revealDone" ref="checksBox" class="mb-5 max-h-72 divide-y divide-slate-50 overflow-y-auto pr-1 text-sm">
                <li v-for="(check, i) in preview.checks" v-show="i <= revealed" :key="i" class="flex items-start gap-3 py-1.5">
                    <i v-if="i === revealed" class="fa-solid fa-spinner fa-spin mt-0.5 w-4 text-center text-blue-600"></i>
                    <i v-else class="fa-solid mt-0.5 w-4 text-center" :class="checkIcon[check.status]"></i>
                    <span class="flex-1 text-slate-700">{{ check.label }}</span>
                    <span v-if="i === revealed" class="text-xs text-slate-400">กำลังตรวจสอบ…</span>
                    <span v-else class="text-right text-xs" :class="checkDetailClass[check.status]">{{ check.detail }}</span>
                </li>
            </ol>

            <!-- Done: count summary + only the non-✓ lines; the full list opens as a grid -->
            <div v-else class="mb-5 rounded-xl border border-slate-100 p-4">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <span class="font-semibold text-slate-800">ตรวจสอบครบ {{ preview.checks.length }} รายการ</span>
                    <span class="text-emerald-700"><i class="fa-solid fa-circle-check mr-1"></i>ผ่าน {{ checkCounts.ok }}</span>
                    <span v-if="checkCounts.warning" class="text-amber-700"
                        ><i class="fa-solid fa-triangle-exclamation mr-1"></i>คำเตือน {{ checkCounts.warning }}</span
                    >
                    <span v-if="checkCounts.error" class="text-red-600"
                        ><i class="fa-solid fa-circle-xmark mr-1"></i>ผิด {{ checkCounts.error }}</span
                    >
                    <span v-if="checkCounts.skipped" class="text-slate-400"
                        ><i class="fa-solid fa-circle-minus mr-1"></i>ข้าม {{ checkCounts.skipped }}</span
                    >
                </div>
                <ul v-if="!showAllChecks && problemChecks.length" class="mt-3 space-y-1 text-sm">
                    <li v-for="(check, i) in problemChecks" :key="i" class="flex items-start gap-3">
                        <i class="fa-solid mt-0.5 w-4 text-center" :class="checkIcon[check.status]"></i>
                        <span class="flex-1 text-slate-700">{{ check.label }}</span>
                        <span class="text-right text-xs" :class="checkDetailClass[check.status]">{{ check.detail }}</span>
                    </li>
                </ul>
                <ul v-if="showAllChecks" class="mt-3 grid gap-x-6 gap-y-1 text-xs md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="(check, i) in preview.checks" :key="i" class="flex items-start gap-2">
                        <i class="fa-solid mt-0.5 w-4 text-center" :class="checkIcon[check.status]"></i>
                        <span class="flex-1 text-slate-700">{{ check.label }}</span>
                        <span class="text-right" :class="checkDetailClass[check.status]">{{ check.detail }}</span>
                    </li>
                </ul>
                <button type="button" class="mt-3 text-xs text-blue-600 hover:underline" @click="showAllChecks = !showAllChecks">
                    {{ showAllChecks ? 'ย่อรายการ' : 'แสดงทั้งหมด ' + preview.checks.length + ' รายการ' }}
                    <i class="fa-solid ml-0.5" :class="showAllChecks ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                </button>
            </div>

            <template v-if="revealDone">
                <!-- Summary -->
                <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">จำนวนแถว</p>
                        <p class="text-lg font-bold text-slate-800">{{ preview.summary.rowCount }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">ชุมชนในไฟล์</p>
                        <p class="text-lg font-bold text-slate-800">
                            {{ preview.summary.included.length }}
                            <span class="text-sm font-normal text-slate-400"
                                >จาก {{ preview.summary.included.length + preview.summary.missing.length }} ชุมชน</span
                            >
                        </p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">ช่องว่าง (ไม่มีข้อมูล)</p>
                        <p class="text-lg font-bold text-slate-800">{{ preview.summary.blankCount }}</p>
                    </div>
                    <div class="rounded-xl p-3" :class="preview.summary.replaceCount ? 'bg-amber-50' : 'bg-slate-50'">
                        <p class="text-xs" :class="preview.summary.replaceCount ? 'text-amber-700' : 'text-slate-500'">แทนที่ข้อมูลเดิม</p>
                        <p class="text-lg font-bold" :class="preview.summary.replaceCount ? 'text-amber-700' : 'text-slate-800'">
                            {{ preview.summary.replaceCount }} <span class="text-sm font-normal">แถว</span>
                        </p>
                    </div>
                </div>

                <details
                    v-if="preview.summary.missing.length && preview.summary.included.length"
                    class="mb-5 rounded-xl bg-slate-50 px-4 py-2.5 text-xs text-slate-600"
                >
                    <summary class="cursor-pointer font-medium">ชุมชนที่ไม่มีในไฟล์ ({{ preview.summary.missing.length }} ชุมชน)</summary>
                    <p class="mt-2 font-mono leading-relaxed">{{ preview.summary.missing.join(', ') }}</p>
                </details>

                <!-- Errors / warnings -->
                <div v-if="preview.errors.length" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4">
                    <p class="mb-2 text-sm font-semibold text-red-700">
                        <i class="fa-solid fa-circle-xmark mr-1"></i>พบข้อผิดพลาด {{ preview.errors.length }} รายการ กรุณาแก้ไขในไฟล์แล้วอัปโหลดใหม่
                    </p>
                    <ul class="max-h-56 space-y-1 overflow-y-auto text-xs text-red-700">
                        <li v-for="(e, i) in preview.errors" :key="i">
                            <span v-if="e.row" class="font-medium">แถว {{ e.row }}</span>
                            <span v-if="e.column" class="font-mono"> · {{ e.column }}</span>
                            <span v-if="e.row || e.column">: </span>{{ e.message }}
                        </li>
                    </ul>
                </div>
                <div v-if="preview.warnings.length" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="mb-2 text-sm font-semibold text-amber-700">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>คำเตือน {{ preview.warnings.length }} รายการ (นำเข้าได้)
                    </p>
                    <ul class="max-h-40 space-y-1 overflow-y-auto text-xs text-amber-700">
                        <li v-for="(w, i) in preview.warnings" :key="i">
                            <span v-if="w.row && w.row > 1" class="font-medium">แถว {{ w.row }}: </span>{{ w.message }}
                        </li>
                    </ul>
                </div>

                <!-- Data preview -->
                <div v-if="preview.rows.length" class="mb-5">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-700">ข้อมูลที่อ่านได้จากไฟล์</h3>
                        <div v-if="problemRowNumbers.size" class="flex rounded-lg bg-slate-100 p-0.5 text-xs">
                            <button
                                type="button"
                                class="rounded-md px-3 py-1"
                                :class="tableFilter === 'problems' ? 'bg-white font-medium text-slate-800 shadow-sm' : 'text-slate-500'"
                                @click="tableFilter = 'problems'"
                            >
                                เฉพาะแถวที่มีปัญหา ({{ problemRowNumbers.size }})
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-3 py-1"
                                :class="tableFilter === 'all' ? 'bg-white font-medium text-slate-800 shadow-sm' : 'text-slate-500'"
                                @click="tableFilter = 'all'"
                            >
                                ทั้งหมด ({{ preview.rows.length }})
                            </button>
                        </div>
                    </div>
                    <div class="max-h-[400px] overflow-auto rounded-xl border border-slate-200">
                        <table class="min-w-full border-separate border-spacing-0 text-xs">
                            <thead>
                                <tr>
                                    <th
                                        class="sticky left-0 top-0 z-30 w-14 min-w-14 border-b border-r border-slate-200 bg-slate-50 px-3 py-2 text-left font-normal text-slate-400"
                                    >
                                        แถว
                                    </th>
                                    <th
                                        v-for="col in tableColumns"
                                        :key="col"
                                        class="sticky top-0 whitespace-nowrap border-b border-r border-slate-200 bg-slate-50 px-3 py-2 text-left font-mono font-semibold text-slate-700 last:border-r-0"
                                        :class="col === 'COMMUNITY_ID' ? 'left-14 z-30' : 'z-20'"
                                    >
                                        {{ col }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in tableRows" :key="r.row">
                                    <td
                                        class="sticky left-0 z-10 w-14 min-w-14 border-b border-r border-slate-100 px-3 py-1.5 text-slate-400"
                                        :class="rowsWithErrors.has(r.row) ? 'bg-red-50' : 'bg-white'"
                                    >
                                        {{ r.row }}
                                    </td>
                                    <td
                                        v-for="col in tableColumns"
                                        :key="col"
                                        class="whitespace-nowrap border-b border-r border-slate-100 px-3 py-1.5 last:border-r-0"
                                        :class="[col === 'COMMUNITY_ID' ? 'sticky left-14 z-10 font-medium' : '', cellClass(r.row, col)]"
                                    >
                                        <template v-if="r.values[col] !== null && r.values[col] !== undefined">
                                            {{ formatCell(col, r.values[col]!) }}
                                        </template>
                                        <span v-else-if="issueAt.get(`${r.row}|${col}`) === 'error'">ผิด/ว่าง</span>
                                        <span v-else class="text-slate-400">ไม่มีข้อมูล</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <p class="text-xs" :class="canConfirm ? 'text-slate-500' : 'text-red-600'">
                        {{
                            canConfirm
                                ? `กดยืนยันแล้วข้อมูล ${preview.summary.rowCount} แถวจะเข้าระบบทันที`
                                : 'ยังนำเข้าไม่ได้ กรุณาแก้ไขข้อผิดพลาดในไฟล์แล้วอัปโหลดใหม่'
                        }}
                    </p>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-xl px-5 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
                            @click="cancelImport"
                        >
                            {{ canConfirm ? 'ยกเลิก' : 'อัปโหลดไฟล์ใหม่' }}
                        </button>
                        <button
                            v-if="canConfirm"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700"
                            @click="openConfirm"
                        >
                            ยืนยันนำเข้าข้อมูล
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <!-- Confirm dialog -->
        <div
            v-if="confirmOpen && (preview || confirming)"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @click.self="!confirming && (confirmOpen = false)"
        >
            <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex items-center justify-between bg-blue-600 px-6 py-4">
                    <h3 class="text-base font-semibold text-white">{{ confirming ? 'กำลังนำเข้าข้อมูล' : 'ยืนยันนำเข้าข้อมูล' }}{{ group.label }}</h3>
                    <button
                        v-if="!confirming"
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-blue-100 hover:bg-white/15 hover:text-white"
                        @click="confirmOpen = false"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <!-- Saving: the real save steps, one at a time -->
                <div v-if="confirming" class="p-6">
                    <div class="mb-4 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div
                            class="h-full rounded-full bg-blue-600 transition-all duration-500"
                            :style="{ width: `${(saveStep / saveSteps.length) * 100}%` }"
                        ></div>
                    </div>
                    <ol class="space-y-2.5 text-sm">
                        <li v-for="(label, i) in saveSteps" v-show="i <= saveStep" :key="i" class="flex items-center gap-3">
                            <i v-if="i === saveStep" class="fa-solid fa-spinner fa-spin w-4 text-center text-blue-600"></i>
                            <i v-else class="fa-solid fa-circle-check w-4 text-center text-emerald-600"></i>
                            <span :class="i === saveStep ? 'text-slate-800' : 'text-slate-500'">{{ label }}</span>
                        </li>
                    </ol>
                    <p class="mt-5 text-xs text-slate-400">กรุณาอย่าปิดหน้านี้จนกว่าจะเสร็จ</p>
                </div>

                <template v-else-if="preview">
                    <div class="space-y-4 p-6 text-sm">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-xl bg-slate-50 p-4">
                            <dt class="text-slate-500">ไฟล์</dt>
                            <dd class="truncate font-medium text-slate-800" :title="preview.filename">{{ preview.filename }}</dd>
                            <dt class="text-slate-500">จำนวนข้อมูล</dt>
                            <dd class="font-medium text-slate-800">
                                {{ preview.summary.rowCount }} แถว จาก {{ preview.summary.included.length }} ชุมชน
                            </dd>
                            <template v-if="assessmentDates.length">
                                <dt class="text-slate-500">วันที่ประเมิน</dt>
                                <dd class="font-medium text-slate-800">
                                    {{ assessmentDates.map((d) => formatCell('ASSESSMENT_DATE', d)).join(', ') }}
                                </dd>
                            </template>
                            <dt class="text-slate-500">ช่องว่าง</dt>
                            <dd class="text-slate-800">{{ preview.summary.blankCount }} ช่อง (บันทึกเป็นไม่มีข้อมูล)</dd>
                        </dl>

                        <div v-if="preview.summary.replaceCount" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-800">
                            <p class="font-semibold">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i>จะแทนที่ข้อมูลเดิม {{ preview.summary.replaceCount }} แถว
                            </p>
                            <p class="mt-1 text-xs">ข้อมูลของชุมชนและวันที่ประเมินเดียวกันจะถูกเขียนทับด้วยข้อมูลจากไฟล์นี้</p>
                            <ul v-if="preview.summary.replacedBatches.length" class="mt-2 space-y-0.5 text-xs">
                                <li v-for="(b, i) in preview.summary.replacedBatches" :key="i">
                                    • {{ b.rows }} แถว นำเข้าเมื่อ {{ b.createdAt ? formatDateTime(b.createdAt) : '–' }} โดย
                                    {{ b.user ?? 'ไม่ทราบชื่อ' }}
                                </li>
                            </ul>
                        </div>

                        <p class="text-slate-600">เมื่อยืนยันแล้ว ข้อมูลจะเข้าระบบและแสดงผลทันที</p>

                        <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 p-3 hover:bg-slate-50">
                            <input v-model="confirmChecked" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600" />
                            <span class="font-medium text-slate-800">ฉันตรวจสอบข้อมูลแล้วว่าถูกต้อง</span>
                        </label>

                        <p class="text-xs text-slate-400"><i class="fa-solid fa-user-pen mr-1"></i>ระบบจะบันทึกชื่อผู้นำเข้าไว้ในประวัติการนำเข้า</p>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                        <button
                            type="button"
                            class="rounded-xl px-5 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
                            @click="confirmOpen = false"
                        >
                            กลับไปตรวจดู
                        </button>
                        <button
                            type="button"
                            :disabled="!confirmChecked"
                            class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="confirmImport"
                        >
                            ยืนยันนำเข้าข้อมูล
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- ตัวอย่างข้อมูล modal -->
        <div
            v-if="exampleOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @click.self="exampleOpen = false"
        >
            <div class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex items-center justify-between bg-blue-600 px-6 py-4">
                    <h3 class="text-base font-semibold text-white">ตัวอย่างข้อมูล {{ group.label }}</h3>
                    <button
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-blue-100 hover:bg-white/15 hover:text-white"
                        @click="exampleOpen = false"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2 px-6 pt-4 text-xs text-slate-500">
                    <span class="rounded-md bg-amber-50 px-2 py-0.5 font-medium text-amber-700">ข้อมูลสมมติเพื่อการสาธิต</span>
                    <span>หน้าตาเหมือนชีตแรกในแบบฟอร์ม ช่องที่ไม่มีข้อมูลให้เว้นว่างไว้ ไม่ต้องใส่ 0</span>
                </div>

                <div class="m-6 mt-3 overflow-auto rounded-xl border border-slate-200">
                    <table class="min-w-full border-collapse text-xs">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr>
                                <th
                                    v-for="(col, i) in columns"
                                    :key="col"
                                    class="border-b border-r border-slate-200 px-3 py-2 text-left align-top font-normal last:border-r-0"
                                >
                                    <span class="block whitespace-nowrap font-mono font-semibold text-slate-700">{{ col }}</span>
                                    <span v-if="descriptions[i]" class="mt-0.5 block min-w-36 text-[11px] text-slate-400">{{ descriptions[i] }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, r) in exampleRows" :key="r">
                                <td
                                    v-for="(col, i) in columns"
                                    :key="col"
                                    class="whitespace-nowrap border-b border-r border-slate-100 px-3 py-2 align-top text-slate-700 last:border-r-0"
                                    :class="{ 'max-w-xs truncate': col === 'EVENT_DESCRIPTION' }"
                                    :title="col === 'EVENT_DESCRIPTION' ? String(row[i] ?? '') : undefined"
                                >
                                    <template v-if="row[i] !== null">{{ formatCell(col, row[i]!) }}</template>
                                    <span v-else-if="col === 'COMMUNITY_NAME'" class="italic text-slate-400">(เว้นว่าง) ไม่บังคับ</span>
                                    <span v-else class="rounded bg-amber-50 px-1.5 py-0.5 text-amber-700">(เว้นว่าง) = ไม่มีข้อมูล</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
