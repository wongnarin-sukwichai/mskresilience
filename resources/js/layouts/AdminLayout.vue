<script setup lang="ts">
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

withDefaults(
    defineProps<{
        title?: string;
    }>(),
    { title: 'แดชบอร์ดผู้ดูแลระบบ' },
);

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);

const roleLabels: Record<string, string> = {
    admin: 'ผู้ดูแลระบบ',
    staff: 'เจ้าหน้าที่',
    member: 'บุคคลทั่วไป',
};

const navItems = computed(() => [
    { label: 'แดชบอร์ด', icon: 'fa-solid fa-gauge', href: route('dashboard') },
    // Data import, hazard analysis, and the L&D register are staff-level duties — admin/staff only,
    // hidden from member.
    ...(user.value.role === 'admin' || user.value.role === 'staff'
        ? [
              { label: 'นำเข้าข้อมูล', icon: 'fa-solid fa-cloud-arrow-up', href: route('data-import.index') },
              { label: 'ภัยพิบัติรวม', icon: 'fa-solid fa-cloud-bolt', href: route('disaster-overview.index') },
              { label: 'รายภัย', icon: 'fa-solid fa-calculator', href: route('hazard-analysis.index') },
              { label: 'เหตุการณ์ L&D', icon: 'fa-solid fa-triangle-exclamation', href: route('events-ld.index') },
          ]
        : []),
    // ตั้งค่า: for admin, a real expandable submenu holding account/role management, scoring-criteria
    // weights, and community management — all admin-only (routes/admin.php), moved here from the top
    // level per product decision. For staff/member it stays the inert roadmap placeholder it always was.
    user.value.role === 'admin'
        ? {
              label: 'ตั้งค่า',
              icon: 'fa-solid fa-gear',
              children: [
                  { label: 'จัดการสมาชิก', icon: 'fa-solid fa-user-gear', href: route('users.index') },
                  { label: 'จัดการเกณฑ์คะแนน', icon: 'fa-solid fa-sliders', href: route('scoring-criteria.index') },
                  { label: 'จัดการชุมชน', icon: 'fa-solid fa-users', href: route('communities.index') },
              ],
          }
        : { label: 'ตั้งค่า', icon: 'fa-solid fa-gear' },
]);

const isActive = (href?: string) => !!href && page.url === new URL(href, window.location.origin).pathname;

// Auto-expand the ตั้งค่า submenu when the current page is one of its children, so a direct navigation
// (or reload) to e.g. /communities doesn't leave the active link hidden inside a collapsed menu.
const settingsOpen = ref(
    user.value.role === 'admin' && [route('users.index'), route('scoring-criteria.index'), route('communities.index')].some((href) => isActive(href)),
);

const currentDateText = ref('');
function updateDateText() {
    const date = new Date();
    const thaiMonths = [
        'มกราคม',
        'กุมภาพันธ์',
        'มีนาคม',
        'เมษายน',
        'พฤษภาคม',
        'มิถุนายน',
        'กรกฎาคม',
        'สิงหาคม',
        'กันยายน',
        'ตุลาคม',
        'พฤศจิกายน',
        'ธันวาคม',
    ];
    currentDateText.value = `${date.getDate()} ${thaiMonths[date.getMonth()]} ${date.getFullYear() + 543}`;
}

const mobileSidebarOpen = ref(false);
const userMenuOpen = ref(false);
const userMenuRef = ref<HTMLElement | null>(null);

function handleClickOutside(event: MouseEvent) {
    if (userMenuOpen.value && userMenuRef.value && !userMenuRef.value.contains(event.target as Node)) {
        userMenuOpen.value = false;
    }
}

let dateTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    updateDateText();
    dateTimer = setInterval(updateDateText, 60000);
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    if (dateTimer) clearInterval(dateTimer);
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-slate-50 font-sans text-slate-800">
        <!-- Mobile overlay -->
        <div v-if="mobileSidebarOpen" class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden" @click="mobileSidebarOpen = false"></div>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-64 shrink-0 flex-col bg-slate-900 text-slate-300 transition-transform duration-200 lg:static lg:translate-x-0"
            :class="mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-[72px] items-center gap-3 border-b border-white/10 px-5">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white shadow-inner">
                    <i class="fa-solid fa-shield-halved text-lg"></i>
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold leading-tight text-white">ระบบบริหารข้อมูลความเสี่ยง</p>
                    <p class="truncate text-xs text-slate-400">อ.เมือง จ.มหาสารคาม</p>
                </div>
            </div>

            <nav class="custom-scrollbar flex-1 space-y-1 overflow-y-auto px-3 py-4">
                <template v-for="item in navItems" :key="item.label">
                    <template v-if="item.children">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors"
                            :class="settingsOpen ? 'bg-white/5 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                            @click="settingsOpen = !settingsOpen"
                        >
                            <i :class="[item.icon, 'w-4 text-center']"></i>
                            <span class="flex-1 text-left">{{ item.label }}</span>
                            <i
                                class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform"
                                :class="settingsOpen ? 'rotate-180' : ''"
                            ></i>
                        </button>
                        <div v-if="settingsOpen" class="ml-4 space-y-1 border-l border-white/10 py-1 pl-3">
                            <Link
                                v-for="child in item.children"
                                :key="child.label"
                                :href="child.href"
                                class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors"
                                :class="
                                    isActive(child.href) ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-300 hover:bg-white/5 hover:text-white'
                                "
                            >
                                <i :class="[child.icon, 'w-4 text-center']"></i>
                                {{ child.label }}
                            </Link>
                        </div>
                    </template>
                    <component
                        :is="item.href ? Link : 'div'"
                        v-else
                        :href="item.href"
                        class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors"
                        :class="
                            isActive(item.href)
                                ? 'bg-blue-600 text-white shadow-sm'
                                : item.href
                                  ? 'text-slate-300 hover:bg-white/5 hover:text-white'
                                  : 'cursor-default text-slate-500'
                        "
                        :title="item.href ? undefined : 'อยู่ระหว่างการพัฒนา'"
                    >
                        <i :class="[item.icon, 'w-4 text-center']"></i>
                        {{ item.label }}
                    </component>
                </template>
            </nav>

            <div class="border-t border-white/10 px-4 py-4 text-xs text-slate-400">
                <div class="mb-1.5 flex items-center justify-between">
                    <span>สถานะระบบ</span>
                    <span class="flex items-center gap-1.5 font-medium text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                        ปกติ
                    </span>
                </div>
                <div class="mb-1.5">
                    <span>อัปเดตข้อมูลล่าสุด</span>
                    <p class="text-slate-300">{{ currentDateText }}</p>
                </div>
                <div class="flex items-center justify-between">
                    <span>เวอร์ชันระบบ</span>
                    <span class="text-slate-300">v2.1.0</span>
                </div>
            </div>
        </aside>

        <!-- Main -->
        <div class="flex min-w-0 flex-1 flex-col">
            <!-- Header -->
            <header class="flex h-[72px] shrink-0 items-center gap-4 border-b border-slate-200 bg-white px-4 lg:px-6">
                <button
                    type="button"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 lg:hidden"
                    @click="mobileSidebarOpen = true"
                >
                    <i class="fa-solid fa-bars"></i>
                </button>

                <h1 class="hidden shrink-0 text-lg font-bold text-slate-800 md:block">{{ title }}</h1>

                <div class="relative min-w-0 flex-1 md:max-w-sm">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                        <i class="fa-solid fa-magnifying-glass text-sm"></i>
                    </span>
                    <input
                        type="text"
                        placeholder="ค้นหาชุมชน, จุดเสี่ยง, เหตุการณ์..."
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm text-slate-700 placeholder-slate-400 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                    />
                </div>

                <div class="ml-auto flex shrink-0 items-center gap-2 lg:gap-4">
                    <div
                        class="hidden items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3.5 py-2 text-sm text-slate-600 sm:flex"
                    >
                        <i class="fa-regular fa-calendar-days text-slate-400"></i>
                        {{ currentDateText }}
                        <i class="fa-solid fa-chevron-down text-[10px] text-slate-400"></i>
                    </div>

                    <button type="button" class="relative flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100">
                        <i class="fa-regular fa-bell text-base"></i>
                        <span
                            class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white"
                        >
                            6
                        </span>
                    </button>

                    <div ref="userMenuRef" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-full py-1 pl-1 pr-1 hover:bg-slate-100"
                            @click="userMenuOpen = !userMenuOpen"
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-600">
                                {{ user.name.charAt(0) }}
                            </span>
                            <span class="hidden text-left lg:block">
                                <span class="block text-sm font-medium text-slate-700">{{ roleLabels[user.role] ?? user.role }}</span>
                                <span class="block text-xs capitalize text-slate-400">{{ user.role }}</span>
                            </span>
                            <i class="fa-solid fa-chevron-down hidden text-[10px] text-slate-400 lg:block"></i>
                        </button>

                        <div
                            v-if="userMenuOpen"
                            class="absolute right-0 z-10 mt-2 w-56 overflow-hidden rounded-xl border border-slate-100 bg-white py-1 shadow-lg"
                        >
                            <div class="border-b border-slate-100 px-4 py-3">
                                <p class="truncate text-sm font-semibold text-slate-800">{{ user.name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ user.email }}</p>
                            </div>
                            <Link
                                :href="route('profile.edit')"
                                class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50"
                            >
                                <i class="fa-solid fa-user w-4 text-center text-slate-400"></i>
                                โปรไฟล์ของฉัน
                            </Link>
                            <Link
                                :href="route('logout')"
                                method="post"
                                as="button"
                                class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm text-red-600 hover:bg-red-50"
                            >
                                <i class="fa-solid fa-right-from-bracket w-4 text-center"></i>
                                ออกจากระบบ
                            </Link>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="custom-scrollbar flex-1 overflow-y-auto p-4 lg:p-6">
                <slot />
            </main>
        </div>
    </div>
</template>

<style>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
</style>
