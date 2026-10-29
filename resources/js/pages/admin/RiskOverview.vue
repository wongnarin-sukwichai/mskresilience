<script setup lang="ts">
import PagePlaceholder from '@/components/admin/PagePlaceholder.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="ภาพรวมความเสี่ยง" />

    <AdminLayout title="ภาพรวมความเสี่ยง">
        <PagePlaceholder title="ภาพรวมความเสี่ยง" description="ภาพรวมความเสี่ยงของเมือง" icon="fa-solid fa-chart-pie" />
    </AdminLayout>
</template>
