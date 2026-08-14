import { ref } from 'vue';
import { type RiskLevel, riskLevelFromScore } from './hazardMeta';

// Shared mock "เกณฑ์คะแนน" store — module-level ref, imported wherever criteria are read (รายภัย,
// เหตุการณ์ L&D) or managed (จัดการเกณฑ์คะแนน). There's no scoring_criteria table/controller yet, so
// this is the in-memory "database": CRUD in ScoringCriteria.vue mutates the SAME ref other pages read
// from, so cross-page consistency actually holds within a browser session (resets on reload, expected
// for mock data). Weight structure/values are taken directly from the real 02_เกณฑ์คะแนน sheet.

export interface WeightField {
    key: string;
    label: string;
}
export interface WeightGroup {
    key: string;
    title: string;
    fields: WeightField[];
}

export const weightGroups: WeightGroup[] = [
    {
        key: 'multiHazard',
        title: 'ดัชนีความเสี่ยงหลายภัย (Multi-hazard)',
        fields: [
            { key: 'flood', label: 'อุทกภัย' },
            { key: 'pm25', label: 'PM 2.5' },
            { key: 'storm', label: 'วาตภัย' },
            { key: 'heat', label: 'คลื่นความร้อน' },
            { key: 'fire', label: 'อัคคีภัย' },
        ],
    },
    {
        key: 'riskDriver',
        title: 'สูตรความเสี่ยงหลัก (Risk Driver)',
        fields: [
            { key: 'hazard', label: 'Hazard' },
            { key: 'exposure', label: 'Exposure' },
            { key: 'vulnerability', label: 'Vulnerability' },
        ],
    },
    {
        key: 'vPeople',
        title: 'กลุ่มเปราะบาง (V_people)',
        fields: [
            { key: 'elderly', label: 'ผู้สูงอายุ' },
            { key: 'child', label: 'เด็ก' },
            { key: 'bedriddenDisabled', label: 'ผู้ป่วยติดเตียง/พิการ' },
            { key: 'lowIncome', label: 'ผู้มีรายได้น้อย' },
        ],
    },
    {
        key: 'vTotal',
        title: 'ความเปราะบางรวม (V_total)',
        fields: [
            { key: 'people', label: 'People' },
            { key: 'system', label: 'System' },
        ],
    },
    {
        key: 'capacity',
        title: 'ศักยภาพในการรับมือ (Capacity)',
        fields: [
            { key: 'social', label: 'Social / Institutional' },
            { key: 'physical', label: 'Physical / NbS' },
            { key: 'operational', label: 'Operational' },
            { key: 'evacuation', label: 'Evacuation / Network' },
        ],
    },
    {
        key: 'recovery',
        title: 'การฟื้นฟู (Recovery)',
        fields: [
            { key: 'r1', label: 'R1 แผนฟื้นฟูหลังภัย' },
            { key: 'r2', label: 'R2 Build Back Better' },
            { key: 'r3', label: 'R3 ฟื้นฟูเศรษฐกิจ/สังคม' },
            { key: 'r4', label: 'R4 ดูแลกลุ่มเปราะบาง' },
            { key: 'r5', label: 'R5 ถอดบทเรียน' },
        ],
    },
];

export type Weights = Record<string, Record<string, number>>;

export function baselineWeights(): Weights {
    return {
        multiHazard: { flood: 0.35, pm25: 0.3, storm: 0.15, heat: 0.1, fire: 0.1 },
        riskDriver: { hazard: 0.35, exposure: 0.25, vulnerability: 0.4 },
        vPeople: { elderly: 0.3, child: 0.2, bedriddenDisabled: 0.5, lowIncome: 0 },
        vTotal: { people: 0.6, system: 0.4 },
        capacity: { social: 0.3, physical: 0.25, operational: 0.25, evacuation: 0.2 },
        recovery: { r1: 0.25, r2: 0.25, r3: 0.2, r4: 0.15, r5: 0.15 },
    };
}

export function cloneWeights(weights: Weights): Weights {
    return JSON.parse(JSON.stringify(weights));
}

export interface ScoringCriteriaRecord {
    id: number;
    name: string;
    year: number;
    isDefault: boolean;
    locked: boolean;
    createdAt: string;
    weights: Weights;
}

export const criteriaList = ref<ScoringCriteriaRecord[]>([
    { id: 1, name: 'เกณฑ์คะแนนปี 2567', year: 2567, isDefault: false, locked: true, createdAt: '15 มิ.ย. 2567', weights: baselineWeights() },
    { id: 2, name: 'เกณฑ์คะแนนปี 2568', year: 2568, isDefault: true, locked: true, createdAt: '20 ก.ค. 2568', weights: baselineWeights() },
    {
        id: 3,
        name: 'เกณฑ์คะแนนปี 2569 (ฉบับร่าง)',
        year: 2569,
        isDefault: false,
        locked: false,
        createdAt: '01 ส.ค. 2569',
        weights: baselineWeights(),
    },
]);

export function defaultCriteria(): ScoringCriteriaRecord {
    return criteriaList.value.find((c) => c.isDefault) ?? criteriaList.value[0];
}

export function groupSum(weights: Weights, groupKey: string) {
    const group = weights[groupKey] ?? {};
    return Math.round(Object.values(group).reduce((sum, v) => sum + (Number(v) || 0), 0) * 100) / 100;
}

// ── Calculation helpers ──────────────────────────────────────────────────
// NOTE: the source workbook's cells only exposed structure + weights, not the literal Excel formula
// text, so HEVC_RAW here is a reasonable reconstruction (Risk Driver-weighted H/E/V, dampened by
// Capacity as a mitigating factor) — flag this assumption to the data provider when they review this
// mock; the exact formula should be confirmed, not assumed correct.

export interface HevcScores {
    hazard: number; // H_SCORE, 1-5
    exposure: number; // E_SCORE, 1-5
    vulnerability: number; // V_TOTAL_SCORE, 1-5
    capacity: number; // C_TOTAL_SCORE, 1-5
}

export interface HevcResult {
    hevcRaw: number;
    hevc100: number;
    riskLevel: RiskLevel;
}

export function calculateHevc(weights: Weights, scores: HevcScores): HevcResult {
    const rd = weights.riskDriver;
    const riskIndex = scores.hazard * rd.hazard + scores.exposure * rd.exposure + scores.vulnerability * rd.vulnerability; // 1-5 scale
    const mitigation = 1 - (scores.capacity / 5) * 0.3; // capacity dampens risk by up to 30%
    const hevcRaw = riskIndex * mitigation;
    const hevc100 = Math.max(0, Math.min(100, (hevcRaw / 5) * 100));
    return {
        hevcRaw: Math.round(hevcRaw * 100) / 100,
        hevc100: Math.round(hevc100 * 10) / 10,
        riskLevel: riskLevelFromScore(hevc100),
    };
}

export function calculateRecoveryScore(weights: Weights, r: { r1: number; r2: number; r3: number; r4: number; r5: number }) {
    const w = weights.recovery;
    return Math.round((r.r1 * w.r1 + r.r2 * w.r2 + r.r3 * w.r3 + r.r4 * w.r4 + r.r5 * w.r5) * 100) / 100;
}
