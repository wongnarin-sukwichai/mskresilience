<script setup lang="ts">
import PagePlaceholder from '@/components/admin/PagePlaceholder.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="เกี่ยวกับระบบ" />

    <AdminLayout title="เกี่ยวกับระบบ">
        <PagePlaceholder title="เกี่ยวกับระบบ" description="เกี่ยวกับระบบและการอ่านข้อมูล" icon="fa-solid fa-house" />
    </AdminLayout>
</template>
