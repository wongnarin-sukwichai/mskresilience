<script setup lang="ts">
import { workGroupIcons } from '@/data/workGroupIcons';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

// Upload shell for one กลุ่มงาน — the server only renders this page if User::canImport(group). The file is
// picked client-side only for now: nothing is uploaded, parsed or stored yet (parsing/validation/staging and
// the review workflow are the next steps, built sheet by sheet).
defineProps<{
    group: { value: string; label: string; sheet: string };
}>();

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
    </AdminLayout>
</template>
