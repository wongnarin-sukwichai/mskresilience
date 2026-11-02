<script setup lang="ts">
import { workGroupIcons } from '@/data/workGroupIcons';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

// Import page for one กลุ่มงาน — the server only renders this page if User::canImport(group). The template
// download is real (DataImportController::template). The file is still picked client-side only: nothing is
// uploaded, parsed or stored yet (validation and saving are the next steps, built sheet by sheet).
defineProps<{
    group: { value: string; label: string; sheet: string };
    columns: string[];
    descriptions: (string | null)[];
    exampleRows: (string | number | null)[][];
}>();

// "ตัวอย่างข้อมูล" viewer: web-only demo rows (App\Import\ImportExamples), never part of the template.
const DATE_COLUMNS = ['ASSESSMENT_DATE', 'EVENT_START', 'EVENT_END'];
const exampleOpen = ref(false);

function formatExampleCell(column: string, value: string | number) {
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

const steps = [
    { title: 'ดาวน์โหลดแบบฟอร์ม', detail: 'ไฟล์ Excel ที่มีหัวคอลัมน์ตรงตามที่ระบบต้องการ', ready: true },
    {
        title: 'กรอกข้อมูลในชีตแรก',
        detail: 'เลือกรหัสชุมชน (COMMUNITY_ID) จากรายการ ดูชื่อชุมชนได้ในชีต "รายชื่อชุมชน" ชี้เมาส์ที่หัวคอลัมน์เพื่อดูคำอธิบาย ช่องที่ไม่มีข้อมูลให้เว้นว่างไว้ (ไม่ต้องใส่ 0)',
        ready: true,
    },
    { title: 'อัปโหลดไฟล์', detail: 'ระบบตรวจชื่อคอลัมน์ รหัสชุมชน และค่าในแต่ละช่อง', ready: false },
    { title: 'ตรวจผลและยืนยัน', detail: 'ดูข้อผิดพลาด/คำเตือน และชุมชนที่มีหรือขาด แล้วกดยืนยันเพื่อนำข้อมูลเข้าระบบ', ready: false },
];

const ACCEPTED_EXTENSIONS = ['.xlsx', '.xls'];

const selectedFile = ref<File | null>(null);
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
    selectedFile.value = file;
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
    selectedFile.value = null;
    fileError.value = '';
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

        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold text-slate-800">อัปโหลดไฟล์</h2>

            <div
                v-if="!selectedFile"
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
            <p v-if="fileError" class="mt-2 text-xs text-red-600">{{ fileError }}</p>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                <p class="text-xs text-slate-400">
                    <i class="fa-solid fa-person-digging mr-1"></i>ขั้นตอนตรวจสอบไฟล์และนำเข้าข้อมูลอยู่ระหว่างการพัฒนา
                </p>
                <button
                    type="button"
                    disabled
                    class="cursor-not-allowed rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white opacity-50"
                    title="อยู่ระหว่างการพัฒนา"
                >
                    ตรวจสอบและนำเข้า
                </button>
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
                                    <template v-if="row[i] !== null">{{ formatExampleCell(col, row[i]!) }}</template>
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
