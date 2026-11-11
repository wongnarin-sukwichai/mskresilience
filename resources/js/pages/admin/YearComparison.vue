<script setup lang="ts">
// เปรียบเทียบรายปี (owner-approved from the /compare-preview mock, 2026-11-11). Every score comes from RiskEngine via
// YearComparisonController: two fiscal years A and B, each community's latest record on or before each side's date,
// and the criteria set of that record's year. The page only classifies communities (ลดลง/เพิ่มขึ้น/ทรงตัว) by the
// "ค่าความต่างที่รับได้" setting and counts where each side's data comes from.
import AnimatedNumber from '@/components/admin/AnimatedNumber.vue';
import Reveal from '@/components/admin/Reveal.vue';
import RiskGauge from '@/components/admin/RiskGauge.vue';
import { useInView } from '@/composables/useInView';
import { HAZARD_KEYS, formatThaiDate, hazardIcon, hazardLabel, levelOf, riskLevels } from '@/data/riskDisplay';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { HazardKey } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface Side {
    multi: number;
    hazards: Record<HazardKey, number | null>;
    dataDate: string;
    fiscalYear: number;
    criteriaSet: { id: number; name: string };
}
interface Community {
    code: string;
    name: string;
    stale: boolean;
    a: Side | null;
    b: Side | null;
}
interface SideMeta {
    year: number;
    asOf: string | null;
    latest: boolean;
    multi: number | null;
    communities: number;
}
interface HazardRow {
    a: number | null;
    b: number | null;
    delta: number | null;
    fromData: number | null;
    fromCriteria: number | null;
    cause: { driver: 'E' | 'V' | 'C'; direction: 'up' | 'down' } | null;
    communities: number;
}

const props = defineProps<{
    fiscalYears: number[];
    yearA: number | null;
    yearB: number | null;
    result: {
        a: SideMeta;
        b: SideMeta;
        delta: number | null;
        comparable: number;
        setsDiffer: boolean;
        hazards: Record<HazardKey, HazardRow>;
        communities: Community[];
    } | null;
}>();

const oneDecimal = (v: number) => v.toFixed(1);
// Fiscal year = Oct–Sep, e.g. 2569 = ต.ค. 2568 – ก.ย. 2569.
const yearLabel = (fy: number) => `ปีงบประมาณ ${fy} (ต.ค. ${fy - 1} – ก.ย. ${fy})`;

// Criteria sets get a color each, by id order, so the same set always looks the same on the A/B cards.
const SET_BADGES = [
    'bg-sky-100 text-sky-700 ring-sky-200',
    'bg-fuchsia-100 text-fuchsia-700 ring-fuchsia-200',
    'bg-teal-100 text-teal-700 ring-teal-200',
    'bg-orange-100 text-orange-700 ring-orange-200',
];
const setIds = computed(() =>
    [
        ...new Set((props.result?.communities ?? []).flatMap((c) => [c.a?.criteriaSet.id, c.b?.criteriaSet.id]).filter((id): id is number => !!id)),
    ].sort((x, y) => x - y),
);
const setBadge = (id: number) => SET_BADGES[setIds.value.indexOf(id) % SET_BADGES.length];

// ---------- Controls ----------
const yearA = ref(props.yearA);
const yearB = ref(props.yearB);
const STABLE_OPTIONS = [0.5, 1, 2];
const stable = ref(1); // |Δ| below this counts as ทรงตัว

function load() {
    router.get(route('year-comparison.index'), { a: yearA.value, b: yearB.value }, { preserveScroll: true, preserveState: true });
}
function swap() {
    [yearA.value, yearB.value] = [yearB.value, yearA.value];
    load();
}

// ---------- Results ----------
type Status = 'down' | 'up' | 'stable' | 'stale' | 'na';
const statusMeta: Record<Status, { label: string; cls: string; icon: string }> = {
    down: { label: 'เสี่ยงลดลง', cls: 'bg-green-100 text-green-700', icon: 'fa-solid fa-arrow-down' },
    up: { label: 'เสี่ยงเพิ่มขึ้น', cls: 'bg-red-100 text-red-700', icon: 'fa-solid fa-arrow-up' },
    stable: { label: 'ทรงตัว', cls: 'bg-slate-100 text-slate-600', icon: 'fa-solid fa-minus' },
    stale: { label: 'ไม่มีข้อมูลใหม่', cls: 'bg-amber-100 text-amber-700', icon: 'fa-solid fa-hourglass-half' },
    na: { label: 'เทียบไม่ได้', cls: 'bg-slate-100 text-slate-400', icon: 'fa-solid fa-ban' },
};
const STATUS_ORDER: Status[] = ['down', 'up', 'stable', 'stale', 'na'];
// Text-only colors for the same statuses (the summary card's community line, no badges).
const statusText: Record<Status, string> = {
    down: 'text-green-700',
    up: 'text-red-600',
    stable: 'text-slate-600',
    stale: 'text-amber-700',
    na: 'text-slate-400',
};

function statusOf(c: Community): Status {
    if (!c.a || !c.b) return 'na';
    if (c.stale) return 'stale'; // same record on both sides: no new information
    const d = c.b.multi - c.a.multi;
    if (Math.abs(d) < stable.value) return 'stable';
    return d < 0 ? 'down' : 'up';
}

const rows = computed(() =>
    (props.result?.communities ?? []).map((c) => {
        const status = statusOf(c);
        // The hazard with the largest change (either direction), only when the two sides are really comparable.
        const topHazard =
            c.a && c.b && status !== 'stale'
                ? (HAZARD_KEYS.flatMap((h) =>
                      c.a!.hazards[h] !== null && c.b!.hazards[h] !== null ? [{ h, d: c.b!.hazards[h]! - c.a!.hazards[h]! }] : [],
                  ).sort((x, y) => Math.abs(y.d) - Math.abs(x.d))[0] ?? null)
                : null;
        return { ...c, status, delta: c.a && c.b ? c.b.multi - c.a.multi : null, topHazard };
    }),
);
// Δ only over communities with new data on both sides: stale ones would pull the change toward 0.
const comparable = computed(() => rows.value.filter((r) => r.status !== 'na' && r.status !== 'stale'));
const counts = computed(
    () => Object.fromEntries(STATUS_ORDER.map((s) => [s, rows.value.filter((r) => r.status === s).length])) as Record<Status, number>,
);

const cityA = computed(() => props.result?.a.multi ?? null);
const cityB = computed(() => props.result?.b.multi ?? null);
const cityDelta = computed(() => props.result?.delta ?? null);
const cityStatus = computed<Status>(() =>
    cityDelta.value === null ? 'na' : Math.abs(cityDelta.value) < stable.value ? 'stable' : cityDelta.value < 0 ? 'down' : 'up',
);
const setsDiffer = computed(() => props.result?.setsDiffer ?? false);

const causeText = {
    E: { down: 'คนที่สัมผัสภัย (E) ลดลง', up: 'คนที่สัมผัสภัย (E) เพิ่มขึ้น' },
    V: { down: 'ความเปราะบาง (V) ลดลง', up: 'ความเปราะบาง (V) เพิ่มขึ้น' },
    C: { down: 'ความพร้อม (C) เพิ่มขึ้น', up: 'ความพร้อม (C) ลดลง' },
} as const;

const hazardRows = computed(() =>
    props.result
        ? HAZARD_KEYS.map((h) => {
              const r = props.result!.hazards[h];
              return { h, ...r, cause: r.cause ? causeText[r.cause.driver][r.cause.direction] : null };
          })
        : [],
);
const maxHazard = computed(() => Math.max(50, ...hazardRows.value.flatMap((r) => [r.a ?? 0, r.b ?? 0])));

const levelUps = computed(() => comparable.value.filter((r) => levelRank(r.b!.multi) > levelRank(r.a!.multi)).length);
const levelDowns = computed(() => comparable.value.filter((r) => levelRank(r.b!.multi) < levelRank(r.a!.multi)).length);
function levelRank(v: number) {
    return ['low', 'moderate', 'high', 'very_high'].indexOf(levelOf(v));
}

// The headline rows: the hazard that fell most, and every hazard that rose beyond the tolerance.
const withDelta = computed(() => hazardRows.value.filter((r): r is typeof r & { delta: number } => r.delta !== null));
const biggestDown = computed(() => [...withDelta.value].sort((x, y) => x.delta - y.delta)[0]);
const ups = computed(() => withDelta.value.filter((r) => r.delta >= stable.value));

// Where each side's numbers come from: how many communities per data year and per criteria set.
function provenance(entries: (Side | null)[]) {
    const byYear = new Map<number, number>();
    const bySet = new Map<number, { set: { id: number; name: string }; n: number }>();
    const dates: string[] = [];
    for (const e of entries) {
        if (!e) continue;
        byYear.set(e.fiscalYear, (byYear.get(e.fiscalYear) ?? 0) + 1);
        const s = bySet.get(e.criteriaSet.id) ?? { set: e.criteriaSet, n: 0 };
        s.n++;
        bySet.set(e.criteriaSet.id, s);
        dates.push(e.dataDate);
    }
    dates.sort();
    return {
        count: entries.filter(Boolean).length,
        from: dates[0] ?? null,
        to: dates[dates.length - 1] ?? null,
        byYear: [...byYear.entries()].sort((x, y) => y[0] - x[0]),
        bySet: [...bySet.values()].sort((x, y) => x.set.id - y.set.id),
    };
}
const provA = computed(() => provenance(rows.value.map((r) => r.a)));
const provB = computed(() => provenance(rows.value.map((r) => r.b)));

// Communities table
const statusFilter = ref<Status | 'all' | 'levelChanged'>('all');
const tableRows = computed(() => {
    let list = rows.value;
    if (statusFilter.value === 'levelChanged') list = comparable.value.filter((r) => levelRank(r.a!.multi) !== levelRank(r.b!.multi));
    else if (statusFilter.value !== 'all') list = list.filter((r) => r.status === statusFilter.value);
    // "ไม่มีข้อมูลใหม่" goes last.
    const order: Status[] = ['down', 'up', 'stable', 'na', 'stale'];
    return [...list].sort((x, y) => {
        const sx = order.indexOf(x.status) - order.indexOf(y.status);
        return sx !== 0 ? sx : Math.abs(y.delta ?? 0) - Math.abs(x.delta ?? 0);
    });
});

// Risk going down is good (green); up is bad (red).
const deltaClass = (d: number | null) => (d === null || Math.abs(d) < stable.value ? 'text-slate-500' : d < 0 ? 'text-green-600' : 'text-red-600');
const signed = (d: number | null) => (d === null ? '–' : `${d > 0 ? '+' : d < 0 ? '−' : ''}${Math.abs(d).toFixed(1)}`);

const barsEl = ref<HTMLElement | null>(null);
const barsInView = useInView(barsEl, 0.4);

// Row click → a small menu to open this community on page 3 (map) or page 4 (factors) for year B. Both honor
// ?community= and ?year=. It is fixed-positioned at the click, so the table's scroll box can't clip it.
const menu = ref<{ code: string; name: string; x: number; y: number } | null>(null);
function openMenu(row: { code: string; name: string }, event: MouseEvent | KeyboardEvent) {
    if (menu.value?.code === row.code) {
        menu.value = null;
        return;
    }
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const x = event instanceof MouseEvent && event.clientX ? event.clientX : rect.left + 24;
    const y = event instanceof MouseEvent && event.clientY ? event.clientY : rect.bottom;
    // Keep the 224px-wide menu inside the viewport.
    menu.value = { code: row.code, name: row.name, x: Math.min(x, window.innerWidth - 240), y: Math.min(y + 6, window.innerHeight - 130) };
}
const closeMenu = () => (menu.value = null);
const onKey = (e: KeyboardEvent) => e.key === 'Escape' && closeMenu();
onMounted(() => {
    window.addEventListener('click', closeMenu);
    window.addEventListener('scroll', closeMenu, true);
    window.addEventListener('keydown', onKey);
});
onUnmounted(() => {
    window.removeEventListener('click', closeMenu);
    window.removeEventListener('scroll', closeMenu, true);
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Head title="เปรียบเทียบรายปี" />

    <AdminLayout title="เปรียบเทียบรายปีงบประมาณ">
        <div v-if="!result" class="rounded-xl border border-slate-200 bg-white p-10 text-center text-slate-500 shadow-sm">
            <i class="fa-solid fa-code-compare mb-3 text-3xl text-slate-300"></i>
            <p class="font-medium text-slate-700">ยังเปรียบเทียบไม่ได้</p>
            <p class="mt-1 text-sm">ต้องมีข้อมูลประเมินอย่างน้อย 2 ปีงบประมาณ (ตอนนี้มี {{ fiscalYears.length }} ปี)</p>
        </div>
        <template v-else>
            <!-- Controls (sticky) -->
            <div
                class="sticky -top-4 z-20 -mx-4 mb-5 flex flex-wrap items-end gap-3 border-b border-slate-200/70 bg-slate-50/90 px-4 py-3 backdrop-blur lg:-top-6 lg:-mx-6 lg:px-6"
            >
                <label class="flex flex-col text-xs text-slate-500">
                    ปีงบประมาณ A (ก่อน)
                    <select v-model.number="yearA" @change="load" class="mt-1 rounded-lg border-slate-300 py-1.5 text-sm text-slate-800">
                        <option v-for="fy in fiscalYears" :key="fy" :value="fy" :disabled="fy === yearB">{{ yearLabel(fy) }}</option>
                    </select>
                </label>
                <button
                    type="button"
                    class="mb-0.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-amber-800 hover:bg-amber-100"
                    title="สลับ A ↔ B"
                    @click="swap"
                >
                    <i class="fa-solid fa-right-left"></i>
                </button>
                <label class="flex flex-col text-xs text-slate-500">
                    ปีงบประมาณ B (หลัง)
                    <select v-model.number="yearB" @change="load" class="mt-1 rounded-lg border-slate-300 py-1.5 text-sm text-slate-800">
                        <option v-for="fy in fiscalYears" :key="fy" :value="fy" :disabled="fy === yearA">{{ yearLabel(fy) }}</option>
                    </select>
                </label>
                <label class="flex flex-col text-xs text-slate-500">
                    ค่าความ “ต่าง” ที่รับได้
                    <select v-model.number="stable" class="mt-1 rounded-lg border-slate-300 py-1.5 text-sm text-slate-800">
                        <option v-for="s in STABLE_OPTIONS" :key="s" :value="s">± {{ s }} คะแนน</option>
                    </select>
                </label>
            </div>

            <!-- Headline: the answer to "did risk go up or down?" -->
            <!-- Summary, laid out like the owner's reference picture (2026-11-11): the city line, then one row each for the
             hazards, the communities and the level changes. Colors follow the result: red = risk up, green = down. -->
            <section class="mb-5 rounded-2xl border border-slate-200 bg-gradient-to-br from-white via-white to-blue-50/60 p-5 shadow-sm">
                <span class="inline-flex items-center gap-2 text-xs font-medium text-slate-400">
                    <i class="fa-solid fa-chart-simple"></i>สรุป · เทียบปีงบประมาณ {{ yearA }} กับ {{ yearB }}
                </span>

                <!-- City -->
                <Reveal class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-xl shadow-sm transition-colors duration-500"
                            :class="statusMeta[cityStatus].cls"
                        >
                            <i
                                :class="
                                    cityStatus === 'up'
                                        ? 'fa-solid fa-arrow-trend-up'
                                        : cityStatus === 'down'
                                          ? 'fa-solid fa-arrow-trend-down'
                                          : statusMeta[cityStatus].icon
                                "
                            ></i>
                        </span>
                        <div>
                            <p class="text-base font-bold text-slate-800">ความเสี่ยงรวมของเมือง</p>
                            <p class="flex items-baseline gap-1.5 font-bold" :class="deltaClass(cityDelta)">
                                <span class="text-base">
                                    {{
                                        cityStatus === 'down'
                                            ? 'ลดลง'
                                            : cityStatus === 'up'
                                              ? 'เพิ่มขึ้น'
                                              : cityStatus === 'stable'
                                                ? 'ทรงตัว'
                                                : 'เทียบไม่ได้'
                                    }}
                                </span>
                                <span v-if="cityDelta !== null" class="text-3xl leading-none">
                                    <AnimatedNumber :value="Math.abs(cityDelta)" :format="oneDecimal" />
                                </span>
                                <span v-if="cityDelta !== null" class="text-base">คะแนน</span>
                            </p>
                        </div>
                    </div>
                    <span v-if="cityA !== null && cityB !== null" class="hidden h-10 w-px bg-slate-200 sm:block"></span>
                    <span v-if="cityA !== null && cityB !== null" class="inline-flex items-center gap-2 text-sm text-slate-600">
                        ระดับ
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold transition-colors duration-500"
                            :class="riskLevels[levelOf(cityA)].badge"
                            >{{ riskLevels[levelOf(cityA)].label }}</span
                        >
                        <i class="fa-solid fa-arrow-right text-xs text-slate-400"></i>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold transition-colors duration-500"
                            :class="riskLevels[levelOf(cityB)].badge"
                            >{{ riskLevels[levelOf(cityB)].label }}</span
                        >
                    </span>
                </Reveal>

                <!-- Hazards that moved: one row each, red when up, green when down -->
                <TransitionGroup tag="div" name="rank" class="mt-4 space-y-2">
                    <div
                        v-for="item in [
                            ...(biggestDown && biggestDown.delta <= -stable ? [{ ...biggestDown, tag: 'ภัยที่ลดมากที่สุด' }] : []),
                            ...ups.map((u) => ({ ...u, tag: 'ภัยที่เพิ่มขึ้น' })),
                        ]"
                        :key="item.h"
                        class="flex items-center gap-3 py-1 text-sm"
                    >
                        <i :class="[hazardIcon[item.h], item.delta < 0 ? 'text-green-600' : 'text-red-500']" class="w-5 text-center text-base"></i>
                        <p class="text-slate-700">
                            <span class="font-semibold text-slate-800">{{ item.tag }}:</span>
                            <span class="font-semibold" :class="item.delta < 0 ? 'text-green-700' : 'text-red-600'">
                                {{ hazardLabel[item.h] }} (<AnimatedNumber :value="item.delta" :format="signed" />)</span
                            >
                            <template v-if="item.cause"> ส่วนใหญ่มาจาก{{ item.cause }}</template>
                        </p>
                    </div>
                    <div
                        key="none"
                        v-if="!(biggestDown && biggestDown.delta <= -stable) && ups.length === 0"
                        class="flex items-center gap-3 py-1 text-sm text-slate-500"
                    >
                        <i class="fa-solid fa-minus w-5 text-center text-slate-400"></i>ไม่มีภัยที่เปลี่ยนเกินค่าความต่างที่รับได้
                    </div>
                </TransitionGroup>

                <!-- Communities: chips split by border-r, each in its status color -->
                <Reveal :delay="150" class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 py-1 text-sm">
                    <span class="flex items-center gap-3 font-semibold text-slate-700">
                        <i class="fa-solid fa-users w-5 text-center text-base text-blue-500"></i>ชุมชน:
                    </span>
                    <div class="flex flex-wrap items-center gap-y-1">
                        <span v-for="s in STATUS_ORDER" :key="s" class="border-r border-slate-200 px-1.5 last:border-r-0">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 px-1 text-xs hover:underline"
                                :class="statusText[s]"
                                title="แสดงชุมชนกลุ่มนี้ในตารางรายชุมชน"
                                @click="statusFilter = s"
                            >
                                <i :class="statusMeta[s].icon" class="text-[10px]"></i>{{ statusMeta[s].label }}
                                <span class="text-sm font-bold"><AnimatedNumber :value="counts[s]" /></span>
                            </button>
                        </span>
                    </div>
                </Reveal>

                <!-- Level changes -->
                <Reveal :delay="300" class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 py-1 text-sm">
                    <span class="flex items-center gap-3 font-semibold text-slate-700">
                        <i class="fa-solid fa-layer-group w-5 text-center text-base text-blue-500"></i>เปลี่ยนระดับความเสี่ยง:
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-xs text-green-700">
                        <i class="fa-solid fa-arrow-down text-[10px]"></i>ลดระดับ
                        <span class="text-sm font-bold"><AnimatedNumber :value="levelDowns" /></span> ชุมชน
                    </span>
                    <span class="text-slate-300">•</span>
                    <span class="inline-flex items-center gap-1.5 text-xs text-red-700">
                        <i class="fa-solid fa-arrow-up text-[10px]"></i>เพิ่มระดับ
                        <span class="text-sm font-bold"><AnimatedNumber :value="levelUps" /></span> ชุมชน
                    </span>
                </Reveal>

                <p class="mt-3 flex items-start gap-2 text-xs text-slate-400">
                    <i class="fa-solid fa-circle-info mt-0.5"></i>
                    <span>
                        ผลต่างคิดจาก {{ comparable.length }} ชุมชนที่มีข้อมูลใหม่ทั้งสองปี · ไม่นับ {{ counts.stale }} ชุมชนที่ไม่มีข้อมูลใหม่
                        (จะดึงผลต่างให้เข้าใกล้ 0 ทั้งที่จริงคือ “ไม่รู้”) และ {{ counts.na }} ชุมชนที่ไม่มีข้อมูลฝั่งใดฝั่งหนึ่ง
                    </span>
                </p>
            </section>

            <!-- Notices about criteria -->
            <Transition name="fade" mode="out-in">
                <div
                    v-if="setsDiffer"
                    key="differ"
                    class="mb-5 flex flex-wrap items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800"
                >
                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">สองปีนี้มีชุมชนที่คำนวณด้วยเกณฑ์คนละชุด</p>
                        <p class="text-xs">ผลต่างจึงมาจาก 2 อย่างปนกัน: ข้อมูลที่เปลี่ยน + เกณฑ์ที่เปลี่ยน (ดูการแยกส่วนในตารางรายภัย)</p>
                    </div>
                </div>
                <div
                    v-else
                    key="same"
                    class="mb-5 flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
                >
                    <i class="fa-solid fa-circle-check mt-0.5"></i>
                    <p>ชุมชนที่นำมาเทียบใช้เกณฑ์ชุดเดียวกันทั้งสองปี — ผลต่างมาจากข้อมูลที่เปลี่ยนเท่านั้น</p>
                </div>
            </Transition>

            <!-- Side A vs Side B, with where the numbers come from -->
            <section class="mb-6 grid items-stretch gap-4 lg:grid-cols-[1fr_auto_1fr]">
                <div
                    v-for="s in [
                        { tag: 'A', side: result.a, value: cityA, prov: provA },
                        { tag: 'B', side: result.b, value: cityB, prov: provB },
                    ]"
                    :key="s.tag"
                    class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                    :class="s.tag === 'B' ? 'lg:order-3' : ''"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold text-white"
                                :class="s.tag === 'A' ? 'bg-slate-400' : 'bg-blue-600'"
                                >{{ s.tag }}</span
                            >
                            <span class="font-semibold text-slate-800">{{ yearLabel(s.side.year) }}</span>
                        </span>
                        <span class="text-xs text-slate-400">ความเสี่ยงรวมทั้งเมือง</span>
                    </div>
                    <RiskGauge :value="s.value" />
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-400">ข้อมูล ณ วันที่</dt>
                            <dd class="text-right text-slate-700">{{ s.side.asOf ? formatThaiDate(s.side.asOf) : 'ข้อมูลล่าสุด' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-400">ช่วงวันที่ประเมินที่ใช้</dt>
                            <dd class="text-right text-slate-700">
                                {{ formatThaiDate(s.prov.from)
                                }}<template v-if="s.prov.from !== s.prov.to"> – {{ formatThaiDate(s.prov.to) }}</template>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-400">ข้อมูลของปีงบประมาณ</dt>
                            <dd class="text-right">
                                <span
                                    v-for="[fy, n] in s.prov.byYear"
                                    :key="fy"
                                    class="ml-1 inline-block rounded-full px-2 py-0.5 text-xs"
                                    :class="fy === s.side.year ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-700'"
                                    >{{ fy }}: {{ n }} ชุมชน</span
                                >
                                <span
                                    v-if="s.prov.count < rows.length"
                                    class="ml-1 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-400"
                                    >ไม่มีข้อมูล: {{ rows.length - s.prov.count }}</span
                                >
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-400">เกณฑ์ที่ใช้</dt>
                            <dd class="text-right">
                                <span
                                    v-for="b in s.prov.bySet"
                                    :key="b.set.id"
                                    class="mb-1 ml-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                                    :class="setBadge(b.set.id)"
                                    >{{ b.set.name }}<template v-if="s.prov.bySet.length > 1">: {{ b.n }}</template></span
                                >
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="s.prov.byYear.some(([fy]) => fy !== s.side.year)"
                        class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[11px] text-amber-700"
                    >
                        <i class="fa-solid fa-circle-info mr-1"></i>บางชุมชนไม่ได้นำเข้าข้อมูลในปีงบประมาณ {{ s.side.year }} จึงใช้ข้อมูลล่าสุดที่มี
                    </p>
                </div>

                <div class="flex flex-col items-center justify-center rounded-xl bg-slate-100 px-6 py-4 lg:order-2">
                    <span class="text-xs text-slate-500">ผลต่าง (B − A)</span>
                    <span class="mt-1 text-3xl font-bold" :class="deltaClass(cityDelta)">
                        <i v-if="cityDelta !== null" class="text-xl" :class="statusMeta[cityStatus].icon"></i>
                        <AnimatedNumber :value="cityDelta === null ? null : Math.abs(cityDelta)" :format="oneDecimal" />
                    </span>
                    <span class="mt-1 rounded-full px-2 py-0.5 text-xs" :class="statusMeta[cityStatus].cls">{{ statusMeta[cityStatus].label }}</span>
                </div>
            </section>

            <!-- Hazards -->
            <section ref="barsEl" class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold text-slate-800">รายภัย (เฉพาะ {{ comparable.length }} ชุมชนที่เทียบได้)</h2>
                    <div class="flex items-center gap-3 text-xs text-slate-500">
                        <span class="flex items-center gap-1"><span class="h-2.5 w-4 rounded-sm bg-slate-300"></span>A {{ yearA }}</span>
                        <span class="flex items-center gap-1"><span class="h-2.5 w-4 rounded-sm bg-blue-500"></span>B {{ yearB }}</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-left text-xs text-slate-400">
                                <th class="py-2 pr-3 font-medium">ภัย</th>
                                <th class="w-[30%] py-2 pr-3 font-medium"></th>
                                <th class="py-2 pr-3 text-right font-medium">A</th>
                                <th class="py-2 pr-3 text-right font-medium">B</th>
                                <th class="py-2 pr-3 text-right font-medium">Δ</th>
                                <th v-if="setsDiffer" class="py-2 pr-3 text-right font-medium">แยกส่วน Δ</th>
                                <th class="py-2 font-medium">สาเหตุหลัก</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in hazardRows" :key="row.h" class="border-b border-slate-50">
                                <td class="whitespace-nowrap py-2.5 pr-3 text-slate-700">
                                    <i :class="hazardIcon[row.h]" class="mr-1.5 w-4 text-slate-400"></i>{{ hazardLabel[row.h] }}
                                </td>
                                <td class="py-2.5 pr-3">
                                    <div class="space-y-1">
                                        <div class="h-2 rounded-full bg-slate-100">
                                            <div
                                                class="h-2 rounded-full bg-slate-300 transition-all duration-700"
                                                :style="{ width: barsInView ? `${((row.a ?? 0) / maxHazard) * 100}%` : '0%' }"
                                            ></div>
                                        </div>
                                        <div class="h-2 rounded-full bg-slate-100">
                                            <div
                                                class="h-2 rounded-full bg-blue-500 transition-all duration-700"
                                                :style="{ width: barsInView ? `${((row.b ?? 0) / maxHazard) * 100}%` : '0%' }"
                                            ></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2.5 pr-3 text-right text-slate-500"><AnimatedNumber :value="row.a" :format="oneDecimal" /></td>
                                <td class="py-2.5 pr-3 text-right font-medium text-slate-800">
                                    <AnimatedNumber :value="row.b" :format="oneDecimal" />
                                </td>
                                <td class="whitespace-nowrap py-2.5 pr-3 text-right font-semibold" :class="deltaClass(row.delta)">
                                    {{ signed(row.delta) }}
                                </td>
                                <td v-if="setsDiffer" class="whitespace-nowrap py-2.5 pr-3 text-right text-xs">
                                    <span class="text-slate-500">ข้อมูล</span>
                                    <span class="font-medium" :class="deltaClass(row.fromData)"> {{ signed(row.fromData) }}</span>
                                    <span class="mx-1 text-slate-300">|</span>
                                    <span class="text-slate-500">เกณฑ์</span>
                                    <span class="font-medium text-fuchsia-600"> {{ signed(row.fromCriteria) }}</span>
                                </td>
                                <td class="py-2.5 text-xs text-slate-500">
                                    {{ row.delta === null || Math.abs(row.delta) < stable ? '–' : (row.cause ?? '–') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="setsDiffer" class="mt-3 text-xs text-slate-400">
                    วิธีอ่าน: ถ้า Δ รวมดูเหมือนแทบไม่ลด แต่ช่อง “ข้อมูล” ติดลบ แปลว่าพื้นที่ดีขึ้นจริง เพียงแต่เกณฑ์ใหม่ให้คะแนนเข้มขึ้น
                </p>
            </section>

            <!-- Communities -->
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold text-slate-800">รายชุมชน</h2>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        <button
                            type="button"
                            class="rounded-full px-3 py-1 ring-1"
                            :class="statusFilter === 'all' ? 'bg-blue-600 text-white ring-blue-600' : 'bg-white text-slate-600 ring-slate-200'"
                            @click="statusFilter = 'all'"
                        >
                            ทั้งหมด {{ rows.length }}
                        </button>
                        <button
                            v-for="s in STATUS_ORDER"
                            :key="s"
                            type="button"
                            class="rounded-full px-3 py-1 ring-1"
                            :class="statusFilter === s ? 'bg-blue-600 text-white ring-blue-600' : 'bg-white text-slate-600 ring-slate-200'"
                            @click="statusFilter = s"
                        >
                            {{ statusMeta[s].label }} {{ counts[s] }}
                        </button>
                        <button
                            type="button"
                            class="rounded-full px-3 py-1 ring-1"
                            :class="
                                statusFilter === 'levelChanged' ? 'bg-blue-600 text-white ring-blue-600' : 'bg-white text-slate-600 ring-slate-200'
                            "
                            @click="statusFilter = 'levelChanged'"
                        >
                            เปลี่ยนระดับ {{ levelUps + levelDowns }}
                        </button>
                    </div>
                </div>
                <div class="max-h-[520px] overflow-auto">
                    <table class="w-full min-w-[860px] text-sm">
                        <thead class="sticky top-0 z-10 bg-white">
                            <tr class="border-b border-slate-200 text-left text-xs text-slate-400">
                                <th class="py-2.5 pr-3 font-medium">ชุมชน</th>
                                <th
                                    v-for="h in [
                                        { tag: 'A', year: yearA },
                                        { tag: 'B', year: yearB },
                                    ]"
                                    :key="h.tag"
                                    class="py-2.5 pr-3 font-medium"
                                >
                                    <span class="flex items-center gap-1.5 text-slate-600">
                                        <span
                                            class="flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold text-white"
                                            :class="h.tag === 'A' ? 'bg-slate-400' : 'bg-blue-600'"
                                            >{{ h.tag }}</span
                                        >
                                        ปีงบประมาณ {{ h.year }}
                                    </span>
                                    <span class="mt-0.5 block text-[10px] font-normal">ต.ค. {{ h.year - 1 }} – ก.ย. {{ h.year }}</span>
                                </th>
                                <th class="py-2.5 pr-3 text-right font-medium">Δ</th>
                                <th class="py-2.5 pr-3 font-medium">ผล</th>
                                <th class="py-2.5 font-medium">ภัยที่เปลี่ยนมากสุด</th>
                            </tr>
                        </thead>
                        <TransitionGroup tag="tbody" name="rank">
                            <tr
                                v-for="row in tableRows"
                                :key="row.code"
                                tabindex="0"
                                class="cursor-pointer border-b border-slate-100 align-top hover:bg-blue-50/50 focus:bg-blue-50/50 focus:outline-none"
                                :class="{ 'bg-blue-50/70': menu?.code === row.code }"
                                @click.stop="openMenu(row, $event)"
                                @keydown.enter.stop="openMenu(row, $event)"
                            >
                                <td class="py-3 pr-3">
                                    <span class="font-medium text-slate-800">{{ row.name }}</span>
                                </td>
                                <td v-for="(e, k) in [row.a, row.b]" :key="k" class="py-3 pr-3">
                                    <!-- Case 1: no import in year B, so B would only repeat A's numbers. Say so instead. -->
                                    <span
                                        v-if="k === 1 && row.status === 'stale'"
                                        class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                                    >
                                        <i class="fa-solid fa-hourglass-half text-[9px]"></i>ไม่มีข้อมูลปีนี้
                                    </span>
                                    <template v-else-if="e">
                                        <div class="flex items-center gap-1.5">
                                            <span
                                                class="text-base tabular-nums leading-none"
                                                :class="k ? 'font-semibold text-slate-800' : 'font-medium text-slate-500'"
                                                >{{ e.multi.toFixed(1) }}</span
                                            >
                                            <span class="rounded-full px-2 py-0.5 text-[11px]" :class="riskLevels[levelOf(e.multi)].badge">{{
                                                riskLevels[levelOf(e.multi)].label
                                            }}</span>
                                        </div>
                                        <!-- Only when the data year differs from the column's year (no import that year, an older
                                         year stands in): that year in amber, like the card's amber year chip. -->
                                        <div
                                            v-if="e.fiscalYear !== (k ? yearB : yearA)"
                                            class="mt-1 text-[11px] font-medium text-amber-600"
                                            :title="`ไม่มีข้อมูลปี ${k ? yearB : yearA} ใช้ข้อมูลปี ${e.fiscalYear} แทน`"
                                        >
                                            <i class="fa-solid fa-clock-rotate-left mr-0.5"></i>{{ e.fiscalYear }}
                                        </div>
                                    </template>
                                    <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-400">ไม่มีข้อมูล</span>
                                </td>
                                <td class="whitespace-nowrap py-3 pr-3 text-right">
                                    <span class="text-base font-semibold" :class="deltaClass(row.delta)">{{
                                        row.delta === null || row.status === 'stale' ? '–' : signed(row.delta)
                                    }}</span>
                                </td>
                                <td class="py-3 pr-3">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]"
                                        :class="statusMeta[row.status].cls"
                                    >
                                        <i :class="statusMeta[row.status].icon" class="text-[9px]"></i>{{ statusMeta[row.status].label }}
                                    </span>
                                </td>
                                <!-- The hazard that moved most for this community (the "why" behind its Δ), one small line. -->
                                <td class="whitespace-nowrap py-3 text-[11px] text-slate-500">
                                    <template v-if="row.topHazard">
                                        <i :class="hazardIcon[row.topHazard.h]" class="mr-0.5 text-slate-400"></i>{{ hazardLabel[row.topHazard.h] }}
                                        <span class="font-semibold" :class="deltaClass(row.topHazard.d)">{{ signed(row.topHazard.d) }}</span>
                                    </template>
                                    <span v-else class="text-slate-400">–</span>
                                </td>
                            </tr>
                        </TransitionGroup>
                    </table>
                    <p v-if="tableRows.length === 0" class="py-8 text-center text-sm text-slate-400">ไม่มีชุมชนในกลุ่มนี้</p>
                </div>
                <p class="mt-2 text-[11px] text-slate-400">
                    <i class="fa-solid fa-hand-pointer mr-1"></i>คลิกชุมชนเพื่อดูบนแผนที่หรือดูปัจจัยความเสี่ยง
                </p>
            </section>

            <Teleport to="body">
                <Transition name="fade">
                    <div
                        v-if="menu"
                        class="fixed z-[90] w-56 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-xl"
                        :style="{ left: `${menu.x}px`, top: `${menu.y}px` }"
                        @click.stop
                    >
                        <p class="truncate border-b border-slate-100 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">{{ menu.name }}</p>
                        <Link
                            :href="route('community-map.index', { community: menu.code, year: yearB })"
                            class="flex items-center gap-2.5 px-3 py-2.5 text-slate-700 hover:bg-blue-50 hover:text-blue-700"
                        >
                            <i class="fa-solid fa-map w-4 text-center text-slate-400"></i>ดูบนแผนที่
                        </Link>
                        <Link
                            :href="route('risk-factors.index', { community: menu.code, year: yearB })"
                            class="flex items-center gap-2.5 px-3 py-2.5 text-slate-700 hover:bg-blue-50 hover:text-blue-700"
                        >
                            <i class="fa-solid fa-triangle-exclamation w-4 text-center text-slate-400"></i>ดูปัจจัยความเสี่ยง
                        </Link>
                    </div>
                </Transition>
            </Teleport>
        </template>
    </AdminLayout>
</template>

<style scoped>
.rank-move,
.rank-enter-active {
    transition: all 0.5s ease;
}
.rank-enter-from {
    opacity: 0;
    transform: translateX(16px);
}
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.25s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .rank-move,
    .rank-enter-active,
    .fade-enter-active,
    .fade-leave-active {
        transition: none;
    }
}
</style>
