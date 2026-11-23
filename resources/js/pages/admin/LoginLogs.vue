<script setup lang="ts">
import Pager from '@/components/admin/Pager.vue';
import TorRef from '@/components/admin/TorRef.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive } from 'vue';

// ประวัติการเข้าใช้งาน (ตั้งค่าระบบ module, admin, LoginLogController). Top: who is online now, refreshed every
// 15 s; this is the screen for the "20 users log in at once" acceptance test (คุณลักษณะ 2.1-3). Below: every
// login, logout and failed login.
interface Online {
    id: number;
    name: string;
    role: string | null;
    sessions: number;
    ip: string | null;
    device: string | null;
    lastActivity: string;
}
interface Log {
    id: number;
    at: string;
    user: string | null;
    email: string | null;
    event: 'login' | 'logout' | 'failed';
    eventLabel: string;
    ip: string | null;
    device: string | null;
}

const props = defineProps<{
    logs: {
        data: Log[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { q: string; event: string; from: string; to: string };
    events: { value: string; label: string }[];
    online: Online[];
    onlineMinutes: number;
    sessionsTracked: boolean;
}>();

const form = reactive({ ...props.filters });
const hasFilters = computed(() => Object.values(props.filters).some((v) => v !== ''));

function search() {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get(route('login-logs.index'), query, { preserveState: true, preserveScroll: true, replace: true });
}
function clearFilters() {
    Object.assign(form, { q: '', event: '', from: '', to: '' });
    search();
}

// Live refresh of the online list (and the newest log rows) while the page is open.
let timer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    timer = setInterval(() => router.reload({ only: ['online', 'logs'] }), 15000);
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const eventClass: Record<string, string> = {
    login: 'bg-emerald-50 text-emerald-700',
    logout: 'bg-slate-100 text-slate-600',
    failed: 'bg-red-50 text-red-700',
};

function formatDateTime(iso: string) {
    return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'medium' });
}
function ago(iso: string) {
    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    if (seconds < 60) return `${seconds} วินาทีที่แล้ว`;
    return `${Math.floor(seconds / 60)} นาทีที่แล้ว`;
}

const inputClass =
    'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="ประวัติการเข้าใช้งาน" />

    <AdminLayout title="ประวัติการเข้าใช้งาน">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">
                ประวัติการเข้าใช้งาน
                <TorRef :refs="['4.1.1(6)', 'คุณลักษณะ 3-4', '11.4']" />
            </h1>
            <p class="mt-1 text-sm text-slate-500">การเข้าสู่ระบบ ออกจากระบบ และการเข้าสู่ระบบไม่สำเร็จทุกครั้ง พร้อมผู้ใช้ที่ออนไลน์ขณะนี้</p>
        </div>

        <!-- Online now -->
        <div class="mb-5 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <i class="fa-solid fa-signal text-xl"></i>
                </span>
                <div>
                    <p class="text-xs text-slate-500">
                        ผู้ใช้ที่ออนไลน์ขณะนี้
                        <TorRef :refs="['4.4-3', 'คุณลักษณะ 2.1-3']" />
                    </p>
                    <p class="text-3xl font-bold text-slate-800">{{ online.length }} <span class="text-sm font-normal text-slate-500">คน</span></p>
                </div>
                <p class="ml-auto text-xs text-slate-400">
                    <span class="mr-1 inline-block h-2 w-2 animate-pulse rounded-full bg-emerald-500 align-middle"></span>
                    ใช้งานภายใน {{ onlineMinutes }} นาทีล่าสุด · อัปเดตทุก 15 วินาที
                </p>
            </div>
            <p v-if="!sessionsTracked" class="rounded-xl bg-amber-50 px-4 py-3 text-xs text-amber-700">
                ระบบไม่ได้เก็บ session ในฐานข้อมูล (SESSION_DRIVER ไม่ใช่ database) จึงแสดงผู้ใช้ที่ออนไลน์ไม่ได้
            </p>
            <div v-else-if="online.length" class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="o in online" :key="o.id" class="flex items-center gap-3 rounded-xl border border-slate-100 px-3 py-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-600">
                        {{ o.name.charAt(0) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-700">
                            {{ o.name }}
                            <span v-if="o.sessions > 1" class="text-xs font-normal text-slate-400">({{ o.sessions }} อุปกรณ์)</span>
                        </p>
                        <p class="truncate text-[11px] text-slate-400">{{ o.role ?? '–' }} · {{ o.device ?? '–' }} · {{ ago(o.lastActivity) }}</p>
                    </div>
                </div>
            </div>
            <p v-else class="text-sm text-slate-400">ไม่มีผู้ใช้ออนไลน์</p>
        </div>

        <form class="mb-5 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm" @submit.prevent="search">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">ชื่อหรืออีเมล</span>
                    <input v-model="form.q" type="search" placeholder="พิมพ์ชื่อหรืออีเมล" :class="inputClass" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-medium text-slate-600">เหตุการณ์</span>
                    <select v-model="form.event" :class="inputClass" @change="search">
                        <option value="">ทั้งหมด</option>
                        <option v-for="e in events" :key="e.value" :value="e.value">{{ e.label }}</option>
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
                <span class="text-xs text-slate-500">พบ {{ logs.total }} รายการ</span>
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
            <div v-if="logs.data.length" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs text-slate-500">
                            <th class="py-2 pr-4 font-medium">วันเวลา</th>
                            <th class="py-2 pr-4 font-medium">ผู้ใช้</th>
                            <th class="py-2 pr-4 font-medium">อีเมล</th>
                            <th class="py-2 pr-4 font-medium">เหตุการณ์</th>
                            <th class="py-2 pr-4 font-medium">IP</th>
                            <th class="py-2 font-medium">อุปกรณ์</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in logs.data" :key="l.id" class="border-b border-slate-50 text-slate-700 last:border-0">
                            <td class="whitespace-nowrap py-2 pr-4 text-xs text-slate-500">{{ formatDateTime(l.at) }}</td>
                            <td class="whitespace-nowrap py-2 pr-4">{{ l.user ?? '–' }}</td>
                            <td class="py-2 pr-4 text-xs text-slate-500">{{ l.email ?? '–' }}</td>
                            <td class="whitespace-nowrap py-2 pr-4">
                                <span class="rounded-md px-2 py-0.5 text-xs font-medium" :class="eventClass[l.event]">{{ l.eventLabel }}</span>
                            </td>
                            <td class="whitespace-nowrap py-2 pr-4 text-xs text-slate-500">{{ l.ip ?? '–' }}</td>
                            <td class="whitespace-nowrap py-2 text-xs text-slate-500">{{ l.device ?? '–' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="py-10 text-center text-sm text-slate-500">
                <i class="fa-solid fa-right-to-bracket mb-2 block text-xl text-slate-300"></i>
                {{ hasFilters ? 'ไม่พบรายการที่ตรงกับเงื่อนไข' : 'ยังไม่มีประวัติการเข้าใช้งาน' }}
            </div>

            <Pager :page="logs" />
        </div>
    </AdminLayout>
</template>
