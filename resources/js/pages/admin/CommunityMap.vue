<script setup lang="ts">
// หน้า 3 แผนที่ความเสี่ยงรายชุมชน (spec v5 §3). Scores come live from App\Scoring\RiskEngine via
// CommunityMapController. Owner decisions 2026-11-10:
// - E/V/C layers on a 0–5 scale with their own legend; red always means bad.
// - Names show only from zoom 15; hover shows the name + score.
// - With nothing selected, the panel ranks all 32 communities.
// Leaflet objects are plain module variables, not reactive (see CLAUDE.md "Map pages").
import AnimatedNumber from '@/components/admin/AnimatedNumber.vue';
import CalcTraceDialog from '@/components/admin/CalcTraceDialog.vue';
import ScoreBar from '@/components/admin/ScoreBar.vue';
import { communityBoundaries } from '@/data/communityBoundaries';
import { MUNICIPALITY_BOUNDARY, communities as localCommunities } from '@/data/mahasarakham';
import {
    HAZARD_KEYS,
    RISK_LEVEL_KEYS,
    SCORE_PALETTE,
    formatScore,
    formatThaiDate,
    groupLabel,
    hazardIcon,
    hazardLabel,
    levelOf,
    riskLevels,
    scoreColor,
} from '@/data/riskDisplay';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { CommunityRiskDetail, HazardKey, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import L from 'leaflet';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    fiscalYears: number[];
    year: number | null;
    initialCommunity: string | null;
    initialHazard: string | null;
    cityDominant: HazardKey | null;
    communities: CommunityRiskDetail[];
}>();

// "ดูวิธีคิด" is admin-only (the endpoint is role:admin too).
const isAdmin = computed(() => usePage<SharedData>().props.auth.user?.role === 'admin');
const traceCode = ref<string | null>(null);

type Layer = 'HEVC' | 'E' | 'V' | 'C';
const LAYERS: { key: Layer; label: string }[] = [
    { key: 'HEVC', label: 'ระดับความเสี่ยง (HEVC)' },
    { key: 'E', label: 'ผู้ที่อาจได้รับผลกระทบ (E)' },
    { key: 'V', label: 'ความเปราะบาง (V)' },
    { key: 'C', label: 'ความพร้อมรับมือ (C)' },
];

// ---------------------------------------------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------------------------------------------
const selectedYear = ref(props.year);
const latestYear = computed(() => (props.fiscalYears.length ? Math.max(...props.fiscalYears) : null));
const hazard = ref<'ALL' | HazardKey>(HAZARD_KEYS.includes(props.initialHazard as HazardKey) ? (props.initialHazard as HazardKey) : 'ALL');
const layer = ref<Layer>('HEVC');
const selectedCode = ref<string | null>(props.communities.some((c) => c.code === props.initialCommunity) ? props.initialCommunity : null);

function changeYear() {
    router.get(
        route('community-map.index'),
        { year: selectedYear.value, community: selectedCode.value ?? undefined, hazard: hazard.value === 'ALL' ? undefined : hazard.value },
        { preserveScroll: true },
    );
}

// E, V and C exist per hazard. Under ภัยรวม they use the city's dominant hazard (same rule as page 2).
const layerHazard = computed<HazardKey | null>(() => (hazard.value === 'ALL' ? props.cityDominant : hazard.value));

const byCode = computed(() => new Map(props.communities.map((c) => [c.code, c])));
const selected = computed(() => (selectedCode.value ? (byCode.value.get(selectedCode.value) ?? null) : null));

// ---------------------------------------------------------------------------------------------------------------
// Values and colors per community
// ---------------------------------------------------------------------------------------------------------------
type Cell = { kind: 'value'; value: number } | { kind: 'missing' } | { kind: 'na' };

function cellOf(c: CommunityRiskDetail): Cell {
    if (c.status !== 'ok' || !c.hazards) return { kind: 'missing' };
    let value: number | null = null;
    if (layer.value === 'HEVC') {
        value = hazard.value === 'ALL' ? (c.multi?.hevc ?? null) : c.hazards[hazard.value].hevc;
    } else if (layerHazard.value) {
        const h = c.hazards[layerHazard.value];
        value = layer.value === 'E' ? h.e : layer.value === 'V' ? h.v : h.cRaw;
    }
    return value === null ? { kind: 'na' } : { kind: 'value', value };
}

const MISSING_COLOR = '#94a3b8';
const NA_COLOR = '#e2e8f0';

function colorOf(cell: Cell): string {
    if (cell.kind === 'missing') return MISSING_COLOR;
    if (cell.kind === 'na') return NA_COLOR;
    return layer.value === 'HEVC' ? riskLevels[levelOf(cell.value)].color : scoreColor(cell.value, layer.value === 'C');
}

function formatCell(cell: Cell): string {
    if (cell.kind === 'missing') return 'ข้อมูลไม่ครบ';
    if (cell.kind === 'na') return 'ไม่เกี่ยวข้อง';
    return layer.value === 'HEVC' ? `${cell.value.toFixed(1)} / 100` : `${cell.value.toFixed(1)} / 5`;
}

const layerTitle = computed(() => {
    const base = LAYERS.find((l) => l.key === layer.value)!.label;
    if (layer.value === 'HEVC') return hazard.value === 'ALL' ? 'ความเสี่ยงรวม (MULTI_HAZARD_HEVC)' : `ความเสี่ยง ${hazardLabel[hazard.value]}`;
    const h = layerHazard.value ? hazardLabel[layerHazard.value] : '';
    return `${base} · ${hazard.value === 'ALL' ? `ภัยเด่น: ${h}` : h}`;
});

const legend = computed(() => {
    if (layer.value === 'HEVC') {
        return RISK_LEVEL_KEYS.map((k) => ({ color: riskLevels[k].color, label: `${riskLevels[k].range} ${riskLevels[k].label}` }));
    }
    const goodWhenHigh = layer.value === 'C';
    return [0, 1, 2, 3, 4, 5].map((k) => ({ color: SCORE_PALETTE[goodWhenHigh ? 5 - k : k], label: `${k}` }));
});
const legendNote = computed(() =>
    layer.value === 'HEVC' ? '' : layer.value === 'C' ? 'คะแนน 0–5 (ต่ำ = พร้อมน้อย = แดง)' : 'คะแนน 0–5 (สูง = แย่ = แดง)',
);

// Ranked list (panel with nothing selected): riskiest first. Capacity is risky when low, so it sorts ascending.
const ranked = computed(() => {
    const rows = props.communities.map((c) => ({ c, cell: cellOf(c) }));
    const sign = layer.value === 'C' ? 1 : -1;
    const scored = rows
        .filter((r) => r.cell.kind === 'value')
        .sort((a, b) => sign * ((a.cell as { value: number }).value - (b.cell as { value: number }).value));
    return [...scored, ...rows.filter((r) => r.cell.kind === 'na'), ...rows.filter((r) => r.cell.kind === 'missing')];
});

// ---------------------------------------------------------------------------------------------------------------
// Leaflet
// ---------------------------------------------------------------------------------------------------------------
const mapEl = ref<HTMLElement | null>(null);
const fullscreen = ref(false);
const basemap = ref<'gray' | 'street' | 'satellite'>('gray');
const LABEL_ZOOM = 15;

let map: L.Map | null = null;
let tiles: Record<'gray' | 'street' | 'satellite', L.TileLayer> | null = null;
let labelLayer: L.LayerGroup | null = null;
const polygons = new Map<string, L.Polygon>();

// The boundary polygons are keyed by mahasarakham.ts' local id; its `code` joins them to the DB communities.
const ringsByCode = new Map<string, [number, number][][]>();
for (const local of localCommunities) {
    const boundary = communityBoundaries.find((b) => b.id === local.id);
    if (boundary?.rings.length) ringsByCode.set(local.code, boundary.rings);
}
const hasPolygon = (code: string) => ringsByCode.has(code);

function restyle() {
    for (const [code, polygon] of polygons) {
        const c = byCode.value.get(code);
        const isSelected = code === selectedCode.value;
        const cell = c ? cellOf(c) : ({ kind: 'missing' } as Cell);
        polygon.setStyle({
            fillColor: colorOf(cell),
            fillOpacity: cell.kind === 'value' ? 0.72 : 0.45,
            color: isSelected ? '#0f172a' : '#ffffff',
            weight: isSelected ? 3.5 : 1.5,
            dashArray: cell.kind === 'missing' ? '4 4' : undefined,
        });
        if (isSelected) polygon.bringToFront();
    }
}

function tooltipHtml(code: string): string {
    const c = byCode.value.get(code);
    if (!c) return '';
    return `<div class="font-sans"><div class="font-semibold">${c.name}</div><div class="text-xs">${formatCell(cellOf(c))}</div></div>`;
}

function updateLabels() {
    if (!map || !labelLayer) return;
    if (map.getZoom() >= LABEL_ZOOM) labelLayer.addTo(map);
    else labelLayer.remove();
}

function flyToSelected() {
    if (!map) return;
    const polygon = selectedCode.value ? polygons.get(selectedCode.value) : null;
    if (polygon) map.flyToBounds(polygon.getBounds(), { padding: [60, 60], maxZoom: 16, duration: 1.2 });
    else map.flyToBounds(L.latLngBounds(MUNICIPALITY_BOUNDARY), { padding: [20, 20], duration: 1.2 });
}

onMounted(() => {
    if (!mapEl.value) return;
    map = L.map(mapEl.value, { zoomControl: true }).fitBounds(L.latLngBounds(MUNICIPALITY_BOUNDARY), { padding: [20, 20] });
    tiles = {
        gray: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, FAO, NOAA, USGS',
            maxZoom: 16,
        }),
        street: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles &copy; Esri &mdash; Source: Esri, HERE, Garmin, USGS, NGA',
            maxZoom: 19,
        }),
        satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
            maxZoom: 19,
        }),
    };
    tiles.gray.addTo(map);
    L.control.scale({ imperial: false, position: 'bottomright' }).addTo(map);

    L.polygon(MUNICIPALITY_BOUNDARY, { color: '#1e3a8a', weight: 2, fill: false, dashArray: '6 6', interactive: false }).addTo(map);

    labelLayer = L.layerGroup();
    for (const [code, rings] of ringsByCode) {
        // Start neutral; restyle() right after mount fades in the real colors (CSS transition on .community-zone).
        const polygon = L.polygon(rings, { className: 'community-zone', fillColor: NA_COLOR, fillOpacity: 0.45, color: '#ffffff', weight: 1.5 });
        polygon.bindTooltip(() => tooltipHtml(code), { sticky: true, direction: 'top', className: 'zone-tooltip' });
        polygon.on('click', () => selectCommunity(code));
        polygon.on('mouseover', () => polygon.setStyle({ weight: code === selectedCode.value ? 3.5 : 3 }));
        polygon.on('mouseout', () => restyle());
        polygon.addTo(map);
        polygons.set(code, polygon);

        const name = props.communities.find((c) => c.code === code)?.name ?? '';
        L.marker(polygon.getBounds().getCenter(), {
            interactive: false,
            icon: L.divIcon({ className: 'zone-label', html: `<span>${name}</span>`, iconSize: undefined }),
        }).addTo(labelLayer);
    }
    map.on('zoomend', updateLabels);
    updateLabels();

    setTimeout(() => {
        restyle();
        if (selectedCode.value) flyToSelected();
    }, 150);
});

onUnmounted(() => {
    map?.remove();
    map = null;
    polygons.clear();
});

watch([hazard, layer, () => props.communities], restyle);
watch(selectedCode, () => {
    restyle();
    flyToSelected();
    syncUrl();
});
watch(hazard, syncUrl);
watch(basemap, (next, prev) => {
    if (!map || !tiles) return;
    tiles[prev].remove();
    tiles[next].addTo(map);
});
watch(fullscreen, async () => {
    await nextTick();
    map?.invalidateSize();
});

function selectCommunity(code: string | null) {
    selectedCode.value = code;
}

// Keep the URL shareable without an Inertia visit (it would refetch everything for a client-side change).
function syncUrl() {
    const url = new URL(window.location.href);
    if (selectedCode.value) url.searchParams.set('community', selectedCode.value);
    else url.searchParams.delete('community');
    if (hazard.value !== 'ALL') url.searchParams.set('hazard', hazard.value);
    else url.searchParams.delete('hazard');
    window.history.replaceState(window.history.state, '', url);
}

// ---------------------------------------------------------------------------------------------------------------
// Panel
// ---------------------------------------------------------------------------------------------------------------
// The hazard the panel's E / gap / C6 / C7 rows describe: the selected one, or this community's dominant hazard.
const panelHazard = computed<HazardKey | null>(() =>
    hazard.value === 'ALL' ? (selected.value?.multi?.dominant ?? props.cityDominant) : hazard.value,
);

const C5_HAZARDS: HazardKey[] = ['FLOOD', 'HEAT'];
const panelCapacity = computed(() => {
    const g = selected.value?.groups;
    const h = panelHazard.value;
    if (!g || !h) return [];
    return [
        { key: 'C4', label: `C4 ${groupLabel.C4}`, value: g.C4 },
        ...(C5_HAZARDS.includes(h) ? [{ key: 'C5', label: `C5 ${groupLabel.C5}`, value: g.C5 }] : []),
        { key: 'C6', label: `C6 ${groupLabel.C6}`, value: g[`C6_${h}`] },
        { key: 'C7', label: `C7 ${groupLabel.C7}`, value: g[`C7_${h}`] },
        { key: 'C8', label: `C8 ${groupLabel.C8}`, value: g.C8 },
    ];
});

const keyGroups = computed(() => {
    const counts = selected.value?.counts ?? {};
    return [
        { key: 'v_elderly_count', label: 'ผู้สูงอายุ', icon: 'fa-solid fa-person-cane' },
        { key: 'v_assisted_evacuation_count', label: 'ต้องช่วยอพยพ', icon: 'fa-solid fa-wheelchair' },
        { key: 'v_medical_dependent_count', label: 'พึ่งพาบริการแพทย์', icon: 'fa-solid fa-kit-medical' },
    ].map((g) => ({ ...g, count: counts[g.key] ?? null }));
});

const selectedScore = computed(() => {
    const c = selected.value;
    if (!c || c.status !== 'ok') return null;
    return hazard.value === 'ALL' ? (c.multi?.hevc ?? null) : (c.hazards?.[hazard.value].hevc ?? null);
});
const selectedDelta = computed(() => {
    const c = selected.value;
    if (!c?.previous || selectedScore.value === null) return null;
    const prev = hazard.value === 'ALL' ? c.previous.multi : c.previous.hazards[hazard.value];
    return prev === null ? null : selectedScore.value - prev;
});

function deltaClass(delta: number) {
    if (Math.abs(delta) < 0.05) return 'text-slate-400';
    return delta > 0 ? 'text-red-600' : 'text-green-600';
}
function deltaIcon(delta: number) {
    if (Math.abs(delta) < 0.05) return 'fa-solid fa-minus';
    return delta > 0 ? 'fa-solid fa-arrow-up' : 'fa-solid fa-arrow-down';
}

const oneDecimal = (v: number) => v.toFixed(1);
const percent = (v: number) => `${v.toFixed(1)}%`;
const card = 'rounded-2xl border border-slate-100 bg-white shadow-sm';
const selectClass =
    'rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100';
</script>

<template>
    <Head title="แผนที่รายชุมชน" />

    <AdminLayout title="แผนที่ความเสี่ยงรายชุมชน">
        <!-- Filters (sticky, same as page 2) -->
        <div
            class="sticky -top-4 z-[1000] -mx-4 mb-5 flex flex-wrap items-end gap-3 border-b border-slate-200/70 bg-slate-50/90 px-4 py-3 backdrop-blur lg:-top-6 lg:-mx-6 lg:px-6"
        >
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">เลือกภัย</span>
                <select v-model="hazard" :class="selectClass">
                    <option value="ALL">ภัยรวม (HEVC)</option>
                    <option v-for="h in HAZARD_KEYS" :key="h" :value="h">{{ hazardLabel[h] }}</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">เลือกชุมชน</span>
                <select
                    :value="selectedCode ?? ''"
                    :class="selectClass"
                    @change="selectCommunity(($event.target as HTMLSelectElement).value || null)"
                >
                    <option value="">ทั้งหมด</option>
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
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-500">ชั้นข้อมูล</span>
                <select v-model="layer" :class="selectClass">
                    <option v-for="l in LAYERS" :key="l.key" :value="l.key">{{ l.label }}</option>
                </select>
            </label>
        </div>

        <div v-if="!communities.length" class="rounded-2xl border border-slate-100 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
            <i class="fa-solid fa-database mb-3 block text-3xl text-slate-300"></i>
            ยังไม่มีข้อมูลสำหรับคำนวณ — นำเข้าข้อมูลที่เมนู "นำเข้าข้อมูล" ก่อน
        </div>

        <div v-else class="grid gap-5 xl:grid-cols-3">
            <!-- Map -->
            <div :class="[card, 'overflow-hidden xl:col-span-2', fullscreen ? 'fixed inset-0 z-[1100] rounded-none' : 'relative']">
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
                    <div class="min-w-0">
                        <h2 class="truncate font-semibold text-slate-800">{{ layerTitle }}</h2>
                        <p class="text-xs text-slate-400">ชี้เพื่อดูคะแนน · คลิกเพื่อดูรายละเอียด · ซูมเข้าเพื่อดูชื่อชุมชน</p>
                    </div>
                    <button
                        type="button"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"
                        :title="fullscreen ? 'ออกจากเต็มจอ' : 'เต็มจอ'"
                        @click="fullscreen = !fullscreen"
                    >
                        <i :class="fullscreen ? 'fa-solid fa-compress' : 'fa-solid fa-expand'"></i>
                    </button>
                </div>
                <div class="relative">
                    <div ref="mapEl" class="w-full" :class="fullscreen ? 'h-[calc(100vh-62px)]' : 'h-[460px] sm:h-[600px]'"></div>

                    <!-- Basemap toggle -->
                    <div class="absolute right-3 top-3 z-[500] flex overflow-hidden rounded-xl border border-slate-200 bg-white text-xs shadow-sm">
                        <button
                            v-for="b in [
                                { key: 'gray', label: 'เทา' },
                                { key: 'street', label: 'ถนน' },
                                { key: 'satellite', label: 'ดาวเทียม' },
                            ] as const"
                            :key="b.key"
                            type="button"
                            class="px-3 py-1.5 transition-colors"
                            :class="basemap === b.key ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-50'"
                            @click="basemap = b.key"
                        >
                            {{ b.label }}
                        </button>
                    </div>

                    <!-- Legend -->
                    <div class="absolute bottom-3 left-3 z-[500] rounded-xl border border-slate-200 bg-white/95 p-3 text-xs shadow-sm">
                        <p class="mb-1.5 font-semibold text-slate-700">{{ layer === 'HEVC' ? 'ระดับความเสี่ยง (HEVC)' : legendNote }}</p>
                        <ul :class="layer === 'HEVC' ? 'space-y-1' : 'flex gap-1'">
                            <li v-for="item in legend" :key="item.label" :class="layer === 'HEVC' ? 'flex items-center gap-2' : 'text-center'">
                                <span
                                    class="inline-block rounded-sm transition-colors duration-500"
                                    :class="layer === 'HEVC' ? 'h-3 w-4' : 'h-3 w-6'"
                                    :style="{ background: item.color }"
                                ></span>
                                <span class="text-slate-600" :class="layer === 'HEVC' ? '' : 'block'">{{ item.label }}</span>
                            </li>
                        </ul>
                        <div class="mt-2 space-y-1 border-t border-slate-100 pt-2 text-slate-500">
                            <p class="flex items-center gap-2">
                                <span
                                    class="inline-block h-3 w-4 rounded-sm border border-dashed border-slate-500"
                                    :style="{ background: MISSING_COLOR }"
                                ></span
                                >ข้อมูลไม่ครบ
                            </p>
                            <p class="flex items-center gap-2">
                                <span class="inline-block h-3 w-4 rounded-sm" :style="{ background: NA_COLOR }"></span>ไม่เกี่ยวข้อง
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel -->
            <div :class="[card, 'flex max-h-[680px] flex-col overflow-hidden']">
                <!-- Nothing selected: ranking -->
                <template v-if="!selected">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h2 class="font-semibold text-slate-800">อันดับชุมชน</h2>
                        <p class="text-xs text-slate-400">
                            {{ layer === 'C' ? 'เรียงจากพร้อมน้อยไปมาก' : 'เรียงจากเสี่ยงมากไปน้อย' }} · คลิกเพื่อดูรายละเอียด
                        </p>
                    </div>
                    <TransitionGroup tag="ol" name="rank" class="custom-scrollbar relative flex-1 overflow-y-auto px-3 py-2">
                        <li v-for="(row, i) in ranked" :key="row.c.code">
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-slate-50"
                                @click="selectCommunity(row.c.code)"
                            >
                                <span class="w-6 text-center text-xs font-bold text-slate-400">{{ row.cell.kind === 'value' ? i + 1 : '–' }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-slate-700">{{ row.c.name }}</span>
                                <span
                                    v-if="row.cell.kind === 'value'"
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white transition-colors duration-500"
                                    :style="{ background: colorOf(row.cell) }"
                                    ><AnimatedNumber :value="row.cell.value" :format="oneDecimal"
                                /></span>
                                <span v-else class="text-[11px] text-slate-400">{{ formatCell(row.cell) }}</span>
                            </button>
                        </li>
                    </TransitionGroup>
                </template>

                <!-- A community selected -->
                <template v-else>
                    <div class="flex items-start gap-3 border-b border-slate-100 px-5 py-4">
                        <button
                            type="button"
                            class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100"
                            title="กลับไปอันดับทั้งหมด"
                            @click="selectCommunity(null)"
                        >
                            <i class="fa-solid fa-arrow-left"></i>
                        </button>
                        <div class="min-w-0">
                            <h2 class="truncate text-lg font-bold text-slate-800">{{ selected.name }}</h2>
                            <p class="text-xs text-slate-400">
                                {{ selected.code }} · ข้อมูล {{ formatThaiDate(selected.dataDate) }}
                                <template v-if="selected.criteriaSet"> · {{ selected.criteriaSet.name }}</template>
                            </p>
                        </div>
                    </div>

                    <div class="custom-scrollbar flex-1 space-y-5 overflow-y-auto px-5 py-4 text-sm">
                        <p v-if="!hasPolygon(selected.code)" class="rounded-xl bg-amber-50 p-3 text-xs text-amber-700">
                            <i class="fa-solid fa-map-location-dot mr-1"></i>ชุมชนนี้ยังไม่มีขอบเขตบนแผนที่
                        </p>

                        <!-- Area facts -->
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-slate-50 p-2">
                                <p class="text-[11px] text-slate-400">ประชากร</p>
                                <p class="font-semibold text-slate-800"><AnimatedNumber :value="selected.population" /></p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2">
                                <p class="text-[11px] text-slate-400">ครัวเรือน</p>
                                <p class="font-semibold text-slate-800"><AnimatedNumber :value="selected.households" /></p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2">
                                <p class="text-[11px] text-slate-400">พื้นที่ ตร.กม.</p>
                                <p class="font-semibold text-slate-800">
                                    <AnimatedNumber :value="selected.areaKm2" :format="(v: number) => v.toFixed(2)" />
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="selected.status === 'missing'"
                            class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-slate-600"
                        >
                            <p class="font-semibold"><i class="fa-solid fa-circle-half-stroke mr-1 text-slate-400"></i>ข้อมูลไม่ครบ ยังคำนวณไม่ได้</p>
                            <p class="mt-1 text-xs">ขาดข้อมูลจาก: {{ selected.missingSheets.join(', ') }}</p>
                        </div>

                        <template v-else-if="selected.hazards && selected.groups">
                            <!-- Risk -->
                            <div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-4">
                                <div>
                                    <p class="text-xs text-slate-500">
                                        {{ hazard === 'ALL' ? 'ระดับความเสี่ยงรวม' : `ความเสี่ยง ${hazardLabel[hazard]}` }}
                                    </p>
                                    <p v-if="selectedScore !== null" class="text-3xl font-bold text-slate-800">
                                        <AnimatedNumber :value="selectedScore" :format="oneDecimal" />
                                        <span class="ml-1 text-sm font-medium text-slate-400">/ 100</span>
                                    </p>
                                    <p v-else class="text-sm text-slate-400">ไม่เกี่ยวข้อง</p>
                                    <p v-if="selectedDelta !== null" class="text-xs" :class="deltaClass(selectedDelta)">
                                        <i :class="deltaIcon(selectedDelta)" class="mr-0.5"></i>{{ selectedDelta > 0 ? '+' : ''
                                        }}{{ selectedDelta.toFixed(1) }} จากรอบก่อน
                                    </p>
                                    <p v-else class="text-[11px] text-slate-400">ยังไม่มีข้อมูลเปรียบเทียบ</p>
                                </div>
                                <span
                                    v-if="selectedScore !== null"
                                    class="rounded-full px-3 py-1 text-sm font-semibold transition-colors duration-500"
                                    :class="riskLevels[levelOf(selectedScore)].badge"
                                    >{{ riskLevels[levelOf(selectedScore)].label }}</span
                                >
                            </div>
                            <p v-if="selected.multi?.dominant" class="-mt-3 text-xs text-slate-500">
                                <i :class="hazardIcon[selected.multi.dominant]" class="mr-1"></i>ภัยเด่น
                                <span class="font-semibold text-slate-700">{{ hazardLabel[selected.multi.dominant] }}</span>
                            </p>

                            <!-- 5 hazards -->
                            <div>
                                <h3 class="mb-2 font-semibold text-slate-700">คะแนนรายภัย (0–100)</h3>
                                <ul class="space-y-2">
                                    <li
                                        v-for="h in HAZARD_KEYS"
                                        :key="h"
                                        class="grid grid-cols-[88px_1fr_40px] items-center gap-2 transition-opacity duration-500"
                                        :class="hazard !== 'ALL' && hazard !== h ? 'opacity-40' : ''"
                                    >
                                        <span class="truncate text-xs text-slate-600"
                                            ><i :class="hazardIcon[h]" class="mr-1 w-3"></i>{{ hazardLabel[h] }}</span
                                        >
                                        <ScoreBar
                                            :value="selected.hazards[h].hevc"
                                            :max="100"
                                            :color="
                                                selected.hazards[h].hevc === null ? NA_COLOR : riskLevels[levelOf(selected.hazards[h].hevc!)].color
                                            "
                                        />
                                        <span class="text-right text-xs font-semibold text-slate-700">{{
                                            formatScore(selected.hazards[h].hevc)
                                        }}</span>
                                    </li>
                                </ul>
                            </div>

                            <template v-if="panelHazard">
                                <p class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs text-blue-700">
                                    รายละเอียดด้านล่างเป็นของ{{ hazard === 'ALL' ? 'ภัยเด่น' : 'ภัย' }}:
                                    <span class="font-semibold">{{ hazardLabel[panelHazard] }}</span>
                                </p>

                                <!-- Exposure -->
                                <div>
                                    <h3 class="mb-1 font-semibold text-slate-700">ผู้ที่อาจได้รับผลกระทบ</h3>
                                    <p class="text-slate-600">
                                        <span class="text-lg font-bold text-slate-800"
                                            ><AnimatedNumber :value="selected.counts[`e_numerator_count_${panelHazard.toLowerCase()}`]"
                                        /></span>
                                        คน ·
                                        <AnimatedNumber :value="selected.hazards[panelHazard].ePercent" :format="percent" /> ของประชากร
                                        <span class="text-xs text-slate-400">(คะแนน E {{ formatScore(selected.hazards[panelHazard].e, 0) }}/5)</span>
                                    </p>
                                </div>

                                <!-- Key groups -->
                                <div>
                                    <h3 class="mb-2 font-semibold text-slate-700">กลุ่มสำคัญ</h3>
                                    <ul class="space-y-1.5">
                                        <li v-for="g in keyGroups" :key="g.key" class="flex items-center justify-between">
                                            <span class="text-slate-600"><i :class="g.icon" class="mr-2 w-4 text-slate-400"></i>{{ g.label }}</span>
                                            <span class="font-semibold text-slate-800"><AnimatedNumber :value="g.count" /> คน</span>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Gaps -->
                                <div>
                                    <h3 class="mb-2 font-semibold text-slate-700">
                                        ช่องว่าง <span class="text-xs font-normal text-slate-400">(0–5, สูง = แย่)</span>
                                    </h3>
                                    <ul class="space-y-2">
                                        <li
                                            v-for="gap in [
                                                { key: 'w', label: groupLabel.V_WARNING, value: selected.groups.V_WARNING },
                                                {
                                                    key: 'i',
                                                    label: `${groupLabel.V_INFRA} (${hazardLabel[panelHazard]})`,
                                                    value: selected.groups[`V_INFRA_${panelHazard}`],
                                                },
                                            ]"
                                            :key="gap.key"
                                            class="grid grid-cols-[1fr_96px_32px] items-center gap-2"
                                        >
                                            <span class="truncate text-xs text-slate-600">{{ gap.label }}</span>
                                            <ScoreBar
                                                :value="gap.value ?? null"
                                                :max="5"
                                                :color="gap.value === null ? NA_COLOR : scoreColor(gap.value!, false)"
                                            />
                                            <span class="text-right text-xs font-semibold text-slate-700">{{ formatScore(gap.value) }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Capacity -->
                                <div>
                                    <h3 class="mb-2 font-semibold text-slate-700">
                                        ความพร้อมรับมือ <span class="text-xs font-normal text-slate-400">(0–5, สูง = พร้อม)</span>
                                    </h3>
                                    <ul class="space-y-2">
                                        <li v-for="cap in panelCapacity" :key="cap.key" class="grid grid-cols-[1fr_96px_32px] items-center gap-2">
                                            <span class="truncate text-xs text-slate-600">{{ cap.label }}</span>
                                            <ScoreBar :value="cap.value ?? null" :max="5" />
                                            <span class="text-right text-xs font-semibold text-slate-700">{{ formatScore(cap.value) }}</span>
                                        </li>
                                        <li class="grid grid-cols-[1fr_96px_32px] items-center gap-2 border-t border-slate-100 pt-2">
                                            <span class="text-xs font-semibold text-slate-700">C รวม {{ hazardLabel[panelHazard] }}</span>
                                            <ScoreBar :value="selected.hazards[panelHazard].cRaw" :max="5" color="#0d9488" />
                                            <span class="text-right text-xs font-bold text-slate-800">{{
                                                formatScore(selected.hazards[panelHazard].cRaw)
                                            }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </template>

                            <!-- Response + recovery -->
                            <div class="grid grid-cols-2 gap-2">
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <p class="text-[11px] text-slate-400">เวลาตอบสนองของเครือข่าย</p>
                                    <p class="font-semibold text-slate-800">
                                        <template v-if="selected.responseHours !== null"
                                            ><AnimatedNumber :value="selected.responseHours" :format="oneDecimal" /> ชม.</template
                                        >
                                        <template v-else>–</template>
                                    </p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <p class="text-[11px] text-slate-400">ความพร้อมฟื้นฟู (RECOVERY_AVG)</p>
                                    <p class="font-semibold text-slate-800"><AnimatedNumber :value="selected.recovery" :format="oneDecimal" /> / 5</p>
                                </div>
                            </div>
                        </template>

                        <Link
                            :href="route('risk-factors.index', { community: selected.code })"
                            class="flex items-center justify-center gap-2 rounded-xl border border-blue-200 py-2 text-sm font-medium text-blue-600 hover:bg-blue-50"
                        >
                            ดูปัจจัยเพิ่มเติม <i class="fa-solid fa-arrow-right"></i>
                        </Link>
                        <button
                            v-if="isAdmin"
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                            @click="traceCode = selected.code"
                        >
                            <i class="fa-solid fa-calculator"></i>การคำนวณ <span class="text-[11px] text-slate-400">(ผู้ดูแลระบบ)</span>
                        </button>
                    </div>
                </template>
            </div>
        </div>
        <CalcTraceDialog :code="traceCode" :year="year" :initial-hazard="panelHazard" @close="traceCode = null" />
    </AdminLayout>
</template>

<style scoped>
.rank-move,
.rank-enter-active,
.rank-leave-active {
    transition: all 0.5s ease;
}
.rank-enter-from,
.rank-leave-to {
    opacity: 0;
    transform: translateX(16px);
}
.rank-leave-active {
    position: absolute;
    left: 0;
    right: 0;
}
@media (prefers-reduced-motion: reduce) {
    .rank-move,
    .rank-enter-active,
    .rank-leave-active {
        transition: none;
    }
}
</style>

<style>
/* Leaflet paths: fade color changes (layer/hazard switch) instead of snapping. */
.community-zone {
    transition:
        fill 0.6s ease,
        fill-opacity 0.6s ease,
        stroke-width 0.2s ease;
    cursor: pointer;
}
@media (prefers-reduced-motion: reduce) {
    .community-zone {
        transition: none;
    }
}
.zone-tooltip {
    border-radius: 10px;
    padding: 6px 10px;
}
.zone-label {
    background: none;
    border: none;
}
.zone-label span {
    display: inline-block;
    transform: translate(-50%, -50%);
    white-space: nowrap;
    font-family: Anuphan, sans-serif;
    font-size: 11px;
    font-weight: 600;
    color: #1e293b;
    text-shadow:
        0 0 3px #fff,
        0 0 3px #fff,
        0 0 3px #fff;
    pointer-events: none;
}
</style>
