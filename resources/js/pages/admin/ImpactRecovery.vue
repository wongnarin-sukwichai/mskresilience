<script setup lang="ts">
import PagePlaceholder from '@/components/admin/PagePlaceholder.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="ผลกระทบและการฟื้นตัว" />

    <AdminLayout title="ผลกระทบและการฟื้นตัว">
        <PagePlaceholder title="ผลกระทบและการฟื้นตัว" description="ผลกระทบและการฟื้นตัว" icon="fa-solid fa-rotate" />
    </AdminLayout>
</template>
