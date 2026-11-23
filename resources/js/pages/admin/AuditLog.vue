<script setup lang="ts">
import Pager from '@/components/admin/Pager.vue';
import TorRef from '@/components/admin/TorRef.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

// บันทึกการแก้ไข (ตั้งค่าระบบ module, admin, AuditLogController): every change to users, communities, criteria,
// settings, and every import / cancel / file deletion, with the values before and after. Rows come from
// App\Support\AuditPresenter. Filters are server-side (query string), like จัดการประวัตินำเข้า.
interface Change {
    field: string;
    old: string | null;
    new: string | null;
}
interface Row {
    id: number;
    at: string;
    user: string;
    log: string;
    logLabel: string;
    event: string;
    eventLabel: string;
    subject: string;
    description: string;
    changes: Change[];
    details: Record<string, string | number | null> | null;
}

const props = defineProps<{
    activities: {
        data: Row[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { user: string; log: string; from: string; to: string; q: string };
    users: { id: number; name: string }[];
    logs: { value: string; label: string }[];
}>();

const form = reactive({ ...props.filters });
const hasFilters = computed(() => Object.values(props.filters).some((v) => v !== ''));
const open = ref<number | null>(null);

function search() {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get(route('audit-log.index'), query, { preserveState: true, preserveScroll: true, replace: true });
}
function clearFilters() {
    Object.assign(form, { user: '', log: '', from: '', to: '', q: '' });
    search();
}

const eventClass: Record<string, string> = {
    created: 'bg-emerald-50 text-emerald-700',
    imported: 'bg-emerald-50 text-emerald-700',
    updated: 'bg-blue-50 text-blue-700',
    password: 'bg-amber-50 text-amber-700',
    deleted: 'bg-red-50 text-red-700',
    cancelled: 'bg-red-50 text-red-700',
    file_deleted: 'bg-red-50 text-red-700',
    web_add: 'bg-emerald-50 text-emerald-700',
    web_edit: 'bg-blue-50 text-blue-700',
    web_delete: 'bg-red-50 text-red-700',
    submitted: 'bg-amber-50 text-amber-700',
    approved: 'bg-emerald-50 text-emerald-700',
    rejected: 'bg-red-50 text-red-700',
    withdrawn: 'bg-slate-100 text-slate-600',
    backup_created: 'bg-emerald-50 text-emerald-700',
    backup_failed: 'bg-red-50 text-red-700',
    backup_downloaded: 'bg-amber-50 text-amber-700',
};

const detailLabels: Record<string, string> = {
    work_group: 'งาน',
    file: 'ไฟล์',
    rows: 'จำนวนแถว',
    replaced: 'แทนที่ข้อมูลเดิม',
    communities: 'ชุมชน',
    bytes: 'ขนาดไฟล์ (ไบต์)',
    row: 'แถว',
    status: 'สถานะ',
    note: 'เหตุผล',
    type: 'ประเภท',
    trigger: 'วิธีสำรอง',
    message: 'ผล',
};

function formatDateTime(iso: string) {
    const d = new Date(iso);
    return d.toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'medium' });
}

const inputClass =
    'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="บันทึกการแก้ไข" />

    <AdminLayout title="บันทึกการแก้ไข">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">
                บันทึกการแก้ไข
                <TorRef :refs="['4.1.1(2)-4', '4.1.1(6)', 'คุณลักษณะ 2.1-6']" />
            </h1>
            <p class="mt-1 text-sm text-slate-500">ใครแก้ไขข้อมูลอะไร เมื่อใด พร้อมค่าก่อนและหลังการแก้ไข (Audit Trail) ไม่มีการบันทึกรหัสผ่าน</p>
        </div>

        <form class="mb-5 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm" @submit.prevent="search">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">ค้นหาข้อความ</span>
                    <input v-model="form.q" type="search" placeholder="เช่น ชื่อชุมชน, ชื่อไฟล์" :class="inputClass" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">ผู้แก้ไข</span>
                    <select v-model="form.user" :class="inputClass" @change="search">
                        <option value="">ทุกคน</option>
                        <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">ประเภท</span>
                    <select v-model="form.log" :class="inputClass" @change="search">
                        <option value="">ทุกประเภท</option>
                        <option v-for="l in logs" :key="l.value" :value="l.value">{{ l.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">ตั้งแต่วันที่</span>
                    <input v-model="form.from" type="date" :max="form.to || undefined" :class="inputClass" @change="search" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">ถึงวันที่</span>
                    <input v-model="form.to" type="date" :min="form.from || undefined" :class="inputClass" @change="search" />
                </label>
            </div>
            <div class="mt-4 flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">พบ {{ activities.total }} รายการ</span>
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

        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div v-if="activities.data.length" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs text-slate-500">
                            <th class="py-2 pr-4 font-medium">วันเวลา</th>
                            <th class="py-2 pr-4 font-medium">ผู้แก้ไข</th>
                            <th class="py-2 pr-4 font-medium">ประเภท</th>
                            <th class="py-2 pr-4 font-medium">การกระทำ</th>
                            <th class="py-2 pr-4 font-medium">รายละเอียด</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="a in activities.data" :key="a.id">
                            <tr
                                class="cursor-pointer border-b border-slate-50 text-slate-700 hover:bg-slate-50"
                                :class="{ 'bg-blue-50/40': open === a.id }"
                                @click="open = open === a.id ? null : a.id"
                            >
                                <td class="whitespace-nowrap py-2.5 pr-4 text-xs text-slate-500">{{ formatDateTime(a.at) }}</td>
                                <td class="whitespace-nowrap py-2.5 pr-4">{{ a.user }}</td>
                                <td class="whitespace-nowrap py-2.5 pr-4 text-xs text-slate-500">{{ a.logLabel }}</td>
                                <td class="whitespace-nowrap py-2.5 pr-4">
                                    <span
                                        class="rounded-md px-2 py-0.5 text-xs font-medium"
                                        :class="eventClass[a.event] ?? 'bg-slate-100 text-slate-600'"
                                    >
                                        {{ a.eventLabel }}
                                    </span>
                                </td>
                                <td class="py-2.5 pr-4">
                                    {{ a.description }}
                                    <span v-if="a.changes.length" class="ml-1 text-xs text-slate-400">({{ a.changes.length }} รายการ)</span>
                                </td>
                                <td class="py-2.5 text-right text-slate-400">
                                    <i class="fa-solid fa-chevron-down text-xs transition-transform" :class="{ 'rotate-180': open === a.id }"></i>
                                </td>
                            </tr>
                            <tr v-if="open === a.id" class="border-b border-slate-100 bg-slate-50/60">
                                <td colspan="6" class="px-4 py-4">
                                    <table v-if="a.changes.length" class="w-full max-w-4xl text-xs">
                                        <thead>
                                            <tr class="text-left text-slate-500">
                                                <th class="w-1/3 pb-1.5 pr-4 font-medium">ข้อมูล</th>
                                                <th class="w-1/3 pb-1.5 pr-4 font-medium">ค่าเดิม</th>
                                                <th class="w-1/3 pb-1.5 font-medium">ค่าใหม่</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(c, i) in a.changes" :key="i" class="border-t border-slate-200/70 align-top">
                                                <td class="py-1.5 pr-4 font-medium text-slate-600">{{ c.field }}</td>
                                                <td class="break-all py-1.5 pr-4 text-red-700">
                                                    <span v-if="c.old !== null" class="rounded bg-red-50 px-1">{{ c.old }}</span>
                                                    <span v-else class="text-slate-400">–</span>
                                                </td>
                                                <td class="break-all py-1.5 text-emerald-700">
                                                    <span v-if="c.new !== null" class="rounded bg-emerald-50 px-1">{{ c.new }}</span>
                                                    <span v-else class="text-slate-400">–</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <dl v-if="a.details" class="grid max-w-2xl grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-xs">
                                        <template v-for="(v, k) in a.details" :key="k">
                                            <dt class="text-slate-500">{{ detailLabels[k] ?? k }}</dt>
                                            <dd class="break-all text-slate-700">{{ v ?? '–' }}</dd>
                                        </template>
                                    </dl>
                                    <p v-if="!a.changes.length && !a.details" class="text-xs text-slate-400">ไม่มีรายละเอียดเพิ่มเติม</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div v-else class="py-10 text-center text-sm text-slate-500">
                <i class="fa-solid fa-clipboard-list mb-2 block text-xl text-slate-300"></i>
                {{ hasFilters ? 'ไม่พบรายการที่ตรงกับเงื่อนไข' : 'ยังไม่มีบันทึกการแก้ไข' }}
            </div>

            <Pager :page="activities" />
        </div>
    </AdminLayout>
</template>
