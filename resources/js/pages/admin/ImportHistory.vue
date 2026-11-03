<script setup lang="ts">
import ImportHistoryTable, { type ImportHistoryRow, type Paginated } from '@/components/admin/ImportHistoryTable.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// ประวัตินำเข้า (จัดการข้อมูล module, ImportHistoryController): the imports of the groups this user may import,
// newest first. The searchable all-groups view for admin is จัดการประวัตินำเข้า (ImportBatches.vue).
defineProps<{
    batches: Paginated<ImportHistoryRow>;
    cancelHours: number;
}>();

const page = usePage<SharedData>();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');
</script>

<template>
    <Head title="ประวัตินำเข้า" />

    <AdminLayout title="ประวัตินำเข้า">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">ประวัตินำเข้า</h1>
            <p class="mt-1 text-sm text-slate-500">
                รายการนำเข้าข้อมูลที่ยืนยันแล้ว
                <template v-if="isAdmin">· ทุกงาน</template>
                <template v-else>· เฉพาะงานที่คุณได้รับสิทธิ์นำเข้า</template>
            </p>
        </div>

        <ImportHistoryTable :batches="batches" :cancel-hours="cancelHours" empty-text="ยังไม่มีการนำเข้าข้อมูล" />
    </AdminLayout>
</template>
