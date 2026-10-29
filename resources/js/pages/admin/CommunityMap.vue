<script setup lang="ts">
import PagePlaceholder from '@/components/admin/PagePlaceholder.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="แผนที่รายชุมชน" />

    <AdminLayout title="แผนที่รายชุมชน">
        <PagePlaceholder title="แผนที่รายชุมชน" description="แผนที่ความเสี่ยงรายชุมชน" icon="fa-solid fa-map" />
    </AdminLayout>
</template>
