<script setup lang="ts">
import BarChart from '@/components/admin/BarChart.vue';
import { communityZones } from '@/data/communityZones';
import { hazardBadgeClass, hazardIcons, hazardOrder, hazardShortLabel, riskLevelMeta, seededValue, type RiskLevel } from '@/data/hazardMeta';
import { MAP_CENTER, MAP_ZOOM, MUNICIPALITY_BOUNDARY, communities, type Community } from '@/data/mahasarakham';
import { events, type VerificationStatus } from '@/data/mockEvents';
import { calculateHevc, calculateRecoveryScore, criteriaList, defaultCriteria } from '@/data/mockScoringCriteria';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import L from 'leaflet';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';

// Mock "แผ่นรายภัย" workbench, redesigned per a `04 (2).png` UI reference the project owner supplied — the
// old two-mode (ทุกชุมชน / เฉพาะชุมชน) click-through toggle is gone; the bulk table and the single-community
// breakdown now render together on one page, switched only by which community is picked in "เลือกชุมชน".
// No backend yet — everything below is derived deterministically via seededValue() from the real
// community list, matching the field groups documented under "HEVC data model" in CLAUDE.md for the 5
// per-hazard sheets (H, E, V_system, hazard-specific equipment/Capacity, R_mode/override, governance).
// H/E/V/C are always computed from baseline/system data (no manual override — that's the real workbook's
// intent: these come from GIS/census/system feeds); Recovery (R1-R5) is the one dimension a user can
// still USE_SHARED vs OVERRIDE, since Recovery is human-observed per event and may need correcting.
// Loss & Damage / Governance fields (SOURCE/EVIDENCE/DATA_DATE/VERIFICATION_STATUS/L1/L3) are pulled live
// from the shared `events` store (data/mockEvents.ts) rather than invented separately, same as the old
// page's ldSummary — this menu never writes to that store, it only reads for context.
//
// calculateHevc()/calculateRecoveryScore()'s formulas are reconstructions, not confirmed-correct — see
// mockScoringCriteria.ts. The V_total/C_total sub-score averaging below is the same kind of reasonable
// guess (structure from the source workbook's column groups, not a recovered formula) and should be
// confirmed with the data provider, not assumed correct.

const selectedHazard = ref<Community['activeRisk']>('flood');
const selectedCommunityId = ref<number>(communities[0].id);
const selectedCriteriaId = ref<number>(defaultCriteria().id);
const startDate = ref('2026-01-01');
const endDate = ref('2026-08-13');
const searchQuery = ref('');

const selectedCriteria = computed(() => criteriaList.value.find((c) => c.id === selectedCriteriaId.value) ?? defaultCriteria());

function communityCode(id: number) {
    return `R${String(id).padStart(3, '0')}`;
}

// ── Deterministic per-community × per-hazard baselines ──────────────────────
function score1to5(seed: number): number {
    return Math.min(5, Math.max(1, Math.round(1 + (seededValue(seed) / 100) * 4)));
}

function levelLabelFromScore1to5(score: number): string {
    if (score < 2) return riskLevelMeta.low.label;
    if (score < 3.5) return riskLevelMeta.medium.label;
    if (score < 4.5) return riskLevelMeta.high.label;
    return riskLevelMeta.critical.label;
}

interface HazardExposureDetail {
    hRaw: number;
    hScore: number;
    eMethod: string;
    eNumerator: number;
    eDenominator: number;
    ePercent: number;
    eScore: number;
}

function hazardExposureDetail(communityId: number, hazard: Community['activeRisk']): HazardExposureDetail {
    const base = communityId * 13 + hazardOrder.indexOf(hazard) * 5;
    const hRaw = Math.round((seededValue(base + 1) / 100) * 100) / 100;
    const hScore = score1to5(base + 1);
    const totalPopulation = Math.round(600 + seededValue(communityId + 0.3) * 3200);
    const exposedFraction = 0.03 + (seededValue(base + 2) / 100) * 0.32;
    const eNumerator = Math.round(totalPopulation * exposedFraction);
    const ePercent = Math.round((eNumerator / totalPopulation) * 10000) / 100;
    const eScore = ePercent < 5 ? 1 : ePercent < 10 ? 2 : ePercent < 20 ? 3 : ePercent < 30 ? 4 : 5;
    return { hRaw, hScore, eMethod: 'AREA_POPULATION', eNumerator, eDenominator: totalPopulation, ePercent, eScore };
}

interface VulnerabilityDetail {
    warningSharedScore: number;
    warningHazardStatus: string;
    infraGapCount: number;
    infraScore: number;
    systemScore: number;
    totalScore: number;
}

function vulnerabilityDetail(communityId: number, hazard: Community['activeRisk']): VulnerabilityDetail {
    const base = communityId * 19 + hazardOrder.indexOf(hazard) * 7;
    const warningSharedScore = Math.round((1 + (seededValue(base + 1) / 100) * 4) * 100) / 100;
    const infraGapCount = Math.round((seededValue(base + 2) / 100) * 6);
    const infraScore = Math.round(Math.min(5, 1 + infraGapCount * 0.6) * 100) / 100;
    const systemScore = Math.round((1 + (seededValue(base + 3) / 100) * 4) * 100) / 100;
    const totalScore = Math.round(((warningSharedScore + infraScore + systemScore) / 3) * 100) / 100;
    return {
        warningSharedScore,
        warningHazardStatus: levelLabelFromScore1to5(warningSharedScore),
        infraGapCount,
        infraScore,
        systemScore,
        totalScore,
    };
}

interface CapacityDetail {
    socialEffective: number;
    physicalEffective: number;
    operationalEffective: number;
    evacNetworkEffective: number;
    totalScore: number;
}

function capacityDetail(communityId: number, hazard: Community['activeRisk']): CapacityDetail {
    const base = communityId * 23 + hazardOrder.indexOf(hazard) * 11;
    const socialEffective = Math.round((1 + (seededValue(base + 1) / 100) * 4) * 100) / 100;
    const physicalEffective = Math.round((1 + (seededValue(base + 2) / 100) * 4) * 100) / 100;
    const operationalEffective = Math.round((1 + (seededValue(base + 3) / 100) * 4) * 100) / 100;
    const evacNetworkEffective = Math.round((1 + (seededValue(base + 4) / 100) * 4) * 100) / 100;
    const totalScore = Math.round(((socialEffective + physicalEffective + operationalEffective + evacNetworkEffective) / 4) * 100) / 100;
    return { socialEffective, physicalEffective, operationalEffective, evacNetworkEffective, totalScore };
}

interface RecoveryBaseline {
    r1: number;
    r2: number;
    r3: number;
    r4: number;
    r5: number;
}

function recoveryBaseline(communityId: number, hazard: Community['activeRisk']): RecoveryBaseline {
    const base = communityId * 29 + hazardOrder.indexOf(hazard) * 13;
    return { r1: score1to5(base + 1), r2: score1to5(base + 2), r3: score1to5(base + 3), r4: score1to5(base + 4), r5: score1to5(base + 5) };
}

const recoveryFieldKeys = ['r1', 'r2', 'r3', 'r4', 'r5'] as const;

// ── ตารางรายภัย — bulk row per community for the selected hazard, always all 32 communities (matches the
// old page's "ทุกชุมชน" scope; per-hazard exposure scoping isn't modeled here) ──
interface BulkRow {
    id: number;
    name: string;
    hScore: number;
    eScore: number;
    vTotalScore: number;
    cTotalScore: number;
    recoveryScore: number;
    hevc100: number;
    riskLevel: RiskLevel;
}

function buildBulkRow(community: Community, hazard: Community['activeRisk']): BulkRow {
    const he = hazardExposureDetail(community.id, hazard);
    const v = vulnerabilityDetail(community.id, hazard);
    const c = capacityDetail(community.id, hazard);
    const r = recoveryBaseline(community.id, hazard);
    const hevc = calculateHevc(selectedCriteria.value.weights, {
        hazard: he.hScore,
        exposure: he.eScore,
        vulnerability: v.totalScore,
        capacity: c.totalScore,
    });
    return {
        id: community.id,
        name: community.name,
        hScore: he.hScore,
        eScore: he.eScore,
        vTotalScore: v.totalScore,
        cTotalScore: c.totalScore,
        recoveryScore: calculateRecoveryScore(selectedCriteria.value.weights, r),
        hevc100: hevc.hevc100,
        riskLevel: hevc.riskLevel,
    };
}

const bulkRows = computed<BulkRow[]>(() => communities.map((c) => buildBulkRow(c, selectedHazard.value)));

const filteredBulkRows = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return bulkRows.value;
    return bulkRows.value.filter((r) => r.name.toLowerCase().includes(q));
});

const currentPage = ref(1);
const pageSize = 10;
const totalPages = computed(() => Math.max(1, Math.ceil(filteredBulkRows.value.length / pageSize)));
const pagedBulkRows = computed(() => {
    const start = (currentPage.value - 1) * pageSize;
    return filteredBulkRows.value.slice(start, start + pageSize);
});
function goToPage(page: number) {
    currentPage.value = Math.min(Math.max(page, 1), totalPages.value);
}
watch([selectedHazard, searchQuery], () => {
    currentPage.value = 1;
});

const topRowsForHazard = computed(() => [...bulkRows.value].sort((a, b) => b.hevc100 - a.hevc100).slice(0, 5));
const avgHevcForHazard = computed(() => (bulkRows.value.reduce((sum, r) => sum + r.hevc100, 0) / bulkRows.value.length).toFixed(1));
const avgRecoveryForHazard = computed(() => (bulkRows.value.reduce((sum, r) => sum + r.recoveryScore, 0) / bulkRows.value.length).toFixed(2));

// ── รายละเอียดการประเมินรายภัย — single-community breakdown for the currently selected community ──
const selectedCommunity = computed(() => communities.find((c) => c.id === selectedCommunityId.value) ?? communities[0]);
const heDetail = computed(() => hazardExposureDetail(selectedCommunityId.value, selectedHazard.value));
const vDetail = computed(() => vulnerabilityDetail(selectedCommunityId.value, selectedHazard.value));
const cDetail = computed(() => capacityDetail(selectedCommunityId.value, selectedHazard.value));
const rBaseline = computed(() => recoveryBaseline(selectedCommunityId.value, selectedHazard.value));

const rMode = ref<'shared' | 'override'>('shared');
const rOverride = ref<RecoveryBaseline>({ r1: 3, r2: 3, r3: 3, r4: 3, r5: 3 });

function resetRecoveryToBaseline() {
    rMode.value = 'shared';
    rOverride.value = { ...rBaseline.value };
}
watch([selectedCommunityId, selectedHazard], resetRecoveryToBaseline, { immediate: true });

const effectiveR = computed(() => (rMode.value === 'shared' ? rBaseline.value : rOverride.value));

const singleResult = computed(() =>
    calculateHevc(selectedCriteria.value.weights, {
        hazard: heDetail.value.hScore,
        exposure: heDetail.value.eScore,
        vulnerability: vDetail.value.totalScore,
        capacity: cDetail.value.totalScore,
    }),
);
const singleRecoveryScore = computed(() => calculateRecoveryScore(selectedCriteria.value.weights, effectiveR.value));

const scoreBadges = computed(() => [
    { key: 'H', label: 'H_SCORE', value: heDetail.value.hScore, color: '#22c55e' },
    { key: 'E', label: 'E_SCORE', value: heDetail.value.eScore, color: '#2563eb' },
    { key: 'V', label: 'V_TOTAL_SCORE', value: vDetail.value.totalScore, color: '#f97316' },
    { key: 'C', label: 'C_TOTAL_SCORE', value: cDetail.value.totalScore, color: '#8b5cf6' },
    { key: 'R', label: 'RECOVERY_SCORE', value: singleRecoveryScore.value, color: '#0ea5e9' },
]);

const componentBars = computed(() => [
    { label: 'H', value: heDetail.value.hScore, color: '#22c55e' },
    { label: 'E', value: heDetail.value.eScore, color: '#2563eb' },
    { label: 'V', value: vDetail.value.totalScore, color: '#f97316' },
    { label: 'C', value: cDetail.value.totalScore, color: '#8b5cf6' },
    { label: 'Recovery', value: singleRecoveryScore.value, color: '#0ea5e9' },
]);

// ── 5. Loss & Damage / Governance — read-only, live from the shared เหตุการณ์_LD register, never written
// back to it from this page ──
const matchingEvents = computed(() =>
    events.value.filter(
        (e) =>
            e.communityId === selectedCommunityId.value &&
            e.primaryHazard === selectedHazard.value &&
            e.eventStart >= startDate.value &&
            e.eventStart <= endDate.value,
    ),
);
const latestEvent = computed(() => [...matchingEvents.value].sort((a, b) => (a.eventStart < b.eventStart ? 1 : -1))[0] ?? null);
const ldSummary = computed(() => ({
    count: matchingEvents.value.length,
    houses: matchingEvents.value.reduce((sum, e) => sum + e.l1HousesDamaged, 0),
    damageThb: matchingEvents.value.reduce((sum, e) => sum + e.l3DamageValueThb, 0),
    source: latestEvent.value?.source ?? null,
    evidence: latestEvent.value?.evidence ?? null,
    dataDate: latestEvent.value?.dataDate ?? null,
    verificationStatus: (latestEvent.value?.verificationStatus ?? null) as VerificationStatus | null,
}));

const verificationBadgeClass: Record<VerificationStatus, string> = {
    ยืนยันแล้ว: 'bg-emerald-50 text-emerald-600',
    กำลังตรวจสอบ: 'bg-amber-50 text-amber-600',
};

// ── ตาราง ⇄ ผังพื้นที่ toggle for ตารางรายภัย — same pattern as DisasterOverview.vue's zone view (basemap
// TileLayer + real community zones, communityZones.ts), colored by this hazard's HEVC risk level instead
// of the overall multi-hazard level. ──
const tableViewMode = ref<'table' | 'map'>('table');
let zoneMap: L.Map | null = null;
const zoneMapEl = ref<HTMLDivElement | null>(null);

function renderZoneLayer() {
    if (!zoneMap) return;
    const rowById = new Map(bulkRows.value.map((r) => [r.id, r]));
    communityZones.forEach((zone) => {
        if (zone.polygon.length === 0) return;
        const row = rowById.get(zone.id);
        if (!row) return;
        const meta = riskLevelMeta[row.riskLevel];
        const popupHtml = `
            <div class="min-w-[150px] pb-1 text-center font-sans">
                <h4 class="mb-1 text-sm font-bold text-slate-800">${zone.name}</h4>
                <span class="inline-block rounded px-2 py-1 text-[10px] font-semibold" style="background-color:${meta.color}1a;color:${meta.color}">
                    HEVC ${row.hevc100} · ระดับ${meta.label}
                </span>
            </div>
        `;
        L.polygon(zone.polygon, { color: meta.color, weight: 1, fillColor: meta.color, fillOpacity: 0.55 }).bindPopup(popupHtml).addTo(zoneMap!);
    });
}

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
    renderZoneLayer();
    zoneMap.fitBounds(L.polygon(MUNICIPALITY_BOUNDARY).getBounds(), { padding: [10, 10] });
}

function destroyZoneMap() {
    zoneMap?.remove();
    zoneMap = null;
}

watch(tableViewMode, async (mode) => {
    if (mode === 'map') {
        await nextTick();
        initZoneMap();
    } else {
        destroyZoneMap();
    }
});

// Re-paint the zone colors when the hazard tab changes while the map view is already open.
watch(selectedHazard, () => {
    if (tableViewMode.value !== 'map') return;
    destroyZoneMap();
    nextTick(() => initZoneMap());
});

onUnmounted(destroyZoneMap);
</script>

<template>
    <Head title="รายภัย" />

    <AdminLayout title="รายภัย">
        <div class="mb-6">
            <h1 class="text-lg font-bold text-slate-800">แผ่นรายภัย</h1>
            <p class="mt-1 text-sm text-slate-500">จัดการข้อมูลรายภัย คำนวณ H-E-V-C-Recovery และ HEVC แยกตามประเภทภัย</p>
        </div>

        <!-- Stat row -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs"
                        :class="[hazardBadgeClass[selectedHazard].bg, hazardBadgeClass[selectedHazard].color]"
                    >
                        <i :class="hazardIcons[selectedHazard]"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">ภัยที่เลือก</p>
                </div>
                <p class="mt-2 truncate text-lg font-bold text-slate-800">{{ hazardShortLabel[selectedHazard] }}</p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-cyan-50 text-xs text-cyan-600">
                        <i class="fa-solid fa-users"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">ชุมชนในแผ่นงาน</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ communities.length }}</span
                    ><span class="text-[10px] text-slate-400">ชุมชน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-purple-50 text-xs text-purple-600">
                        <i class="fa-solid fa-gauge-high"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">HEVC เฉลี่ย</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ avgHevcForHazard }}</span
                    ><span class="text-[10px] text-slate-400">คะแนน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-50 text-xs text-red-600">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">ชุมชนเสี่ยงสูงสุด</p>
                </div>
                <p class="mt-2 truncate text-lg font-bold text-slate-800">{{ topRowsForHazard[0]?.name ?? '–' }}</p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-xs text-emerald-600">
                        <i class="fa-solid fa-rotate"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">Recovery เฉลี่ย</p>
                </div>
                <p class="mt-2 flex items-baseline gap-1 truncate">
                    <span class="text-lg font-bold text-slate-800">{{ avgRecoveryForHazard }}</span
                    ><span class="text-[10px] text-slate-400">คะแนน</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-xs text-sky-600">
                        <i class="fa-solid fa-database"></i>
                    </span>
                    <p class="truncate text-[11px] leading-tight text-slate-500">โหมด Recovery</p>
                </div>
                <p class="mt-2 truncate text-lg font-bold text-slate-800">{{ rMode === 'shared' ? 'USE_SHARED' : 'OVERRIDE' }}</p>
            </div>
        </div>

        <!-- Hazard tabs -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <button
                v-for="hazard in hazardOrder"
                :key="hazard"
                type="button"
                @click="selectedHazard = hazard"
                class="flex items-center justify-center gap-2 rounded-2xl border px-4 py-3 text-sm font-semibold shadow-sm transition-all"
                :class="
                    selectedHazard === hazard
                        ? 'border-blue-600 bg-blue-600 text-white'
                        : 'border-slate-100 bg-white text-slate-600 hover:bg-slate-50'
                "
            >
                <i :class="hazardIcons[hazard]"></i>
                {{ hazardShortLabel[hazard] }}
            </button>
        </div>

        <!-- Filter bar -->
        <div class="mb-6 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">เลือกชุมชน</label>
                    <select
                        v-model.number="selectedCommunityId"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:bg-white focus:outline-none"
                    >
                        <option v-for="c in communities" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ช่วงข้อมูล</label>
                    <div class="flex items-center gap-1 text-xs">
                        <input
                            type="date"
                            v-model="startDate"
                            class="w-0 min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-1.5 py-2 text-center focus:border-blue-500 focus:bg-white focus:outline-none"
                        />
                        <span class="text-slate-400">–</span>
                        <input
                            type="date"
                            v-model="endDate"
                            class="w-0 min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-1.5 py-2 text-center focus:border-blue-500 focus:bg-white focus:outline-none"
                        />
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">ค้นหาชุมชน</label>
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-300"></i>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="ค้นหาชุมชน..."
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-8 pr-3 text-sm text-slate-700 focus:border-blue-500 focus:bg-white focus:outline-none"
                        />
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">เกณฑ์คะแนนอ้างอิง</label>
                    <select
                        v-model.number="selectedCriteriaId"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:bg-white focus:outline-none"
                    >
                        <option v-for="c in criteriaList" :key="c.id" :value="c.id">{{ c.name }}{{ c.isDefault ? ' (ค่าเริ่มต้น)' : '' }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <!-- ตารางรายภัย -->
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
                        <h2 class="font-semibold text-slate-800">ตารางรายภัย ({{ hazardShortLabel[selectedHazard] }})</h2>
                        <div class="flex items-center rounded-lg border border-slate-200 bg-slate-100/80 p-0.5">
                            <button
                                type="button"
                                @click="tableViewMode = 'table'"
                                class="flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-semibold transition-all"
                                :class="tableViewMode === 'table' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                            >
                                <i class="fa-solid fa-table-list"></i>
                                ตาราง
                            </button>
                            <button
                                type="button"
                                @click="tableViewMode = 'map'"
                                class="flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-semibold transition-all"
                                :class="tableViewMode === 'map' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                            >
                                <i class="fa-solid fa-draw-polygon"></i>
                                ผังพื้นที่
                            </button>
                        </div>
                    </div>

                    <template v-if="tableViewMode === 'table'">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th class="px-3 py-2 font-medium">COMMUNITY_NAME</th>
                                        <th class="px-3 py-2 text-right font-medium">H_SCORE</th>
                                        <th class="px-3 py-2 text-right font-medium">E_SCORE</th>
                                        <th class="px-3 py-2 text-right font-medium">V_TOTAL_SCORE</th>
                                        <th class="px-3 py-2 text-right font-medium">C_TOTAL_SCORE</th>
                                        <th class="px-3 py-2 text-right font-medium">RECOVERY_SCORE</th>
                                        <th class="px-3 py-2 text-right font-medium">HEVC_100</th>
                                        <th class="px-3 py-2 text-right font-medium">RISK_LEVEL</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr
                                        v-for="row in pagedBulkRows"
                                        :key="row.id"
                                        class="cursor-pointer hover:bg-slate-50"
                                        :class="row.id === selectedCommunityId ? 'bg-blue-50/60' : ''"
                                        @click="selectedCommunityId = row.id"
                                    >
                                        <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-700">{{ row.name }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ row.hScore }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ row.eScore }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ row.vTotalScore }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ row.cTotalScore }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ row.recoveryScore }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-semibold text-slate-800">{{ row.hevc100 }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                :style="{
                                                    backgroundColor: riskLevelMeta[row.riskLevel].color + '1a',
                                                    color: riskLevelMeta[row.riskLevel].color,
                                                }"
                                            >
                                                {{ riskLevelMeta[row.riskLevel].label }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="pagedBulkRows.length === 0">
                                        <td colspan="8" class="px-3 py-8 text-center text-sm text-slate-400">ไม่พบชุมชนที่ตรงกับคำค้นหา</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                            <span>
                                แสดง {{ filteredBulkRows.length === 0 ? 0 : (currentPage - 1) * pageSize + 1 }}-{{
                                    Math.min(currentPage * pageSize, filteredBulkRows.length)
                                }}
                                จาก {{ filteredBulkRows.length }} รายการ
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

                    <div v-else class="p-4">
                        <p class="mb-3 text-[11px] text-slate-500">สีแสดงระดับความเสี่ยงของ{{ hazardShortLabel[selectedHazard] }}</p>
                        <div ref="zoneMapEl" class="h-[420px] w-full rounded-xl bg-slate-50"></div>
                        <div class="mt-3 flex flex-wrap gap-3">
                            <span v-for="(meta, level) in riskLevelMeta" :key="level" class="flex items-center gap-1.5 text-[11px] text-slate-500">
                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: meta.color }"></span>
                                {{ meta.label }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- รายละเอียดการประเมินรายภัย -->
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-semibold text-slate-800">รายละเอียดการประเมินรายภัย · {{ selectedCommunity.name }}</h2>
                        <span
                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                            :style="{
                                backgroundColor: riskLevelMeta[singleResult.riskLevel].color + '1a',
                                color: riskLevelMeta[singleResult.riskLevel].color,
                            }"
                        >
                            {{ riskLevelMeta[singleResult.riskLevel].label }}
                        </span>
                    </div>
                    <p class="mb-4 text-xs text-slate-400">
                        {{ communityCode(selectedCommunity.id) }} · {{ selectedCommunity.name }} · {{ startDate }} – {{ endDate }}
                    </p>

                    <div class="mb-5 grid grid-cols-3 gap-3 sm:grid-cols-6">
                        <div v-for="badge in scoreBadges" :key="badge.key" class="flex flex-col items-center rounded-xl bg-slate-50 p-3 text-center">
                            <span
                                class="mb-1.5 flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold text-white"
                                :style="{ backgroundColor: badge.color }"
                            >
                                {{ badge.key }}
                            </span>
                            <p class="text-[10px] text-slate-400">{{ badge.label }}</p>
                            <p class="text-sm font-bold text-slate-800">{{ badge.value }}</p>
                        </div>
                        <div class="flex flex-col items-center rounded-xl bg-slate-50 p-3 text-center">
                            <span class="mb-1.5 flex h-9 w-9 items-center justify-center rounded-full bg-indigo-600 text-sm text-white">
                                <i class="fa-solid fa-gauge-high"></i>
                            </span>
                            <p class="text-[10px] text-slate-400">HEVC_100</p>
                            <p class="text-sm font-bold text-slate-800">{{ singleResult.hevc100 }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <!-- 1. Hazard & Exposure -->
                        <div class="rounded-xl border border-slate-100 p-3.5">
                            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-blue-600">
                                <i class="fa-solid fa-droplet"></i>
                                1. Hazard &amp; Exposure
                            </p>
                            <dl class="space-y-1.5 text-[11px]">
                                <div>
                                    <dt class="text-slate-400">H_RAW</dt>
                                    <dd class="font-medium text-slate-700">{{ heDetail.hRaw }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">H_SCORE</dt>
                                    <dd class="font-medium text-slate-700">{{ heDetail.hScore }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">E_METHOD</dt>
                                    <dd class="font-medium text-slate-700">{{ heDetail.eMethod }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">E_NUMERATOR</dt>
                                    <dd class="font-medium text-slate-700">{{ heDetail.eNumerator.toLocaleString('th-TH') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">E_DENOMINATOR</dt>
                                    <dd class="font-medium text-slate-700">{{ heDetail.eDenominator.toLocaleString('th-TH') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">E_PERCENT</dt>
                                    <dd class="font-medium text-slate-700">{{ heDetail.ePercent }}%</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">E_SCORE</dt>
                                    <dd class="font-medium text-slate-700">{{ heDetail.eScore }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- 2. Vulnerability ระบบ -->
                        <div class="rounded-xl border border-slate-100 p-3.5">
                            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-orange-600">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                2. Vulnerability ระบบ
                            </p>
                            <dl class="space-y-1.5 text-[11px]">
                                <div>
                                    <dt class="text-slate-400">V_WARNING_SHARED_SCORE</dt>
                                    <dd class="font-medium text-slate-700">{{ vDetail.warningSharedScore }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">V_WARNING_HAZARD_STATUS</dt>
                                    <dd class="font-medium text-slate-700">{{ vDetail.warningHazardStatus }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">V_INFRA_GAP_COUNT</dt>
                                    <dd class="font-medium text-slate-700">{{ vDetail.infraGapCount }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">V_INFRA_SCORE</dt>
                                    <dd class="font-medium text-slate-700">{{ vDetail.infraScore }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">V_SYSTEM_SCORE</dt>
                                    <dd class="font-medium text-slate-700">{{ vDetail.systemScore }}</dd>
                                </div>
                                <div class="border-t border-slate-100 pt-1.5">
                                    <dt class="text-slate-400">V_TOTAL_SCORE</dt>
                                    <dd class="font-semibold text-slate-800">{{ vDetail.totalScore }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- 3. Capacity -->
                        <div class="rounded-xl border border-slate-100 p-3.5">
                            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-purple-600">
                                <i class="fa-solid fa-shield-halved"></i>
                                3. Capacity
                            </p>
                            <dl class="space-y-1.5 text-[11px]">
                                <div>
                                    <dt class="text-slate-400">C_SOCIAL_EFFECTIVE</dt>
                                    <dd class="font-medium text-slate-700">{{ cDetail.socialEffective }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">C_PHYSICAL_EFFECTIVE</dt>
                                    <dd class="font-medium text-slate-700">{{ cDetail.physicalEffective }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">C_OPERATIONAL_EFFECTIVE</dt>
                                    <dd class="font-medium text-slate-700">{{ cDetail.operationalEffective }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">C_EVAC_NETWORK_EFFECTIVE</dt>
                                    <dd class="font-medium text-slate-700">{{ cDetail.evacNetworkEffective }}</dd>
                                </div>
                                <div class="border-t border-slate-100 pt-1.5">
                                    <dt class="text-slate-400">C_TOTAL_SCORE</dt>
                                    <dd class="font-semibold text-slate-800">{{ cDetail.totalScore }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- 4. Recovery -->
                        <div class="rounded-xl border border-slate-100 p-3.5">
                            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-sky-600">
                                <i class="fa-solid fa-rotate"></i>
                                4. Recovery
                            </p>
                            <div class="mb-2">
                                <p class="text-[11px] text-slate-400">R_MODE</p>
                                <div class="mt-1 flex items-center rounded-md border border-slate-200 bg-slate-100 p-0.5 text-[9px]">
                                    <button
                                        type="button"
                                        @click="rMode = 'shared'"
                                        class="flex-1 rounded px-1.5 py-0.5 font-semibold"
                                        :class="rMode === 'shared' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500'"
                                    >
                                        USE_SHARED
                                    </button>
                                    <button
                                        type="button"
                                        @click="rMode = 'override'"
                                        class="flex-1 rounded px-1.5 py-0.5 font-semibold"
                                        :class="rMode === 'override' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-500'"
                                    >
                                        OVERRIDE
                                    </button>
                                </div>
                            </div>
                            <dl class="space-y-1.5 text-[11px]">
                                <div v-for="(key, idx) in recoveryFieldKeys" :key="key" class="flex items-center justify-between gap-2">
                                    <dt class="text-slate-400">R{{ idx + 1 }}_EFFECTIVE</dt>
                                    <dd v-if="rMode === 'shared'" class="font-medium text-slate-700">{{ effectiveR[key] }}</dd>
                                    <input
                                        v-else
                                        v-model.number="rOverride[key]"
                                        type="number"
                                        min="1"
                                        max="5"
                                        class="w-12 rounded border border-slate-200 bg-slate-50 px-1 py-0.5 text-right text-[11px] focus:border-blue-500 focus:bg-white focus:outline-none"
                                    />
                                </div>
                                <div class="border-t border-slate-100 pt-1.5">
                                    <dt class="text-slate-400">RECOVERY_SCORE</dt>
                                    <dd class="font-semibold text-slate-800">{{ singleRecoveryScore }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- 5. Loss & Damage / Governance -->
                        <div class="rounded-xl border border-slate-100 p-3.5">
                            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-red-600">
                                <i class="fa-solid fa-house-chimney-crack"></i>
                                5. Loss &amp; Damage / Governance
                            </p>
                            <dl class="space-y-1.5 text-[11px]">
                                <div>
                                    <dt class="text-slate-400">L_EVENT_COUNT</dt>
                                    <dd class="font-medium text-slate-700">{{ ldSummary.count }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">L1_HOUSES_DAMAGED</dt>
                                    <dd class="font-medium text-slate-700">{{ ldSummary.houses }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">L3_DAMAGE_VALUE_THB</dt>
                                    <dd class="font-medium text-slate-700">{{ ldSummary.damageThb.toLocaleString('th-TH') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">SOURCE</dt>
                                    <dd class="font-medium text-slate-700">{{ ldSummary.source ?? '–' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">EVIDENCE</dt>
                                    <dd class="font-medium text-slate-700">{{ ldSummary.evidence ?? '–' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400">DATA_DATE</dt>
                                    <dd class="font-medium text-slate-700">{{ ldSummary.dataDate ?? '–' }}</dd>
                                </div>
                                <div>
                                    <dt class="mb-1 text-slate-400">VERIFICATION_STATUS</dt>
                                    <dd>
                                        <span
                                            v-if="ldSummary.verificationStatus"
                                            class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold"
                                            :class="verificationBadgeClass[ldSummary.verificationStatus]"
                                        >
                                            {{ ldSummary.verificationStatus }}
                                        </span>
                                        <span v-else class="text-slate-300">–</span>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end border-t border-slate-100 pt-4">
                        <button type="button" @click="resetRecoveryToBaseline" class="text-xs font-medium text-slate-400 hover:text-slate-600">
                            <i class="fa-solid fa-rotate-left mr-1 text-[10px]"></i>
                            รีเซ็ต Recovery เป็นค่ากลางร่วม
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right column -->
            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-semibold text-slate-800">สรุปชุมชนในแผ่น{{ hazardShortLabel[selectedHazard] }}</h2>
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-slate-400">
                            <tr>
                                <th class="pb-2 font-medium">อันดับ</th>
                                <th class="pb-2 font-medium">ชุมชน</th>
                                <th class="pb-2 text-right font-medium">HEVC_100</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="(row, i) in topRowsForHazard"
                                :key="row.id"
                                class="cursor-pointer"
                                :class="row.id === selectedCommunityId ? 'bg-blue-50/60' : ''"
                                @click="selectedCommunityId = row.id"
                            >
                                <td class="py-2 text-slate-400">{{ i + 1 }}</td>
                                <td class="py-2 font-medium text-slate-700">{{ row.name }}</td>
                                <td class="py-2 text-right font-semibold" :style="{ color: riskLevelMeta[row.riskLevel].color }">
                                    {{ row.hevc100 }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-semibold text-slate-800">องค์ประกอบคะแนน HEVC</h2>
                    <BarChart :bars="componentBars" :max="5" />
                </div>
            </div>
        </div>

        <!-- Action bar -->
        <div class="mt-6 flex flex-col items-stretch justify-end gap-3 sm:flex-row">
            <button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                <i class="fa-regular fa-floppy-disk mr-1.5"></i>
                บันทึกฉบับร่าง
            </button>
            <button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                <i class="fa-solid fa-download mr-1.5"></i>
                ส่งออกข้อมูล
            </button>
            <button type="button" class="rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                <i class="fa-solid fa-arrows-rotate mr-1.5"></i>
                อัปเดตผลคำนวณ
            </button>
        </div>
    </AdminLayout>
</template>
