<script setup lang="ts">
import { defaultWorkGroupTheme, workGroupIcons, workGroupThemes } from '@/data/workGroupIcons';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Only the groups this user may import — filtered server-side via User::canImport() (admin: all 8,
// staff: the ones an admin granted).
defineProps<{
    groups: { value: string; label: string; sheet: string }[];
}>();

const page = usePage<SharedData>();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');

const themeOf = (value: string) => workGroupThemes[value] ?? defaultWorkGroupTheme;
const iconOf = (value: string) => workGroupIcons[value] ?? 'fa-solid fa-file-excel';
</script>

<template>
    <Head title="นำเข้าข้อมูล" />

    <AdminLayout title="นำเข้าข้อมูล">
        <div class="mb-6 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">นำเข้าข้อมูล</h1>
            <p class="mt-1 text-sm text-slate-500">
                เลือกงานที่ต้องการนำเข้าข้อมูล
                <template v-if="isAdmin">· ผู้ดูแลระบบนำเข้าได้ทุกงาน</template>
                <template v-else>· แสดงเฉพาะงานที่ได้รับสิทธิ์จากผู้ดูแลระบบ</template>
            </p>
        </div>

        <div v-if="groups.length" class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <Link
                v-for="group in groups"
                :key="group.value"
                :href="route('data-import.show', group.value)"
                class="group relative flex min-h-[200px] flex-col overflow-hidden rounded-2xl border border-white bg-gradient-to-br from-white via-white p-6 shadow-lg ring-1 ring-slate-100 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
                :class="[themeOf(group.value).card, themeOf(group.value).shadow]"
            >
                <!-- Decorative background: soft color glow + large faded icon, bottom-right -->
                <span
                    class="pointer-events-none absolute -bottom-12 -right-10 h-44 w-44 rounded-full opacity-40 blur-2xl"
                    :class="themeOf(group.value).glow"
                ></span>
                <i
                    class="pointer-events-none absolute -bottom-3 right-4 text-[104px] opacity-60 transition-transform duration-300 group-hover:-rotate-6 group-hover:scale-110"
                    :class="[iconOf(group.value), themeOf(group.value).deco]"
                ></i>

                <span class="relative mb-5 flex h-12 w-12 items-center justify-center rounded-xl shadow-sm" :class="themeOf(group.value).tile">
                    <i :class="[iconOf(group.value), 'text-xl']"></i>
                </span>
                <span class="relative pr-24 text-lg font-bold leading-snug text-slate-800">{{ group.label }}</span>
                <span class="relative mt-auto flex items-center gap-2 pt-5 text-sm font-semibold text-blue-600">
                    นำเข้าข้อมูล
                    <span
                        class="flex h-7 w-7 items-center justify-center rounded-full bg-white shadow-sm transition-transform group-hover:translate-x-1"
                    >
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </span>
                </span>
            </Link>
        </div>

        <div v-else class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center">
            <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-amber-50 text-amber-500">
                <i class="fa-solid fa-lock text-xl"></i>
            </span>
            <h2 class="font-semibold text-slate-800">ยังไม่ได้รับสิทธิ์นำเข้าข้อมูล</h2>
            <p class="mt-1 text-sm text-slate-500">กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดงานที่คุณสามารถนำเข้าข้อมูลได้</p>
        </div>
    </AdminLayout>
</template>
