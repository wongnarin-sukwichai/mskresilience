<script setup lang="ts">
import ImportHistoryTable, { type ImportHistoryRow, type Paginated } from '@/components/admin/ImportHistoryTable.vue';
import TorRef from '@/components/admin/TorRef.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// อนุมัติข้อมูล (จัดการข้อมูล module, admin, ImportApprovalController; TOR 4.1.1(6)-1). Staff imports and web edits
// wait here while ตั้งค่าทั่วไป → "ต้องให้ผู้ดูแลระบบอนุมัติ" is on. Approving checks the data again and saves it;
// rejecting needs a reason that the importer sees. The buttons live in ImportHistoryTable (`reviewable`).
const props = defineProps<{
    batches: Paginated<ImportHistoryRow>;
    recent: ImportHistoryRow[];
    approvalOn: boolean;
    cancelHours: number;
}>();

// The recently reviewed list isn't paginated; the table takes a paginator shape.
const recentPage = computed<Paginated<ImportHistoryRow>>(() => ({
    data: props.recent,
    current_page: 1,
    last_page: 1,
    total: props.recent.length,
    from: props.recent.length ? 1 : null,
    to: props.recent.length || null,
    prev_page_url: null,
    next_page_url: null,
}));
</script>

<template>
    <Head title="อนุมัติข้อมูล" />

    <AdminLayout title="อนุมัติข้อมูล">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">อนุมัติข้อมูล<TorRef :refs="['4.1.1(6)-1']" /></h1>
            <p class="mt-1 text-sm text-slate-500">
                ไฟล์นำเข้าและการแก้ไขข้อมูลในเว็บของเจ้าหน้าที่ที่รอผู้ดูแลระบบอนุมัติ ข้อมูลจะเข้าระบบเมื่ออนุมัติ
            </p>
        </div>

        <div
            class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border px-5 py-4 text-sm"
            :class="approvalOn ? 'border-emerald-100 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-600'"
        >
            <i class="fa-solid" :class="approvalOn ? 'fa-shield-halved text-emerald-600' : 'fa-circle-info text-slate-400'"></i>
            <span class="flex-1">
                <template v-if="approvalOn">โหมดอนุมัติเปิดอยู่ ข้อมูลจากเจ้าหน้าที่ต้องผ่านการอนุมัติที่หน้านี้ก่อนเข้าระบบ</template>
                <template v-else>โหมดอนุมัติปิดอยู่ ข้อมูลจากเจ้าหน้าที่เข้าระบบทันทีหลังยืนยัน (รายการที่ส่งมาก่อนปิดยังรออนุมัติที่นี่)</template>
            </span>
            <Link :href="route('system-settings.index')" class="text-xs font-medium text-blue-600 hover:underline">
                <i class="fa-solid fa-toggle-on mr-1"></i>เปิด/ปิดที่ตั้งค่าทั่วไป
            </Link>
        </div>

        <h2 class="mb-3 font-semibold text-slate-800">
            รออนุมัติ <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700">{{ batches.total }}</span>
        </h2>
        <ImportHistoryTable reviewable :batches="batches" :cancel-hours="cancelHours" empty-text="ไม่มีรายการรออนุมัติ" />

        <h2 class="mb-3 mt-8 font-semibold text-slate-800">ตรวจแล้วล่าสุด</h2>
        <ImportHistoryTable :batches="recentPage" :cancel-hours="cancelHours" empty-text="ยังไม่มีรายการที่ตรวจแล้ว" />
    </AdminLayout>
</template>
