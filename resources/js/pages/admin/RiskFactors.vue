<script setup lang="ts">
import PagePlaceholder from '@/components/admin/PagePlaceholder.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="ปัจจัยความเสี่ยง" />

    <AdminLayout title="ปัจจัยความเสี่ยง">
        <PagePlaceholder title="ปัจจัยความเสี่ยง" description="ปัจจัยความเสี่ยงและความพร้อม (รายภัย)" icon="fa-solid fa-triangle-exclamation" />
    </AdminLayout>
</template>
