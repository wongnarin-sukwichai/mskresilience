<script setup lang="ts">
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import { communityBoundaries } from '@/data/communityBoundaries';
import { communities as mapCommunities } from '@/data/mahasarakham';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { ManagedCommunity } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    communities: ManagedCommunity[];
}>();

// Map boundaries are not stored in the database (product decision, 2026-10-29) — they live in
// communityBoundaries.ts, keyed by mahasarakham.ts's local id, which carries the client code. A
// community added here shows "ไม่มี" until a developer adds its polygon to those two files.
const codesWithBoundary = new Set(
    mapCommunities.filter((c) => communityBoundaries.some((b) => b.id === c.id && b.rings.length > 0)).map((c) => c.code),
);

const search = ref('');

const filteredCommunities = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.communities;
    return props.communities.filter((c) => c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q));
});

const missingBoundaryCount = computed(() => props.communities.filter((c) => !codesWithBoundary.has(c.code)).length);

// Laid out as 3 side-by-side tables of 15 rows each (per product decision), so one page holds 45 —
// all 32 communities fit on a single page; pagination only kicks in if the list grows past 45.
const ROWS_PER_TABLE = 15;
const TABLES_PER_PAGE = 3;
const PAGE_SIZE = ROWS_PER_TABLE * TABLES_PER_PAGE;

const currentPage = ref(1);
const pageCount = computed(() => Math.max(1, Math.ceil(filteredCommunities.value.length / PAGE_SIZE)));

watch(search, () => (currentPage.value = 1));
watch(pageCount, (count) => {
    if (currentPage.value > count) currentPage.value = count;
});

const pageTables = computed(() => {
    const start = (currentPage.value - 1) * PAGE_SIZE;
    const pageItems = filteredCommunities.value.slice(start, start + PAGE_SIZE);
    return Array.from({ length: TABLES_PER_PAGE }, (_, i) => pageItems.slice(i * ROWS_PER_TABLE, (i + 1) * ROWS_PER_TABLE)).filter(
        (rows) => rows.length > 0,
    );
});

// Suggest the next unused R-code when creating, e.g. R033 after R032.
function nextCode() {
    const max = props.communities.reduce((m, c) => Math.max(m, Number(c.code.slice(1)) || 0), 0);
    return `R${String(max + 1).padStart(3, '0')}`;
}

// Modal state — clicking a row opens it in edit mode, where delete also lives.
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingCommunity = ref<ManagedCommunity | null>(null);

const form = useForm({
    code: '',
    name: '',
});

function openCreateModal() {
    modalMode.value = 'create';
    editingCommunity.value = null;
    form.reset();
    form.clearErrors();
    form.code = nextCode();
    modalOpen.value = true;
}

function openEditModal(community: ManagedCommunity) {
    modalMode.value = 'edit';
    editingCommunity.value = community;
    form.clearErrors();
    form.code = community.code;
    form.name = community.name;
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function submit() {
    if (modalMode.value === 'create') {
        form.post(route('communities.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else if (editingCommunity.value) {
        form.put(route('communities.update', editingCommunity.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
}

// Delete confirmation (opened from the edit modal's ลบชุมชน button, stacked on top of it).
const confirmDeleteOpen = ref(false);
const deleting = ref(false);

function destroyCommunity() {
    const community = editingCommunity.value;
    if (!community) return;
    deleting.value = true;
    router.delete(route('communities.destroy', community.id), {
        preserveScroll: true,
        onSuccess: () => {
            confirmDeleteOpen.value = false;
            closeModal();
        },
        onFinish: () => (deleting.value = false),
    });
}
</script>

<template>
    <Head title="จัดการชุมชน" />

    <AdminLayout title="จัดการชุมชน">
        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
                <div>
                    <h2 class="font-semibold text-slate-800">จัดการชุมชน</h2>
                    <p class="mt-0.5 text-sm text-slate-400">
                        ชุมชนในระบบทั้งหมด {{ communities.length }} ชุมชน · คลิกรายการเพื่อแก้ไขหรือลบ
                        <span v-if="missingBoundaryCount > 0" class="text-amber-600">· ยังไม่มีขอบเขตแผนที่ {{ missingBoundaryCount }} ชุมชน</span>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        ></i>
                        <input
                            v-model="search"
                            type="search"
                            placeholder="ค้นหารหัสหรือชื่อชุมชน"
                            class="w-56 rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-8 pr-3 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                    </div>
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700"
                    >
                        <i class="fa-solid fa-plus"></i>
                        เพิ่มชุมชน
                    </button>
                </div>
            </div>

            <div v-if="filteredCommunities.length === 0" class="px-5 py-10 text-center text-sm text-slate-400">ไม่พบชุมชนที่ค้นหา</div>

            <div v-else class="grid gap-4 p-5 lg:grid-cols-3">
                <div v-for="(rows, tableIndex) in pageTables" :key="tableIndex" class="overflow-hidden rounded-xl border border-slate-100">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">รหัส</th>
                                <th class="px-4 py-2.5 font-medium">ชื่อชุมชน</th>
                                <th class="px-4 py-2.5 text-right font-medium">ขอบเขตแผนที่</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="community in rows"
                                :key="community.id"
                                tabindex="0"
                                class="cursor-pointer hover:bg-blue-50/50 focus:bg-blue-50/50 focus:outline-none"
                                @click="openEditModal(community)"
                                @keydown.enter="openEditModal(community)"
                            >
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-slate-500">{{ community.code }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-700">{{ community.name }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <span
                                        v-if="codesWithBoundary.has(community.code)"
                                        class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-600"
                                    >
                                        <i class="fa-solid fa-check mr-1"></i>มี
                                    </span>
                                    <span
                                        v-else
                                        class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-600"
                                        title="ต้องให้ผู้พัฒนาเพิ่มขอบเขตพื้นที่ของชุมชนนี้เข้าระบบ"
                                    >
                                        <i class="fa-solid fa-xmark mr-1"></i>ไม่มี
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="pageCount > 1" class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                <span>หน้า {{ currentPage }} / {{ pageCount }}</span>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        :disabled="currentPage === 1"
                        @click="currentPage--"
                        class="rounded-lg border border-slate-200 px-3 py-1.5 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <i class="fa-solid fa-chevron-left text-xs"></i>
                    </button>
                    <button
                        type="button"
                        :disabled="currentPage === pageCount"
                        @click="currentPage++"
                        class="rounded-lg border border-slate-200 px-3 py-1.5 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
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
                <div class="flex items-center justify-between bg-blue-600 px-6 py-4">
                    <h3 class="text-base font-semibold text-white">
                        {{ modalMode === 'create' ? 'เพิ่มชุมชน' : 'แก้ไขข้อมูลชุมชน' }}
                    </h3>
                    <button
                        type="button"
                        @click="closeModal"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-blue-100 hover:bg-white/15 hover:text-white"
                    >
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="space-y-4 p-6">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">รหัสชุมชน</label>
                        <!-- Code is immutable once created (backend ignores it on update) — fix a wrong code by deleting and re-adding. -->
                        <div
                            v-if="modalMode === 'edit'"
                            class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 font-mono text-sm text-slate-500"
                        >
                            {{ form.code }}
                        </div>
                        <input
                            v-else
                            v-model="form.code"
                            type="text"
                            required
                            maxlength="4"
                            placeholder="R033"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 font-mono text-sm uppercase text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                        <p class="mt-1.5 text-xs text-slate-400">
                            <template v-if="modalMode === 'edit'">แก้ไขรหัสไม่ได้ หากรหัสผิด ให้ลบชุมชนนี้แล้วเพิ่มใหม่</template>
                            <template v-else>รูปแบบ R ตามด้วยตัวเลข 3 หลัก ตรงกับ COMMUNITY_ID ในไฟล์นำเข้าข้อมูล · แก้ไขภายหลังไม่ได้</template>
                        </p>
                        <p v-if="form.errors.code" class="mt-1.5 text-xs text-red-600">{{ form.errors.code }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ชื่อชุมชน</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            placeholder="เช่น ตักศิลา 1"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-800 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                        />
                        <p v-if="form.errors.name" class="mt-1.5 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <details v-if="modalMode === 'create'" class="group rounded-xl bg-amber-50 px-3.5 py-2.5 text-xs text-amber-700">
                        <summary class="cursor-pointer list-none font-semibold">
                            <i class="fa-solid fa-chevron-right mr-1 text-[10px] transition-transform group-open:rotate-90"></i>
                            ขั้นตอนการเพิ่มชุมชน
                        </summary>
                        <ol class="mt-2 list-decimal space-y-1.5 pl-5 leading-relaxed">
                            <li>เจ้าหน้าที่ เพิ่มชุมชนในเมนูจัดการชุมชน เช่น R033 รายการจะยังขึ้นป้ายสีส้ม "ไม่มี" ตรงคอลัมน์ขอบเขตแผนที่</li>
                            <li>
                                แปลงไฟล์ GIS (shapefile หรือ KML/GeoJSON), เพิ่มรูปขอบเขตลงไฟล์ communityBoundaries.ts, เพิ่มรายการพร้อม code
                                และจุดกึ่งกลางลง mahasarakham.ts แล้ว build/deploy ใหม่
                            </li>
                            <li>รายการชุมชนจะเปลี่ยนเป็น "มี" และขึ้นบนแผนที่</li>
                        </ol>
                    </details>

                    <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
                        <button
                            v-if="modalMode === 'edit'"
                            type="button"
                            @click="confirmDeleteOpen = true"
                            class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm text-red-600 hover:bg-red-50"
                        >
                            <i class="fa-regular fa-trash-can"></i>
                            ลบชุมชน
                        </button>
                        <div class="ml-auto flex items-center gap-3">
                            <button
                                type="button"
                                @click="closeModal"
                                class="rounded-xl px-4 py-2 text-sm text-slate-500 hover:bg-slate-50 hover:text-slate-800"
                            >
                                ยกเลิก
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {{ form.processing ? 'กำลังบันทึก...' : 'บันทึก' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <ConfirmDialog
            :open="confirmDeleteOpen"
            title="ยืนยันการลบชุมชน"
            :message="`ต้องการลบ “${editingCommunity?.name}” (${editingCommunity?.code}) ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้`"
            confirm-label="ลบชุมชน"
            :processing="deleting"
            @confirm="destroyCommunity"
            @cancel="confirmDeleteOpen = false"
        />
    </AdminLayout>
</template>
