<script setup lang="ts">
// หน้า 6 MCR2030 & KPI. Data from Mcr2030KpiController. Owner decisions 2026-11-10:
// - KPI รายหน่วยงาน (spec v5 §5.4–5.10): 5 = better, so Δ > 0 is green (the opposite of HEVC, spec §2.6).
//   - City scope: score KPIs are population-weighted; percent KPIs pool Σ/Σ.
//   - KPIs waiting for kpi_records say "รอข้อมูล KPI". The low-income KPI says "รอกำหนดสูตร".
//   - The %→0–5 band is the spec's test default (§5.7), and it's labelled as such.
// - MCR2030: evidence only, never a score (doc note 4). A community shows its status and sentence; the city shows
//   community counts per status.
import AnimatedNumber from '@/components/admin/AnimatedNumber.vue';
import Reveal from '@/components/admin/Reveal.vue';
import ScoreBar from '@/components/admin/ScoreBar.vue';
import { useInView } from '@/composables/useInView';
import { formatThaiDate } from '@/data/riskDisplay';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { KpiDefinition, KpiValue, McrCityEvidence, McrCommunityEvidence, McrStatus } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    fiscalYears: number[];
    year: number | null;
    initialCommunity: string | null;
    definitions: Record<string, KpiDefinition>;
    essentials: Record<string, { label: string; topics: string[] }>;
    city: { kpis: Record<string, KpiValue>; mcr: Record<string, McrCityEvidence>; totalCommunities: number } | null;
    communities: { code: string; name: string; kpis: Record<string, KpiValue>; mcr: Record<string, McrCommunityEvidence> }[];
}>();

// ---------------------------------------------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------------------------------------------
const selectedYear = ref(props.year);
const latestYear = computed(() => (props.fiscalYears.length ? Math.max(...props.fiscalYears) : null));
const scope = ref<string>(
    props.initialCommunity && props.communities.some((c) => c.code === props.initialCommunity) ? props.initialCommunity : 'CITY',
);
const initialTab = new URL(window.location.href).searchParams.get('tab');
const tab = ref<'kpi' | 'mcr'>(initialTab === 'mcr' ? 'mcr' : 'kpi');

function changeYear() {
    router.get(
        route('mcr2030-kpi.index'),
        { year: selectedYear.value, community: scope.value === 'CITY' ? undefined : scope.value, tab: tab.value },
        { preserveScroll: true },
    );
}

watch([scope, tab], () => {
    const url = new URL(window.location.href);
    if (scope.value === 'CITY') url.searchParams.delete('community');
    else url.searchParams.set('community', scope.value);
    url.searchParams.set('tab', tab.value);
    window.history.replaceState(window.history.state, '', url);
});

const community = computed(() => (scope.value === 'CITY' ? null : (props.communities.find((c) => c.code === scope.value) ?? null)));

// ---------------------------------------------------------------------------------------------------------------
// KPI
// ---------------------------------------------------------------------------------------------------------------
type Status = 'ok' | 'waiting' | 'pending' | 'empty';

const kpiRows = computed(() =>
    Object.entries(props.definitions).map(([key, def]) => {
        const v: KpiValue | undefined = community.value ? community.value.kpis[key] : props.city?.kpis[key];
        const score = v?.score ?? null;
        const previous = v?.previous ?? null;
        const delta = community.value ? (score !== null && previous !== null ? score - previous : null) : (v?.delta ?? null);
        const status: Status = def.kind === 'pending' ? 'pending' : !v?.hasData ? 'waiting' : score === null ? 'empty' : 'ok';
        return { key, def, v, score, previous, delta, status };
    }),
);

const agencies = computed(() => {
    const groups = new Map<string, typeof kpiRows.value>();
    for (const row of kpiRows.value) groups.set(row.def.agency, [...(groups.get(row.def.agency) ?? []), row]);
    return [...groups.entries()];
});

const summary = computed(() => {
    const rows = kpiRows.value.filter((r) => r.status === 'ok');
    const withDelta = rows.filter((r) => r.delta !== null);
    return {
        computed: rows.length,
        total: kpiRows.value.length,
        up: withDelta.filter((r) => r.delta! >= 0.05).length,
        same: withDelta.filter((r) => Math.abs(r.delta!) < 0.05).length,
        down: withDelta.filter((r) => r.delta! <= -0.05).length,
        noCompare: rows.length - withDelta.length,
    };
});

const STATUS_BADGE: Record<Exclude<Status, 'ok'>, { label: string; cls: string }> = {
    waiting: { label: 'รอข้อมูล KPI', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
    pending: { label: 'รอกำหนดสูตร', cls: 'bg-slate-100 text-slate-600 ring-slate-200' },
    empty: { label: 'ไม่มีข้อมูล', cls: 'bg-slate-100 text-slate-500 ring-slate-200' },
};

// KPI: higher is better, so a rise is green.
function deltaClass(delta: number) {
    if (Math.abs(delta) < 0.05) return 'text-slate-400';
    return delta > 0 ? 'text-green-600' : 'text-red-600';
}
function deltaIcon(delta: number) {
    if (Math.abs(delta) < 0.05) return 'fa-solid fa-minus';
    return delta > 0 ? 'fa-solid fa-arrow-up' : 'fa-solid fa-arrow-down';
}
const signed = (d: number) => `${d > 0 ? '+' : ''}${d.toFixed(1)}`;
const oneDecimal = (v: number) => v.toFixed(1);

const openKey = ref<string | null>(null);
const openRow = computed(() => kpiRows.value.find((r) => r.key === openKey.value) ?? null);

function rawText(key: string, raw: number | null): string | null {
    if (raw === null) return null;
    if (key === 'KPI_C8_2') return `${raw.toFixed(1)} ชั่วโมง`;
    if (props.definitions[key].kind === 'percent') return `${raw.toFixed(1)}%`;
    if (['KPI_WARNING', 'KPI_INFRA_FLOOD', 'KPI_INFRA_FIRE'].includes(key)) return `ช่องว่าง ${raw.toFixed(1)} / 5`;
    return null;
}

// ---------------------------------------------------------------------------------------------------------------
// MCR2030
// ---------------------------------------------------------------------------------------------------------------
const mcrEl = ref<HTMLElement | null>(null);
const mcrInView = useInView(mcrEl, 0.05);

const MCR_STATUS: Record<McrStatus, { label: string; cls: string; color: string }> = {
    full: { label: 'มีข้อมูล', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200', color: '#10b981' },
    partial: { label: 'มีบางส่วน', cls: 'bg-amber-50 text-amber-700 ring-amber-200', color: '#f59e0b' },
    none: { label: 'ยังไม่พบข้อมูล', cls: 'bg-slate-100 text-slate-500 ring-slate-200', color: '#cbd5e1' },
};

const card = 'rounded-2xl border border-slate-100 bg-white shadow-sm';
const selectClass =
    'rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="MCR2030 & KPI" />

    <AdminLayout title="MCR2030 & KPI">
        <!-- Filters (sticky) -->
        <div
            class="sticky -top-4 z-20 -mx-4 mb-5 flex flex-wrap items-end gap-3 border-b border-slate-200/70 bg-slate-50/90 px-4 py-3 backdrop-blur lg:-top-6 lg:-mx-6 lg:px-6"
        >
            <div class="flex gap-1 rounded-2xl border border-slate-200 bg-white p-1">
                <button
                    v-for="t in [
                        { key: 'kpi', label: 'ความก้าวหน้ารายหน่วยงาน (KPI)', icon: 'fa-solid fa-list-check' },
                        { key: 'mcr', label: 'MCR2030', icon: 'fa-solid fa-bullseye' },
                    ] as const"
                    :key="t.key"
                    type="button"
                    class="flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-medium transition-colors duration-300"
                    :class="tab === t.key ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'"
                    @click="tab = t.key"
                >
                    <i :class="t.icon"></i>{{ t.label }}
                </button>
            </div>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ขอบเขต</span>
                <select v-model="scope" :class="selectClass">
                    <option value="CITY">ทั้งเมือง</option>
                    <option v-for="c in [...communities].sort((a, b) => a.name.localeCompare(b.name, 'th'))" :key="c.code" :value="c.code">
                        {{ c.name }}
                    </option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ช่วงข้อมูล</span>
                <select v-model="selectedYear" :disabled="!fiscalYears.length" :class="selectClass" @change="changeYear">
                    <option v-for="y in [...fiscalYears].reverse()" :key="y" :value="y">
                        ปีงบประมาณ {{ y }}{{ y === latestYear ? ' (ล่าสุด)' : '' }}
                    </option>
                </select>
            </label>
        </div>

        <div v-if="!city" class="rounded-2xl border border-slate-100 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
            <i class="fa-solid fa-database mb-3 block text-3xl text-slate-300"></i>
            ยังไม่มีข้อมูลสำหรับคำนวณ — นำเข้าข้อมูลที่เมนู "นำเข้าข้อมูล" ก่อน
        </div>

        <!-- ================================= KPI ================================= -->
        <template v-else-if="tab === 'kpi'">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div :class="[card, 'p-5']">
                    <p class="text-xs text-slate-500">KPI ที่คำนวณได้</p>
                    <p class="mt-1 text-3xl font-bold text-slate-800">
                        <AnimatedNumber :value="summary.computed" /><span class="text-base text-slate-400"> / {{ summary.total }}</span>
                    </p>
                </div>
                <div :class="[card, 'p-5']">
                    <p class="text-xs text-slate-500"><i class="fa-solid fa-arrow-up mr-1 text-green-600"></i>ดีขึ้น</p>
                    <p class="mt-1 text-3xl font-bold text-green-600"><AnimatedNumber :value="summary.up" /></p>
                </div>
                <div :class="[card, 'p-5']">
                    <p class="text-xs text-slate-500"><i class="fa-solid fa-minus mr-1 text-slate-400"></i>คงเดิม</p>
                    <p class="mt-1 text-3xl font-bold text-slate-600"><AnimatedNumber :value="summary.same" /></p>
                </div>
                <div :class="[card, 'p-5']">
                    <p class="text-xs text-slate-500"><i class="fa-solid fa-arrow-down mr-1 text-red-600"></i>ลดลง</p>
                    <p class="mt-1 text-3xl font-bold text-red-600"><AnimatedNumber :value="summary.down" /></p>
                </div>
                <div :class="[card, 'p-5']">
                    <p class="text-xs text-slate-500">ยังไม่มีข้อมูลเปรียบเทียบ</p>
                    <p class="mt-1 text-3xl font-bold text-slate-400"><AnimatedNumber :value="summary.noCompare" /></p>
                </div>
            </div>

            <div :class="[card, 'mt-5 overflow-hidden']">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
                    <div>
                        <h2 class="font-semibold text-slate-800">ความก้าวหน้ารายหน่วยงาน</h2>
                        <p class="text-xs text-slate-400">
                            คะแนน 0–5 (5 = ดีกว่าเสมอ) ·
                            {{ community ? `ชุมชน${community.name}` : 'ทั้งเมือง: KPI คะแนนเฉลี่ยถ่วงประชากร · KPI ร้อยละรวมยอดทั้งเมือง' }} ·
                            คลิกแถวเพื่อดูรายละเอียด
                        </p>
                    </div>
                </div>
                <div class="custom-scrollbar overflow-x-auto">
                    <table class="w-full min-w-[860px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-5 py-2.5 font-medium">หน่วยงาน</th>
                                <th class="px-4 py-2.5 font-medium">KPI</th>
                                <th class="px-4 py-2.5 text-right font-medium">คะแนนก่อน</th>
                                <th class="w-56 px-4 py-2.5 font-medium">คะแนนปัจจุบัน</th>
                                <th class="px-4 py-2.5 text-right font-medium">ผลต่าง</th>
                                <th class="px-5 py-2.5 text-right font-medium">อัปเดตล่าสุด</th>
                            </tr>
                        </thead>
                        <tbody v-for="[agency, rows] in agencies" :key="agency" class="border-t border-slate-100">
                            <tr v-for="(row, i) in rows" :key="row.key" class="cursor-pointer hover:bg-blue-50/50" @click="openKey = row.key">
                                <td
                                    v-if="i === 0"
                                    :rowspan="rows.length"
                                    class="border-r border-slate-100 px-5 py-3 align-top font-semibold text-slate-700"
                                >
                                    {{ agency }}
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ row.def.label }}</td>
                                <td class="px-4 py-3 text-right font-mono text-slate-500">
                                    {{ row.previous === null ? '–' : row.previous.toFixed(1) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div v-if="row.status === 'ok'" class="flex items-center gap-3">
                                        <ScoreBar :value="row.score" :max="5" class="flex-1" />
                                        <span class="w-8 text-right font-mono font-semibold text-slate-800"
                                            ><AnimatedNumber :value="row.score" :format="oneDecimal"
                                        /></span>
                                    </div>
                                    <span v-else class="rounded-full px-2.5 py-0.5 text-xs ring-1" :class="STATUS_BADGE[row.status].cls">{{
                                        STATUS_BADGE[row.status].label
                                    }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span v-if="row.delta !== null" class="font-mono text-xs" :class="deltaClass(row.delta)">
                                        <i :class="deltaIcon(row.delta)" class="mr-0.5"></i>{{ signed(row.delta) }}
                                    </span>
                                    <span v-else-if="row.status === 'ok'" class="text-[11px] text-slate-300">ยังไม่มีข้อมูลเปรียบเทียบ</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right text-xs text-slate-500">{{ formatThaiDate(row.v?.updated) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-slate-100 px-5 py-3 text-[11px] text-slate-400">
                    <i class="fa-solid fa-flask mr-1"></i>KPI ที่เป็นร้อยละแปลงเป็นคะแนนด้วยเกณฑ์ทดสอบตามสเปก §5.7 (0% = 0, &gt;0–20% = 1, …, &gt;80%
                    = 5) ยังไม่ใช่เกณฑ์ถาวร · KPI ยังรอการยืนยันจากเทศบาล
                </p>
            </div>
        </template>

        <!-- ================================= MCR2030 ================================= -->
        <template v-else>
            <div class="mb-5 rounded-2xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                <i class="fa-solid fa-circle-info mr-1"></i>
                MCR2030 Alignment Evidence Screening แสดง<span class="font-semibold">หลักฐานที่ระบบมี</span>สำหรับ 10 Essentials ไม่ใช่ Disaster
                Resilience Scorecard และไม่สรุปเป็นคะแนน MCR2030 อย่างเป็นทางการ
                <template v-if="!community"> · ทั้งเมือง: นับจำนวนชุมชนตามสถานะ จากทั้งหมด {{ city.totalCommunities }} ชุมชน</template>
            </div>

            <div ref="mcrEl" class="grid gap-4 md:grid-cols-2">
                <Reveal v-for="([key, essential], i) in Object.entries(essentials)" :key="key" :delay="(i % 2) * 80">
                    <div :class="[card, 'h-full p-5']">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="font-semibold text-slate-800">
                                <span class="mr-1.5 rounded-lg bg-blue-600 px-2 py-0.5 text-xs font-bold text-white">{{ key }}</span
                                >{{ essential.label }}
                            </h3>
                            <span
                                v-if="community"
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 transition-colors duration-500"
                                :class="MCR_STATUS[community.mcr[key].status].cls"
                                >{{ MCR_STATUS[community.mcr[key].status].label }}</span
                            >
                        </div>

                        <!-- Community -->
                        <template v-if="community">
                            <p class="mt-3 text-sm leading-relaxed text-slate-600">“{{ community.mcr[key].sentence }}”</p>
                            <p v-if="community.mcr[key].missing.length" class="mt-2 text-xs text-slate-400">
                                <i class="fa-solid fa-circle-minus mr-1"></i>ยังไม่พบ: {{ community.mcr[key].missing.join(', ') }}
                            </p>
                        </template>

                        <!-- City -->
                        <template v-else>
                            <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-slate-100">
                                <div
                                    v-for="s in ['full', 'partial', 'none'] as const"
                                    :key="s"
                                    class="h-full transition-[width] duration-1000 ease-out"
                                    :style="{
                                        width: mcrInView ? `${(city.mcr[key][s] / Math.max(city.totalCommunities, 1)) * 100}%` : '0%',
                                        background: MCR_STATUS[s].color,
                                    }"
                                ></div>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-3 text-xs text-slate-500">
                                <span v-for="s in ['full', 'partial', 'none'] as const" :key="s">
                                    <span class="mr-1 inline-block h-2 w-2 rounded-full" :style="{ background: MCR_STATUS[s].color }"></span
                                    >{{ MCR_STATUS[s].label }} <span class="font-semibold text-slate-700">{{ city.mcr[key][s] }}</span>
                                </span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <span
                                    v-for="t in city.mcr[key].found"
                                    :key="t"
                                    class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] text-emerald-700"
                                >
                                    <i class="fa-solid fa-check mr-0.5"></i>{{ t }}
                                </span>
                                <span
                                    v-for="t in city.mcr[key].missing"
                                    :key="t"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500"
                                >
                                    <i class="fa-solid fa-minus mr-0.5"></i>{{ t }}
                                </span>
                            </div>
                        </template>
                    </div>
                </Reveal>
            </div>
        </template>

        <!-- KPI detail -->
        <Transition name="fade">
            <div
                v-if="openRow"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
                @click.self="openKey = null"
            >
                <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
                    <div class="flex items-start justify-between gap-3 bg-blue-600 px-6 py-4 text-white">
                        <div>
                            <h3 class="text-base font-semibold">{{ openRow.def.label }}</h3>
                            <p class="text-xs text-blue-100">{{ openRow.def.agency }} · {{ community ? `ชุมชน${community.name}` : 'ทั้งเมือง' }}</p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-blue-100 hover:bg-white/15"
                            @click="openKey = null"
                        >
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <dl class="space-y-3 p-6 text-sm">
                        <div>
                            <dt class="text-xs text-slate-400">สูตร</dt>
                            <dd class="text-slate-700">{{ openRow.def.formula }}</dd>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="text-[11px] text-slate-400">คะแนนก่อน</dt>
                                <dd class="font-semibold text-slate-800">{{ openRow.previous?.toFixed(2) ?? '–' }}</dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="text-[11px] text-slate-400">คะแนนปัจจุบัน</dt>
                                <dd class="font-semibold text-slate-800">{{ openRow.score?.toFixed(2) ?? '–' }}</dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="text-[11px] text-slate-400">ผลต่าง</dt>
                                <dd class="font-semibold" :class="openRow.delta === null ? 'text-slate-400' : deltaClass(openRow.delta)">
                                    {{ openRow.delta === null ? '–' : signed(openRow.delta) }}
                                </dd>
                            </div>
                        </div>
                        <div v-if="rawText(openRow.key, openRow.v?.raw ?? null)">
                            <dt class="text-xs text-slate-400">ผลจริง</dt>
                            <dd class="text-slate-700">{{ rawText(openRow.key, openRow.v?.raw ?? null) }}</dd>
                        </div>
                        <div v-if="!community && openRow.v?.communities !== undefined">
                            <dt class="text-xs text-slate-400">ชุมชนที่มีข้อมูล</dt>
                            <dd class="text-slate-700">{{ openRow.v.communities }} จาก {{ city?.totalCommunities }} ชุมชน</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-400">อัปเดตล่าสุด</dt>
                            <dd class="text-slate-700">{{ formatThaiDate(openRow.v?.updated) }}</dd>
                        </div>
                        <p v-if="openRow.status === 'waiting'" class="rounded-xl bg-amber-50 p-3 text-xs text-amber-700">
                            ยังไม่มีข้อมูลจากชีต KPI (กลุ่มงาน "งาน KPI") — ระบบจะคำนวณอัตโนมัติเมื่อมีการนำเข้า
                        </p>
                        <p v-if="openRow.status === 'pending'" class="rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                            สเปกยังไม่ระบุตัวหารที่ชัดเจน รอการกำหนดสูตรจากเทศบาล
                        </p>
                        <p v-if="openRow.def.kind === 'percent'" class="text-[11px] text-slate-400">แปลงร้อยละเป็นคะแนนด้วยเกณฑ์ทดสอบตามสเปก §5.7</p>
                    </dl>
                </div>
            </div>
        </Transition>
    </AdminLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .fade-enter-active,
    .fade-leave-active {
        transition: none;
    }
}
</style>
