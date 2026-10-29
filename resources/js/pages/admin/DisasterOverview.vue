<script setup lang="ts">
import BarChart from '@/components/admin/BarChart.vue';
import DonutChart from '@/components/admin/DonutChart.vue';
import { communityZones } from '@/data/communityZones';
import {
    hazardBadgeClass,
    hazardIcons,
    hazardOrder,
    hazardShortLabel,
    riskLevelFromScore,
    riskLevelMeta,
    seededValue,
    type RiskLevel,
} from '@/data/hazardMeta';
import { MAP_CENTER, MAP_ZOOM, MUNICIPALITY_BOUNDARY, communities, riskColors, type Community } from '@/data/mahasarakham';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import L from 'leaflet';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';

// Fully mock rollup — there's no hazard_assessments table yet (see CLAUDE.md), so every figure here is
// derived deterministically from the real 32-community list in data/mahasarakham.ts via seededValue().
// POPULATION/HOUSEHOLDS mirror the "Meta" input group ข้อมูลชุมชน owns (a separate mock island there, not
// re-imported — this page doesn't need them to match exactly, just to be the same realistic shape).
// ~1/3 of communities are seeded as "ยังไม่ประเมิน" (assessed = false) — no per-hazard sheet has been
// imported for them yet — to demonstrate the data-completeness tracking this rollup page exists for.
// RECOVERY_AVG/L3_DAMAGE_TOTAL_THB come from the เหตุการณ์ L&D register, not the per-hazard assessment, so
// they stay populated even for unassessed communities (matches the real ภัยพิบัติรวม sheet: Recovery/L&D
// are independent column groups from Hazard/Exposure/Vulnerability).

type OverallLevel = RiskLevel | 'incomplete';

const overallLevelMeta: Record<OverallLevel, { label: string; color: string }> = {
    ...riskLevelMeta,
    incomplete: { label: 'ข้อมูลไม่ครบ', color: '#94a3b8' },
};

interface OverviewRow {
    id: number;
    name: string;
    activeRisk: Community['activeRisk'];
    population: number;
    households: number;
    assessed: boolean;
    hazardHevc: Partial<Record<Community['activeRisk'], number>>;
    floodHevc: number | null;
    multiHazardHevc: number | null;
    overallLevel: RiskLevel | null;
    recoveryAvg: number;
    l3DamageTotal: number;
}

function communityCode(id: number) {
    return `R${String(id).padStart(3, '0')}`;
}

const overviewRows = computed<OverviewRow[]>(() =>
    communities.map((c) => {
        const assessed = seededValue(c.id + 0.15) >= 32; // ~68% of communities have at least one hazard sheet imported
        const hazardHevc: Partial<Record<Community['activeRisk'], number>> = {};
        let floodHevc: number | null = null;
        let multiHazardHevc: number | null = null;

        if (assessed) {
            const scores = hazardOrder.map((_, i) => Math.round(seededValue(c.id * 7 + i * 3 + 1) * 0.5 * 10) / 10);
            hazardOrder.forEach((hazard, i) => (hazardHevc[hazard] = scores[i]));
            floodHevc = hazardHevc.flood ?? scores[0];
            multiHazardHevc = Math.max(...scores);
        }

        return {
            id: c.id,
            name: c.name,
            activeRisk: c.activeRisk,
            population: Math.round(600 + seededValue(c.id + 0.3) * 32),
            households: Math.round(200 + seededValue(c.id + 0.6) * 10.5),
            assessed,
            hazardHevc,
            floodHevc,
            multiHazardHevc,
            overallLevel: multiHazardHevc !== null ? riskLevelFromScore(multiHazardHevc) : null,
            recoveryAvg: Math.round((seededValue(c.id + 0.45) / 20) * 100) / 100,
            l3DamageTotal: Math.round((200_000 + seededValue(c.id + 0.9) * 88_000) / 10_000) * 10_000,
        };
    }),
);

const assessedRows = computed(() => overviewRows.value.filter((r) => r.assessed));

// ── Stat row ──
const totalCommunities = communities.length;
const totalPopulation = computed(() => overviewRows.value.reduce((sum, r) => sum + r.population, 0));
const totalHouseholds = computed(() => overviewRows.value.reduce((sum, r) => sum + r.households, 0));
const avgHevc = computed(() =>
    assessedRows.value.length
        ? (assessedRows.value.reduce((sum, r) => sum + (r.multiHazardHevc ?? 0), 0) / assessedRows.value.length).toFixed(1)
        : '–',
);
const avgRecovery = computed(() => (overviewRows.value.reduce((sum, r) => sum + r.recoveryAvg, 0) / overviewRows.value.length).toFixed(2));
const totalDamage = computed(() => (overviewRows.value.reduce((sum, r) => sum + r.l3DamageTotal, 0) / 1_000_000).toFixed(2));

const dominantHazardKey = computed<Community['activeRisk']>(() => {
    const counts: Partial<Record<Community['activeRisk'], number>> = {};
    communities.forEach((c) => {
        if (c.activeRisk === 'none') return;
        counts[c.activeRisk] = (counts[c.activeRisk] ?? 0) + 1;
    });
    let best: Community['activeRisk'] = 'flood';
    let bestCount = -1;
    (Object.entries(counts) as [Community['activeRisk'], number][]).forEach(([key, count]) => {
        if (count > bestCount) {
            best = key;
            bestCount = count;
        }
    });
    return best;
});

// งบเฉลี่ย 3 ปี — Budget & Governance group (F_BUDGET_AVG_3Y), independent per-community mock figure.
const avgBudget3y = computed(() => {
    const perCommunity = communities.map((c) => 500_000 + seededValue(c.id + 0.75) * 25_000);
    return (perCommunity.reduce((sum, v) => sum + v, 0) / perCommunity.length / 1_000_000).toFixed(3);
});

// A compact tile, not the spacious components/admin/StatCard.vue (built for 3-4-per-row layouts) — 8
// stats need to sit in one row here, so this trades StatCard's icon size/padding/"ดูรายละเอียด" footer
// for a denser card instead of fighting StatCard's fixed sizing with wrapper classes.
interface StatTile {
    label: string;
    value: string | number;
    unit?: string;
    icon: string;
    iconBg: string;
    iconColor: string;
}

const statTiles = computed<StatTile[]>(() => [
    { label: 'จำนวนชุมชน', value: totalCommunities, unit: 'ชุมชน', icon: 'fa-solid fa-users', iconBg: 'bg-blue-50', iconColor: 'text-blue-600' },
    {
        label: 'ประชากรรวม',
        value: totalPopulation.value.toLocaleString('th-TH'),
        unit: 'คน',
        icon: 'fa-solid fa-people-group',
        iconBg: 'bg-cyan-50',
        iconColor: 'text-cyan-600',
    },
    {
        label: 'ครัวเรือนรวม',
        value: totalHouseholds.value.toLocaleString('th-TH'),
        unit: 'ครัวเรือน',
        icon: 'fa-solid fa-house',
        iconBg: 'bg-indigo-50',
        iconColor: 'text-indigo-600',
    },
    {
        label: 'HEVC เฉลี่ย',
        value: avgHevc.value,
        unit: 'คะแนน',
        icon: 'fa-solid fa-gauge-high',
        iconBg: 'bg-purple-50',
        iconColor: 'text-purple-600',
    },
    {
        label: 'Recovery เฉลี่ย',
        value: avgRecovery.value,
        unit: 'คะแนน',
        icon: 'fa-solid fa-rotate',
        iconBg: 'bg-emerald-50',
        iconColor: 'text-emerald-600',
    },
    {
        label: 'ความเสียหายรวม',
        value: totalDamage.value,
        unit: 'ล้านบาท',
        icon: 'fa-solid fa-coins',
        iconBg: 'bg-amber-50',
        iconColor: 'text-amber-600',
    },
    {
        label: 'ภัยเด่น',
        value: hazardShortLabel[dominantHazardKey.value],
        icon: hazardIcons[dominantHazardKey.value],
        iconBg: hazardBadgeClass[dominantHazardKey.value].bg,
        iconColor: hazardBadgeClass[dominantHazardKey.value].color,
    },
    {
        label: 'งบเฉลี่ย 3 ปี',
        value: avgBudget3y.value,
        unit: 'ล้านบาท',
        icon: 'fa-solid fa-scale-balanced',
        iconBg: 'bg-slate-100',
        iconColor: 'text-slate-600',
    },
]);

// ── สัดส่วนระดับความเสี่ยง ──
const riskLevelSegments = computed(() => {
    const counts: Record<OverallLevel, number> = { low: 0, medium: 0, high: 0, critical: 0, incomplete: 0 };
    overviewRows.value.forEach((row) => {
        counts[row.overallLevel ?? 'incomplete'] += 1;
    });
    return (Object.keys(overallLevelMeta) as OverallLevel[])
        .filter((level) => counts[level] > 0)
        .map((level) => ({ label: overallLevelMeta[level].label, value: counts[level], color: overallLevelMeta[level].color }));
});

// ── คะแนนรายภัยเฉลี่ย — average across assessed communities, per hazard column ──
const perHazardBars = computed(() =>
    hazardOrder.map((key) => {
        const values = assessedRows.value.map((r) => r.hazardHevc[key]).filter((v): v is number => v !== undefined);
        const avg = values.length ? values.reduce((sum, v) => sum + v, 0) / values.length : 0;
        return { label: hazardShortLabel[key], value: Math.round(avg * 10) / 10, color: riskColors[key] };
    }),
);

// ── ชุมชนเสี่ยงสูงสุด ──
const topRiskCommunities = computed(() => [...assessedRows.value].sort((a, b) => (b.multiHazardHevc ?? 0) - (a.multiHazardHevc ?? 0)).slice(0, 5));

// ── โครงสร้างข้อมูลในแผ่นภัยพิบัติรวม — the sheet's real column groups (see CLAUDE.md's HEVC data model
// note), shown as a static field-dictionary reference below the main table. ──
const dataGroups = [
    {
        key: 'meta',
        label: 'ข้อมูลพื้นฐาน',
        icon: 'fa-solid fa-users',
        color: '#2563eb',
        fields: ['ASSESSMENT_DATE', 'COMMUNITY_ID', 'COMMUNITY_NAME', 'TAMBON, AMPHOE'],
    },
    {
        key: 'vulnerability',
        label: 'Vulnerability ร่วม',
        icon: 'fa-solid fa-triangle-exclamation',
        color: '#f97316',
        fields: ['V_ELDERLY', 'V_CHILD', 'V_BEDRIDDEN_DISABLED', 'V_LOW_INCOME'],
    },
    {
        key: 'capacity',
        label: 'Capacity',
        icon: 'fa-solid fa-shield-halved',
        color: '#8b5cf6',
        fields: ['C1–C8 social, physical,', 'operational, network'],
    },
    { key: 'recovery', label: 'Recovery ฐาน', icon: 'fa-solid fa-rotate', color: '#0ea5e9', fields: ['R1–R5 และ', 'R_SHARED_SCORE'] },
    {
        key: 'budget',
        label: 'Budget & Governance',
        icon: 'fa-solid fa-landmark',
        color: '#2563eb',
        fields: ['F_BUDGET_AVG_3Y', 'M_MCR2030_COVERAGE', 'M_ESSENTIAL_GAPS'],
    },
    {
        key: 'ld',
        label: 'Loss & Damage รวม',
        icon: 'fa-solid fa-house-chimney-crack',
        color: '#ef4444',
        fields: ['L_EVENT_COUNT', 'L1–L5, POLICY_FLAG,', 'NOTES'],
    },
];

// ── ตารางข้อมูลภัยพิบัติรวมระดับชุมชน — filters + client-side pagination (10 rows/page, same convention
// as ข้อมูลชุมชน) ──
const filterLevel = ref<'all' | OverallLevel>('all');
const filterHazard = ref<'all' | Community['activeRisk']>('all');
const searchQuery = ref('');

const filteredRows = computed(() =>
    overviewRows.value.filter((row) => {
        if (filterLevel.value !== 'all' && (row.overallLevel ?? 'incomplete') !== filterLevel.value) return false;
        if (filterHazard.value !== 'all' && row.activeRisk !== filterHazard.value) return false;
        if (searchQuery.value && !row.name.toLowerCase().includes(searchQuery.value.toLowerCase())) return false;
        return true;
    }),
);

const currentPage = ref(1);
const pageSize = 10;
const totalPages = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize)));
const pagedRows = computed(() => {
    const start = (currentPage.value - 1) * pageSize;
    return filteredRows.value.slice(start, start + pageSize);
});
function goToPage(page: number) {
    currentPage.value = Math.min(Math.max(page, 1), totalPages.value);
}
watch([filterLevel, filterHazard, searchQuery], () => {
    currentPage.value = 1;
});

// ── ตาราง ⇄ ผังพื้นที่ toggle — the zone view reuses the same real community zones (communityZones.ts)
// as the Dashboard risk map, with the same light/gray CartoDB basemap Dashboard.vue's "ขาวดำ" style uses (a
// muted basemap so the risk-colored zone fills stay the visual focus, same reasoning as any choropleth).
// Still gets Leaflet's projection math and pan/zoom/popups for free — see the "Map pages" note in
// CLAUDE.md for why the map instance is a plain module-scope variable, not a ref. ──
const viewMode = ref<'table' | 'map'>('table');
let zoneMap: L.Map | null = null;
const zoneMapEl = ref<HTMLDivElement | null>(null);

function initZoneMap() {
    if (!zoneMapEl.value || zoneMap) return;

    zoneMap = L.map(zoneMapEl.value, { scrollWheelZoom: false }).setView(MAP_CENTER, MAP_ZOOM);

    // CartoDB's basemaps.cartocdn.com now requires an API key (see Welcome.vue's initMap() note) —
    // Esri's free no-key light-gray basemap replaces it.
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, FAO, NOAA, USGS',
        maxZoom: 19,
    }).addTo(zoneMap);

    L.polygon(MUNICIPALITY_BOUNDARY, { color: '#94a3b8', weight: 2, dashArray: '6 4', fill: false, interactive: false }).addTo(zoneMap);

    const rowById = new Map(overviewRows.value.map((r) => [r.id, r]));

    communityZones.forEach((zone) => {
        if (zone.polygon.length === 0) return;
        const row = rowById.get(zone.id);
        const level = row?.overallLevel ?? 'incomplete';
        const meta = overallLevelMeta[level];
        const scoreLine =
            row?.multiHazardHevc !== null && row?.multiHazardHevc !== undefined ? `HEVC ${row.multiHazardHevc} · ระดับ${meta.label}` : meta.label;
        const popupHtml = `
            <div class="min-w-[150px] pb-1 text-center font-sans">
                <h4 class="mb-1 text-sm font-bold text-slate-800">${zone.name}</h4>
                <span class="inline-block rounded px-2 py-1 text-[10px] font-semibold" style="background-color:${meta.color}1a;color:${meta.color}">
                    ${scoreLine}
                </span>
            </div>
        `;
        L.polygon(zone.polygon, { color: meta.color, weight: 1, fillColor: meta.color, fillOpacity: 0.55 }).bindPopup(popupHtml).addTo(zoneMap!);
    });

    zoneMap.fitBounds(L.polygon(MUNICIPALITY_BOUNDARY).getBounds(), { padding: [10, 10] });
}

function destroyZoneMap() {
    zoneMap?.remove();
    zoneMap = null;
}

watch(viewMode, async (mode) => {
    if (mode === 'map') {
        await nextTick();
        initZoneMap();
    } else {
        destroyZoneMap();
    }
});

onUnmounted(destroyZoneMap);
</script>

<template>
    <Head title="ภัยพิบัติรวม" />

    <AdminLayout title="ภัยพิบัติรวม">
        <div class="mb-6">
            <h1 class="text-lg font-bold text-slate-800">ภัยพิบัติรวม</h1>
            <p class="mt-1 text-sm text-slate-500">ข้อมูลกลางระดับชุมชนสำหรับสรุป HEVC หลายภัย, Capacity, Recovery และ Loss & Damage</p>
        </div>

        <!-- Stat row -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
            <div v-for="stat in statTiles" :key="stat.label" class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs" :class="[stat.iconBg, stat.iconColor]">
                        <i :class="stat.icon"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">{{ stat.label }}</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ stat.value }}</span>
                    <span v-if="stat.unit" class="shrink-0 text-[10px] text-slate-400">{{ stat.unit }}</span>
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <!-- ตารางข้อมูลภัยพิบัติรวมระดับชุมชน -->
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
                        <h2 class="font-semibold text-slate-800">ตารางข้อมูลภัยพิบัติรวมระดับชุมชน</h2>
                        <div class="flex items-center gap-2">
                            <div class="flex items-center rounded-lg border border-slate-200 bg-slate-100/80 p-0.5">
                                <button
                                    type="button"
                                    @click="viewMode = 'table'"
                                    class="flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-semibold transition-all"
                                    :class="viewMode === 'table' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                >
                                    <i class="fa-solid fa-table-list"></i>
                                    ตาราง
                                </button>
                                <button
                                    type="button"
                                    @click="viewMode = 'map'"
                                    class="flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-semibold transition-all"
                                    :class="viewMode === 'map' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                >
                                    <i class="fa-solid fa-draw-polygon"></i>
                                    ผังพื้นที่
                                </button>
                            </div>
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50"
                            >
                                <i class="fa-solid fa-download"></i>
                                ส่งออก
                            </button>
                        </div>
                    </div>

                    <div v-if="viewMode === 'table'" class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4">
                        <select
                            v-model="filterLevel"
                            class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs text-slate-600 focus:border-blue-500 focus:bg-white focus:outline-none"
                        >
                            <option value="all">ระดับความเสี่ยง: ทั้งหมด</option>
                            <option v-for="(meta, level) in overallLevelMeta" :key="level" :value="level">{{ meta.label }}</option>
                        </select>
                        <select
                            v-model="filterHazard"
                            class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs text-slate-600 focus:border-blue-500 focus:bg-white focus:outline-none"
                        >
                            <option value="all">ภัยเด่น: ทั้งหมด</option>
                            <option v-for="hazard in hazardOrder" :key="hazard" :value="hazard">{{ hazardShortLabel[hazard] }}</option>
                        </select>
                        <div class="relative min-w-[160px] flex-1">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-300"></i>
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="ค้นหาชุมชน..."
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-8 pr-3 text-xs text-slate-600 focus:border-blue-500 focus:bg-white focus:outline-none"
                            />
                        </div>
                    </div>

                    <!-- Table view -->
                    <template v-if="viewMode === 'table'">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th class="px-4 py-2.5 font-medium">COMMUNITY_ID</th>
                                        <th class="px-4 py-2.5 font-medium">COMMUNITY_NAME</th>
                                        <th class="px-4 py-2.5 font-medium">POPULATION</th>
                                        <th class="px-4 py-2.5 font-medium">HOUSEHOLDS</th>
                                        <th class="px-4 py-2.5 font-medium">FLOOD_HEVC</th>
                                        <th class="px-4 py-2.5 font-medium">MULTI_HAZARD_HEVC</th>
                                        <th class="px-4 py-2.5 font-medium">OVERALL_RISK_LEVEL</th>
                                        <th class="px-4 py-2.5 font-medium">RECOVERY_AVG</th>
                                        <th class="px-4 py-2.5 font-medium">L3_DAMAGE_TOTAL_THB</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="row in pagedRows" :key="row.id" class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-blue-600">{{ communityCode(row.id) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-700">{{ row.name }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-500">{{ row.population.toLocaleString('th-TH') }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-500">{{ row.households.toLocaleString('th-TH') }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-500">{{ row.floodHevc ?? '–' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-700">{{ row.multiHazardHevc ?? '–' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5">
                                            <span
                                                v-if="row.overallLevel"
                                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                :style="{
                                                    backgroundColor: riskLevelMeta[row.overallLevel].color + '1a',
                                                    color: riskLevelMeta[row.overallLevel].color,
                                                }"
                                            >
                                                {{ riskLevelMeta[row.overallLevel].label }}
                                            </span>
                                            <span v-else class="text-slate-300">–</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-500">{{ row.recoveryAvg.toFixed(2) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-500">{{ row.l3DamageTotal.toLocaleString('th-TH') }}</td>
                                    </tr>
                                    <tr v-if="pagedRows.length === 0">
                                        <td colspan="9" class="px-4 py-8 text-center text-sm text-slate-400">ไม่พบชุมชนที่ตรงกับตัวกรอง</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                            <span>
                                แสดง {{ filteredRows.length === 0 ? 0 : (currentPage - 1) * pageSize + 1 }}-{{
                                    Math.min(currentPage * pageSize, filteredRows.length)
                                }}
                                จาก {{ filteredRows.length }} รายการ
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
                    </template>

                    <!-- Zone view — Leaflet with a basemap TileLayer, see the note above the viewMode ref -->
                    <div v-else class="p-4">
                        <p class="mb-3 text-[11px] text-slate-500">สีแสดงระดับความเสี่ยงรวม</p>
                        <div ref="zoneMapEl" class="h-[420px] w-full rounded-xl bg-slate-50"></div>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <span v-for="(meta, level) in overallLevelMeta" :key="level" class="flex items-center gap-1.5 text-[11px] text-slate-500">
                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: meta.color }"></span>
                                {{ meta.label }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- โครงสร้างข้อมูลในแผ่นภัยพิบัติรวม — field-dictionary reference, stacked under the table
                     instead of a separate full-width row, so the left column's height keeps pace with the
                     right sidebar instead of leaving the table card stretched with empty space. -->
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-semibold text-slate-800">โครงสร้างข้อมูลในแผ่นภัยพิบัติรวม</h2>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-6">
                        <div v-for="g in dataGroups" :key="g.key" class="rounded-xl border border-slate-100 p-4">
                            <span
                                class="mb-2 flex h-9 w-9 items-center justify-center rounded-lg"
                                :style="{ backgroundColor: g.color + '1a', color: g.color }"
                            >
                                <i :class="g.icon"></i>
                            </span>
                            <p class="text-sm font-semibold text-slate-800">{{ g.label }}</p>
                            <ul class="mt-2 space-y-1 text-[11px] text-slate-500">
                                <li v-for="f in g.fields" :key="f">{{ f }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right column -->
            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-semibold text-slate-800">ชุมชนเสี่ยงสูงสุด</h2>
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-slate-400">
                            <tr>
                                <th class="pb-2 font-medium">อันดับ</th>
                                <th class="pb-2 font-medium">ชุมชน</th>
                                <th class="pb-2 text-right font-medium">คะแนนรวมหลายภัย (HEVC)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(row, i) in topRiskCommunities" :key="row.id">
                                <td class="py-2 text-slate-400">{{ i + 1 }}</td>
                                <td class="py-2 font-medium text-slate-700">{{ row.name }}</td>
                                <td
                                    class="py-2 text-right font-semibold"
                                    :style="{ color: row.overallLevel ? riskLevelMeta[row.overallLevel].color : '#94a3b8' }"
                                >
                                    {{ row.multiHazardHevc }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-semibold text-slate-800">สัดส่วนระดับความเสี่ยง</h2>
                    <DonutChart :segments="riskLevelSegments" center-label="ชุมชน" />
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-semibold text-slate-800">คะแนนรายภัยเฉลี่ย</h2>
                    <BarChart :bars="perHazardBars" :max="100" />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
