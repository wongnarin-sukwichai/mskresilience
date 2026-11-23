<script setup lang="ts">
import Pager from '@/components/admin/Pager.vue';
import TorRef from '@/components/admin/TorRef.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

// สำรองข้อมูล (ตั้งค่าระบบ module, admin, BackupController; TOR 4.4-5, คุณลักษณะ 2.1-5 "แสดง Log Backup").
// Two buttons (database / kept import files, separate so neither hits the host's time limit), the backup log, and
// downloads for keeping a copy off the server. Cron runs the same backups when the host has it.
interface Row {
    id: number;
    type: 'database' | 'files';
    typeLabel: string;
    trigger: 'manual' | 'scheduled';
    status: 'running' | 'success' | 'failed';
    sizeBytes: number | null;
    message: string | null;
    user: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    seconds: number | null;
    downloadable: boolean;
    fileDeleted: boolean;
}

defineProps<{
    backups: {
        data: Row[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    last: { database: string | null; files: string | null };
    daysSinceDatabase: number | null;
    overdueDays: number;
    keep: { database: number; files: number };
}>();

const running = ref<'database' | 'files' | null>(null);
function run(type: 'database' | 'files') {
    router.post(
        route('backups.store'),
        { type },
        { preserveScroll: true, onStart: () => (running.value = type), onFinish: () => (running.value = null) },
    );
}

function formatDateTime(iso: string | null) {
    return iso ? new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : '–';
}
function formatBytes(bytes: number | null) {
    if (bytes === null) return '–';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}
</script>

<template>
    <Head title="สำรองข้อมูล" />

    <AdminLayout title="สำรองข้อมูล">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">สำรองข้อมูล<TorRef :refs="['4.4-5', 'คุณลักษณะ 2.1-5']" /></h1>
            <p class="mt-1 text-sm text-slate-500">
                สำรองฐานข้อมูลและไฟล์นำเข้าต้นฉบับ อย่างน้อยเดือนละครั้ง แนะนำให้ดาวน์โหลดไฟล์สำรองเก็บไว้นอกเซิร์ฟเวอร์ด้วย
            </p>
        </div>

        <div
            v-if="daysSinceDatabase === null || daysSinceDatabase >= overdueDays"
            class="mb-5 flex items-center gap-3 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800"
        >
            <i class="fa-solid fa-triangle-exclamation text-red-500"></i>
            <span
                >{{ daysSinceDatabase === null ? 'ยังไม่เคยสำรองฐานข้อมูล' : `ไม่ได้สำรองฐานข้อมูลมา ${daysSinceDatabase} วัน` }} กรุณากด
                "สำรองฐานข้อมูล"</span
            >
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div
                v-for="t in ['database', 'files'] as const"
                :key="t"
                class="flex flex-col gap-3 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"
            >
                <div class="flex items-center gap-4">
                    <span
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl"
                        :class="t === 'database' ? 'bg-blue-50 text-blue-600' : 'bg-emerald-50 text-emerald-600'"
                    >
                        <i class="fa-solid text-xl" :class="t === 'database' ? 'fa-database' : 'fa-file-zipper'"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-800">{{ t === 'database' ? 'ฐานข้อมูล' : 'ไฟล์นำเข้าต้นฉบับ' }}</p>
                        <p class="text-xs text-slate-500">ล่าสุด {{ formatDateTime(last[t]) }} · เก็บย้อนหลัง {{ keep[t] }} ชุด</p>
                    </div>
                </div>
                <p class="text-xs text-slate-400">
                    {{
                        t === 'database'
                            ? 'ข้อมูลทุกตารางเป็นไฟล์ .sql.gz กู้คืนได้ด้วย phpMyAdmin (นำเข้า) หรือคำสั่ง mysql'
                            : 'ไฟล์ Excel/CSV ที่นำเข้าทั้งหมด (storage/app/private/imports) เป็นไฟล์ .zip'
                    }}
                </p>
                <button
                    type="button"
                    :disabled="running !== null"
                    class="mt-auto inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    :class="t === 'database' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-emerald-600 hover:bg-emerald-700'"
                    @click="run(t)"
                >
                    <i class="fa-solid" :class="running === t ? 'fa-spinner fa-spin' : 'fa-floppy-disk'"></i>
                    {{ running === t ? 'กำลังสำรอง...' : t === 'database' ? 'สำรองฐานข้อมูล' : 'สำรองไฟล์นำเข้า' }}
                </button>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-semibold text-slate-800">ประวัติการสำรองข้อมูล (Log Backup)</h2>
            <div v-if="backups.data.length" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs text-slate-500">
                            <th class="py-2 pr-4 font-medium">#</th>
                            <th class="py-2 pr-4 font-medium">วันเวลา</th>
                            <th class="py-2 pr-4 font-medium">ประเภท</th>
                            <th class="py-2 pr-4 font-medium">วิธี</th>
                            <th class="py-2 pr-4 font-medium">สถานะ</th>
                            <th class="py-2 pr-4 font-medium">รายละเอียด</th>
                            <th class="py-2 pr-4 text-right font-medium">ขนาด</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in backups.data" :key="b.id" class="border-b border-slate-50 text-slate-700 last:border-0">
                            <td class="py-2 pr-4 text-xs text-slate-400">#{{ b.id }}</td>
                            <td class="whitespace-nowrap py-2 pr-4">
                                {{ formatDateTime(b.startedAt) }}
                                <span v-if="b.seconds !== null" class="text-xs text-slate-400">({{ b.seconds }} วินาที)</span>
                            </td>
                            <td class="whitespace-nowrap py-2 pr-4">{{ b.typeLabel }}</td>
                            <td class="whitespace-nowrap py-2 pr-4 text-xs">
                                <template v-if="b.trigger === 'manual'"
                                    ><i class="fa-solid fa-hand-pointer mr-1 text-slate-400"></i>กดเอง · {{ b.user ?? '–' }}</template
                                >
                                <template v-else><i class="fa-solid fa-clock mr-1 text-slate-400"></i>อัตโนมัติ</template>
                            </td>
                            <td class="whitespace-nowrap py-2 pr-4">
                                <span v-if="b.status === 'success'" class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"
                                    >สำเร็จ</span
                                >
                                <span v-else-if="b.status === 'failed'" class="rounded-md bg-red-50 px-2 py-0.5 text-xs text-red-700">ไม่สำเร็จ</span>
                                <span v-else class="rounded-md bg-amber-50 px-2 py-0.5 text-xs text-amber-700">กำลังทำงาน</span>
                            </td>
                            <td class="max-w-xs truncate py-2 pr-4 text-xs text-slate-500" :title="b.message ?? ''">{{ b.message ?? '–' }}</td>
                            <td class="whitespace-nowrap py-2 pr-4 text-right text-xs">
                                <span :class="{ 'text-slate-400 line-through': b.fileDeleted }">{{ formatBytes(b.sizeBytes) }}</span>
                            </td>
                            <td class="whitespace-nowrap py-2 text-right">
                                <a
                                    v-if="b.downloadable"
                                    :href="route('backups.download', b.id)"
                                    class="rounded-lg border border-blue-200 px-2.5 py-1 text-xs text-blue-600 hover:bg-blue-50"
                                >
                                    <i class="fa-solid fa-download mr-1"></i>ดาวน์โหลด
                                </a>
                                <span v-else-if="b.fileDeleted" class="text-[11px] text-slate-400" title="ลบตามรอบเก็บย้อนหลัง"
                                    >ลบตามรอบเก็บแล้ว</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="py-10 text-center text-sm text-slate-500">
                <i class="fa-solid fa-box-archive mb-2 block text-xl text-slate-300"></i>
                ยังไม่มีการสำรองข้อมูล
            </div>
            <Pager :page="backups" />
            <p class="mt-3 text-xs text-slate-400">
                ถ้าเซิร์ฟเวอร์ตั้ง cron ไว้ ระบบจะสำรองฐานข้อมูลทุกวันเวลา 02:00 น. และไฟล์นำเข้าทุกวันอาทิตย์ 03:00 น. โดยอัตโนมัติ ·
                ไฟล์สำรองมีข้อมูลทั้งระบบ การดาวน์โหลดทุกครั้งถูกบันทึกในบันทึกการแก้ไข
            </p>
        </div>
    </AdminLayout>
</template>
