<script setup lang="ts">
import ExportButtons from '@/components/admin/ExportButtons.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// ตารางเทียบ TOR (TorMatrixController, App\Support\TorMatrix): every TOR clause, its text, where the system answers
// it and a status. Used in the acceptance presentation, so it prints cleanly ("บันทึก PDF").
type Status = 'done' | 'test' | 'doc' | 'todo';
interface Item {
    ref: string;
    text: string;
    status: Status;
    where: { label: string; href: string | null }[];
    note?: string;
}
const props = defineProps<{
    sections: { section: string; items: Item[] }[];
    statuses: Record<Status, { label: string; class: string; icon: string }>;
}>();

const filter = ref<'ALL' | Status>('ALL');
const search = ref('');
const all = computed(() => props.sections.flatMap((s) => s.items));
const counts = computed(() => (Object.keys(props.statuses) as Status[]).map((s) => ({ key: s, n: all.value.filter((i) => i.status === s).length })));
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.sections
        .map((s) => ({
            ...s,
            items: s.items.filter(
                (i) =>
                    (filter.value === 'ALL' || i.status === filter.value) && (!q || `${i.ref} ${i.text} ${i.note ?? ''}`.toLowerCase().includes(q)),
            ),
        }))
        .filter((s) => s.items.length);
});
</script>

<template>
    <Head title="ตารางเทียบ TOR" />

    <AdminLayout title="ตารางเทียบ TOR">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div class="border-l-4 border-blue-600 pl-4">
                <h1 class="text-2xl font-bold text-slate-800">ตารางเทียบ TOR</h1>
                <p class="mt-1 text-sm text-slate-500">
                    ทุกข้อในขอบเขตของงาน (TOR) เทียบกับตำแหน่งในระบบ · ทั้งหมด {{ all.length }} ข้อ · เลขข้อตรงกับป้าย TOR
                    ที่แสดงท้ายหัวข้อในแต่ละหน้า
                </p>
            </div>
            <ExportButtons />
        </div>

        <div class="mb-5 flex flex-wrap items-center gap-2 print:hidden">
            <button
                type="button"
                class="rounded-full px-3 py-1 text-xs font-medium ring-1"
                :class="filter === 'ALL' ? 'bg-blue-600 text-white ring-blue-600' : 'bg-white text-slate-600 ring-slate-200'"
                @click="filter = 'ALL'"
            >
                ทั้งหมด {{ all.length }}
            </button>
            <button
                v-for="c in counts"
                :key="c.key"
                type="button"
                class="rounded-full px-3 py-1 text-xs font-medium ring-1"
                :class="filter === c.key ? 'bg-blue-600 text-white ring-blue-600' : statuses[c.key].class"
                @click="filter = c.key"
            >
                <i :class="statuses[c.key].icon" class="mr-1"></i>{{ statuses[c.key].label }} {{ c.n }}
            </button>
            <input
                v-model="search"
                type="search"
                placeholder="ค้นหาเลขข้อหรือข้อความ"
                class="ml-auto w-60 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
        </div>

        <!-- Printed summary (the chips above are hidden on paper) -->
        <p class="mb-3 hidden text-xs text-slate-600 print:block">
            <span v-for="c in counts" :key="c.key" class="mr-4">{{ statuses[c.key].label }} {{ c.n }} ข้อ</span>
        </p>

        <div class="space-y-5">
            <section
                v-for="s in shown"
                :key="s.section"
                class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm print:shadow-none"
            >
                <h2 class="border-b border-slate-100 bg-slate-50 px-5 py-2.5 text-sm font-semibold text-slate-800">{{ s.section }}</h2>
                <table class="w-full text-sm print:text-xs">
                    <thead class="text-left text-xs text-slate-500">
                        <tr class="border-b border-slate-100">
                            <th class="w-24 px-3 py-2 lg:w-36 lg:px-5 print:w-24 print:px-2">ข้อ</th>
                            <th class="px-2 py-2">ข้อกำหนด</th>
                            <th class="w-40 px-2 py-2 lg:w-64 print:w-36">ตำแหน่งในระบบ</th>
                            <th class="w-32 px-3 py-2 lg:w-44 lg:px-5 print:w-28 print:px-2">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in s.items" :key="i.ref" class="print-keep border-b border-slate-50 align-top last:border-0">
                            <td class="px-3 py-2.5 font-semibold text-slate-700 lg:whitespace-nowrap lg:px-5 print:whitespace-normal print:px-2">
                                {{ i.ref }}
                            </td>
                            <td class="px-2 py-2.5 text-slate-700">
                                {{ i.text }}
                                <p v-if="i.note" class="mt-0.5 text-xs text-slate-500"><i class="fa-solid fa-circle-info mr-1"></i>{{ i.note }}</p>
                            </td>
                            <td class="px-2 py-2.5">
                                <ul class="space-y-0.5 text-xs">
                                    <li v-for="w in i.where" :key="w.label">
                                        <Link v-if="w.href" :href="w.href" class="text-blue-600 hover:underline">{{ w.label }}</Link>
                                        <span v-else class="text-slate-600">{{ w.label }}</span>
                                    </li>
                                    <li v-if="!i.where.length" class="text-slate-400">–</li>
                                </ul>
                            </td>
                            <td class="px-3 py-2.5 lg:px-5 print:px-2">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                                    :class="statuses[i.status].class"
                                >
                                    <i :class="statuses[i.status].icon"></i>{{ statuses[i.status].label }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <p v-if="!shown.length" class="rounded-2xl bg-white p-8 text-center text-sm text-slate-400">ไม่พบข้อที่ตรงกับตัวกรอง</p>
        </div>
    </AdminLayout>
</template>
