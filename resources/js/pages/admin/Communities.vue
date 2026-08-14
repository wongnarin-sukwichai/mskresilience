<script setup lang="ts">
import { communities as realCommunities, riskNameTH, type Community } from '@/data/mahasarakham';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Mock CRUD — there's no `communities` table/controller yet (see CLAUDE.md), so this page reads/writes an
// in-memory list only. Field set is a small starter subset of the ภัยพิบัติรวม "input" columns (Meta group);
// intentionally not the full HEVC dictionary (Vulnerability/Capacity/Recovery/budget) — that's deferred until
// the real schema lands, per team decision.

interface CommunityRecord {
    id: number;
    name: string;
    tambon: string;
    population: number;
    households: number;
    areaKm2: number;
    dominantHazard: Community['activeRisk'];
}

function seeded(seed: number): number {
    const x = Math.sin(seed * 78.233) * 12543.987;
    return Math.abs(x - Math.floor(x));
}

const communities = ref<CommunityRecord[]>(
    realCommunities.map((c) => ({
        id: c.id,
        name: c.name,
        tambon: 'ตลาด',
        population: Math.round(600 + seeded(c.id) * 3200),
        households: Math.round(200 + seeded(c.id + 0.5) * 1000),
        areaKm2: Math.round((0.3 + seeded(c.id + 0.25) * 2.2) * 100) / 100,
        dominantHazard: c.activeRisk,
    })),
);

const hazardOptions: Community['activeRisk'][] = ['flood', 'fire', 'wind', 'heat', 'pm25', 'none'];

const hazardBadgeClass: Record<Community['activeRisk'], string> = {
    flood: 'bg-blue-50 text-blue-600',
    fire: 'bg-red-50 text-red-600',
    wind: 'bg-teal-50 text-teal-600',
    heat: 'bg-orange-50 text-orange-600',
    pm25: 'bg-purple-50 text-purple-600',
    none: 'bg-slate-100 text-slate-500',
};

// Modal state
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingId = ref<number | null>(null);

const form = ref({
    name: '',
    tambon: 'ตลาด',
    population: 0,
    households: 0,
    areaKm2: 0,
    dominantHazard: 'none' as Community['activeRisk'],
});

function openCreateModal() {
    modalMode.value = 'create';
    editingId.value = null;
    form.value = { name: '', tambon: 'ตลาด', population: 0, households: 0, areaKm2: 0, dominantHazard: 'none' };
    modalOpen.value = true;
}

function openEditModal(community: CommunityRecord) {
    modalMode.value = 'edit';
    editingId.value = community.id;
    form.value = {
        name: community.name,
        tambon: community.tambon,
        population: community.population,
        households: community.households,
        areaKm2: community.areaKm2,
        dominantHazard: community.dominantHazard,
    };
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function submit() {
    if (modalMode.value === 'create') {
        const nextId = Math.max(0, ...communities.value.map((c) => c.id)) + 1;
        communities.value.push({ id: nextId, ...form.value });
        currentPage.value = totalPages.value; // jump to the page holding the new row
    } else if (editingId.value !== null) {
        const target = communities.value.find((c) => c.id === editingId.value);
        if (target) Object.assign(target, form.value);
    }
    closeModal();
}

function destroyCommunity(community: CommunityRecord) {
    if (!confirm(`ยืนยันลบชุมชน "${community.name}" ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้`)) return;
    communities.value = communities.value.filter((c) => c.id !== community.id);
    goToPage(currentPage.value); // clamp back if the page we were on no longer exists
}

// ── Pagination — 10 rows/page ──
const currentPage = ref(1);
const pageSize = 10;
const totalPages = computed(() => Math.max(1, Math.ceil(communities.value.length / pageSize)));
const pagedCommunities = computed(() => {
    const start = (currentPage.value - 1) * pageSize;
    return communities.value.slice(start, start + pageSize);
});

function goToPage(page: number) {
    currentPage.value = Math.min(Math.max(page, 1), totalPages.value);
}
</script>

<template>
    <Head title="จัดการชุมชน" />

    <AdminLayout title="จัดการชุมชน">
        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 p-5">
                <div>
                    <h2 class="font-semibold text-slate-800">จัดการชุมชน</h2>
                    <p class="mt-0.5 text-sm text-slate-400">ข้อมูลพื้นฐานของชุมชนทั้งหมด {{ communities.length }} ชุมชน</p>
                </div>
                <button
                    type="button"
                    @click="openCreateModal"
                    class="flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700"
                >
                    <i class="fa-solid fa-plus"></i>
                    เพิ่มชุมชนใหม่
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-5 py-3 font-medium">ชื่อชุมชน</th>
                            <th class="px-5 py-3 font-medium">ตำบล</th>
                            <th class="px-5 py-3 font-medium">ประชากร (คน)</th>
                            <th class="px-5 py-3 font-medium">ครัวเรือน</th>
                            <th class="px-5 py-3 font-medium">พื้นที่ (ตร.กม.)</th>
                            <th class="px-5 py-3 font-medium">ภัยเด่น</th>
                            <th class="px-5 py-3 font-medium">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="community in pagedCommunities" :key="community.id" class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-700">{{ community.name }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ community.tambon }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ community.population.toLocaleString('th-TH') }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ community.households.toLocaleString('th-TH') }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ community.areaKm2 }}</td>
                            <td class="whitespace-nowrap px-5 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="hazardBadgeClass[community.dominantHazard]">
                                    {{ riskNameTH[community.dominantHazard] }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-3">
                                <div class="flex items-center gap-3 text-slate-400">
                                    <button type="button" title="แก้ไข" @click="openEditModal(community)" class="hover:text-blue-600">
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </button>
                                    <button type="button" title="ลบ" @click="destroyCommunity(community)" class="hover:text-red-600">
                                        <i class="fa-regular fa-trash-can"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                <span>
                    แสดง {{ (currentPage - 1) * pageSize + 1 }}-{{ Math.min(currentPage * pageSize, communities.length) }} จาก
                    {{ communities.length }} รายการ
                </span>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        @click="goToPage(currentPage - 1)"
                        :disabled="currentPage === 1"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <i class="fa-solid fa-chevron-left text-xs"></i>
                    </button>
                    <button
                        v-for="page in totalPages"
                        :key="page"
                        type="button"
                        @click="goToPage(page)"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-sm"
                        :class="page === currentPage ? 'bg-blue-600 font-medium text-white' : 'text-slate-500 hover:bg-slate-100'"
                    >
                        {{ page }}
                    </button>
                    <button
                        type="button"
                        @click="goToPage(currentPage + 1)"
                        :disabled="currentPage === totalPages"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <i class="fa-solid fa-chevron-right text-xs"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Create / Edit Modal -->
        <div
            v-if="modalOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @click.self="closeModal"
        >
            <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ modalMode === 'create' ? 'เพิ่มชุมชนใหม่' : 'แก้ไขข้อมูลชุมชน' }}
                    </h3>
                    <button
                        type="button"
                        @click="closeModal"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="space-y-4 p-6">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ชื่อชุมชน</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ตำบล</label>
                        <input
                            v-model="form.tambon"
                            type="text"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ประชากรทั้งหมด</label>
                            <input
                                v-model.number="form.population"
                                type="number"
                                min="0"
                                required
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                            />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">จำนวนครัวเรือน</label>
                            <input
                                v-model.number="form.households"
                                type="number"
                                min="0"
                                required
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">พื้นที่ (ตร.กม.)</label>
                        <input
                            v-model.number="form.areaKm2"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ภัยเด่น</label>
                        <select
                            v-model="form.dominantHazard"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        >
                            <option v-for="opt in hazardOptions" :key="opt" :value="opt">{{ riskNameTH[opt] }}</option>
                        </select>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                        <button
                            type="button"
                            @click="closeModal"
                            class="rounded-xl px-4 py-2 text-sm text-slate-500 hover:bg-slate-50 hover:text-slate-800"
                        >
                            ยกเลิก
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700"
                        >
                            บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
