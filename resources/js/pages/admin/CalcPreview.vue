<script setup lang="ts">
// ⚠️ TEMPORARY MOCK PAGE (2026-11-03). The owner asked for a picture of a possible "ตรวจสอบการคำนวณ" menu before
// deciding on it, to be deleted afterwards. Everything lives in this file, plus one route in routes/admin.php
// (calc-preview) and one nav entry in AdminLayout.vue. Delete all three together.
//
// Real: the HEVC formula, C_HEVC = 1 + 0.8·C_RAW, and the weights (copied from CriteriaSchema defaults).
// Mock: every community value, import number and date, the raw→0–5 bands, and the risk-level bands.
import { defaultWorkGroupTheme, workGroupIcons, workGroupThemes } from '@/data/workGroupIcons';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Hazard = 'FLOOD' | 'FIRE' | 'STORM' | 'HEAT' | 'PM25';
const HAZARDS: { key: Hazard; label: string; icon: string }[] = [
    { key: 'FLOOD', label: 'อุทกภัย', icon: 'fa-solid fa-house-flood-water' },
    { key: 'FIRE', label: 'อัคคีภัย', icon: 'fa-solid fa-fire' },
    { key: 'STORM', label: 'วาตภัย', icon: 'fa-solid fa-wind' },
    { key: 'HEAT', label: 'คลื่นความร้อน', icon: 'fa-solid fa-temperature-high' },
    { key: 'PM25', label: 'PM2.5', icon: 'fa-solid fa-smog' },
];

const GROUP_LABEL: Record<string, string> = {
    disaster: 'งานป้องกันภัย',
    welfare: 'งานสวัสดิการสังคม',
    health: 'งานสาธารณสุข',
    education: 'งานกองการศึกษา',
    engineering: 'งานสำนักช่าง',
    public_relations: 'งานประชาสัมพันธ์',
};

// ---- Mock raw→0–5 bands (the real ones aren't settled yet) ----
type Band = { band: number; rule: string };
const steps = (v: number, edges: number[], unit: string, labels: string[]): Band => {
    let band = 0;
    while (band < edges.length && v > edges[band]) band++;
    return { band, rule: `${labels[band]}${unit}` };
};
const pctV = (p: number) => steps(p, [0, 2, 5, 10, 20], '', ['0%', '>0–2%', '>2–5%', '>5–10%', '>10–20%', '>20%']);
const pctE = (p: number) => steps(p, [0, 20, 40, 60, 80], '', ['0%', '>0–20%', '>20–40%', '>40–60%', '>60–80%', '>80–100%']);
const BANDS: Record<string, (v: number, pop: number) => Band & { shown: string }> = {
    share: (v, pop) => ({ ...pctV((v / pop) * 100), shown: `${v.toLocaleString()} ÷ ${pop.toLocaleString()} = ${((v / pop) * 100).toFixed(1)}%` }),
    exposure: (v, pop) => ({ ...pctE((v / pop) * 100), shown: `${v.toLocaleString()} ÷ ${pop.toLocaleString()} = ${((v / pop) * 100).toFixed(1)}%` }),
    score: (v) => ({ band: v, rule: 'คะแนน 0–5 ใช้ตรง', shown: `${v}` }),
    per1000: (v) => ({ ...steps(v, [0, 2, 5, 10, 15], ' คน/1,000', ['0', '>0–2', '>2–5', '>5–10', '>10–15', '>15']), shown: `${v}` }),
    times: (v) => ({ ...steps(v, [0, 1, 2, 3, 4], ' ครั้ง', ['0', '1', '2', '3', '4', '≥5']), shown: `${v} ครั้ง` }),
    percent: (v) => ({ ...pctE(v), shown: `${v}%` }),
    count: (v) => ({ ...steps(v, [0, 1, 2, 3, 4], ' หน่วยงาน', ['0', '1', '2', '3', '4', '≥5']), shown: `${v}` }),
    hours: (v) => {
        const band = v <= 1 ? 5 : v <= 3 ? 4 : v <= 6 ? 3 : v <= 12 ? 2 : v <= 24 ? 1 : 0;
        return { band, rule: ['>24', '≤24', '≤12', '≤6', '≤3', '≤1'][band] + ' ชม.', shown: `${v} ชม.` };
    },
    infra: (v) => ({ ...steps(v, [0, 2, 4, 6, 8], ' จุด', ['0', '1–2', '3–4', '5–6', '7–8', '≥9']), shown: `${v} จุด` }),
};

// ---- Mock community data (values as they would come from the 8 imported sheets) ----
type Raw = Record<string, number | null>;
const COMMUNITIES: { code: string; name: string; raw: Raw; batches: Record<string, { id: number; date: string; file: string }> }[] = [
    {
        code: 'R001',
        name: 'ศรีสวัสดิ์ 2',
        raw: {
            POPULATION: 1240,
            E_NUMERATOR_COUNT_FLOOD: 310,
            E_NUMERATOR_COUNT_FIRE: 120,
            E_NUMERATOR_COUNT_STORM: 450,
            E_NUMERATOR_COUNT_HEAT: 1240,
            E_NUMERATOR_COUNT_PM25: 1240,
            V_ELDERLY_COUNT: 186,
            V_CHILD_COUNT: 142,
            V_DISABLED_COUNT: 31,
            V_LOW_INCOME_COUNT: 96,
            V_BEDRIDDEN_COUNT: 8,
            V_MEDICAL_DEPENDENT_COUNT: 5,
            V_NCD_COUNT: null,
            V_ASSISTED_EVACUATION_COUNT: 22,
            V_COMMUNICATION_ASSISTANCE_COUNT: 14,
            V_WARNING_ACCESS_GAP_SCORE: 3,
            V_INFRA_GAP_COUNT_FLOOD: 6,
            V_INFRA_GAP_COUNT_FIRE: 2,
            V_INFRA_GAP_COUNT_STORM: 3,
            V_INFRA_GAP_COUNT_HEAT: 1,
            V_INFRA_GAP_COUNT_PM25: 1,
            C4_1_VOLUNTEER_PER_1000: 10.5,
            C4_2_TRAINING_COUNT: 1,
            C4_3_RISK_COMM_SCORE: 4,
            C4_4_SCHOOL_DRR_EDUCATION_SCORE: 3,
            C5_1_GREENBLUE_SCORE: 2,
            C5_2_NBS_SCORE: 2,
            C5_3_MAINTENANCE_SCORE: 3,
            C6_1_RESPONSE_VEHICLE_ADEQUACY_SCORE: 4,
            C6_2_LIFESAVING_SCORE: 5,
            C6_3_READINESS_SCORE: 80,
            C6_4_HAZARD_EQUIPMENT_ADEQUACY_SCORE: 4,
            C7_1_FLOOD_SCORE: 4,
            C7_1_FIRE_SCORE: 4,
            C7_1_STORM_SCORE: 3,
            C7_2_SAFE_PLACE_SCORE: 4,
            C7_3_DRILL_SCORE: 3,
            C8_1_FORMAL_NETWORK_COUNT: 3,
            C8_2_RESPONSE_HOURS: 2,
            C8_3_NETWORK_TYPES_SCORE: 4,
            C8_4_JOINT_EXPERIENCE_SCORE: 4,
        },
        batches: {
            disaster: { id: 9, date: '01/02/2025', file: 'ปภ_มกราคม68.xlsx' },
            welfare: { id: 12, date: '20/01/2025', file: 'สวัสดิการ_ม.ค.68.xlsx' },
            health: { id: 14, date: '15/01/2025', file: 'สาธารณสุข_รอบ1.xlsx' },
            education: { id: 16, date: '10/01/2025', file: 'กองการศึกษา.xlsx' },
            engineering: { id: 18, date: '05/02/2025', file: 'สำนักช่าง_สำรวจ.xlsx' },
            public_relations: { id: 19, date: '06/02/2025', file: 'ประชาสัมพันธ์.xlsx' },
        },
    },
    {
        code: 'R002',
        name: 'ตักศิลา 2',
        raw: {
            POPULATION: 980,
            E_NUMERATOR_COUNT_FLOOD: 150,
            E_NUMERATOR_COUNT_FIRE: 210,
            E_NUMERATOR_COUNT_STORM: 330,
            E_NUMERATOR_COUNT_HEAT: 980,
            E_NUMERATOR_COUNT_PM25: 980,
            V_ELDERLY_COUNT: 132,
            V_CHILD_COUNT: 118,
            V_DISABLED_COUNT: 22,
            V_LOW_INCOME_COUNT: 74,
            V_BEDRIDDEN_COUNT: 5,
            V_MEDICAL_DEPENDENT_COUNT: 3,
            V_NCD_COUNT: 140,
            V_ASSISTED_EVACUATION_COUNT: 9,
            V_COMMUNICATION_ASSISTANCE_COUNT: 6,
            V_WARNING_ACCESS_GAP_SCORE: 2,
            V_INFRA_GAP_COUNT_FLOOD: 2,
            V_INFRA_GAP_COUNT_FIRE: 5,
            V_INFRA_GAP_COUNT_STORM: 2,
            V_INFRA_GAP_COUNT_HEAT: 3,
            V_INFRA_GAP_COUNT_PM25: 2,
            C4_1_VOLUNTEER_PER_1000: 7.2,
            C4_2_TRAINING_COUNT: 1,
            C4_3_RISK_COMM_SCORE: 2,
            C4_4_SCHOOL_DRR_EDUCATION_SCORE: null,
            C5_1_GREENBLUE_SCORE: 3,
            C5_2_NBS_SCORE: null,
            C5_3_MAINTENANCE_SCORE: 2,
            C6_1_RESPONSE_VEHICLE_ADEQUACY_SCORE: 3,
            C6_2_LIFESAVING_SCORE: 3,
            C6_3_READINESS_SCORE: 70,
            C6_4_HAZARD_EQUIPMENT_ADEQUACY_SCORE: 2,
            C7_1_FLOOD_SCORE: 2,
            C7_1_FIRE_SCORE: 2,
            C7_1_STORM_SCORE: 2,
            C7_2_SAFE_PLACE_SCORE: 2,
            C7_3_DRILL_SCORE: 2,
            C8_1_FORMAL_NETWORK_COUNT: 2,
            C8_2_RESPONSE_HOURS: 4,
            C8_3_NETWORK_TYPES_SCORE: 3,
            C8_4_JOINT_EXPERIENCE_SCORE: 2,
        },
        batches: {
            disaster: { id: 9, date: '01/02/2025', file: 'ปภ_มกราคม68.xlsx' },
            welfare: { id: 12, date: '20/01/2025', file: 'สวัสดิการ_ม.ค.68.xlsx' },
            health: { id: 15, date: '28/01/2025', file: 'สาธารณสุข_แก้ไข.xlsx' },
            education: { id: 16, date: '10/01/2025', file: 'กองการศึกษา.xlsx' },
            engineering: { id: 18, date: '05/02/2025', file: 'สำนักช่าง_สำรวจ.xlsx' },
            public_relations: { id: 19, date: '06/02/2025', file: 'ประชาสัมพันธ์.xlsx' },
        },
    },
];

// ---- Real weights (CriteriaSchema defaults) ----
const W = {
    hevc: { E: 0.3846, V: 0.6154, c: 0.16 },
    multi: { FLOOD: 0.35, FIRE: 0.1, STORM: 0.15, HEAT: 0.1, PM25: 0.3 } as Record<Hazard, number>,
    vSocial: { V_ELDERLY_COUNT: 0.3, V_CHILD_COUNT: 0.2, V_DISABLED_COUNT: 0.2, V_LOW_INCOME_COUNT: 0.3 },
    vHealth: { V_BEDRIDDEN_COUNT: 0.25, V_MEDICAL_DEPENDENT_COUNT: 0.3, V_NCD_COUNT: 0.45 },
    vAssist: { V_ASSISTED_EVACUATION_COUNT: 0.6, V_COMMUNICATION_ASSISTANCE_COUNT: 0.4 },
    vHazard: {
        FLOOD: [0.2, 0.15, 0.25, 0.15, 0.25],
        FIRE: [0.15, 0.15, 0.3, 0.15, 0.25],
        STORM: [0.2, 0.15, 0.25, 0.15, 0.25],
        HEAT: [0.25, 0.4, 0.1, 0.1, 0.15],
        PM25: [0.25, 0.45, 0.05, 0.15, 0.1],
    } as Record<Hazard, number[]>,
    c4: { C4_1_VOLUNTEER_PER_1000: 0.25, C4_2_TRAINING_COUNT: 0.2, C4_3_RISK_COMM_SCORE: 0.35, C4_4_SCHOOL_DRR_EDUCATION_SCORE: 0.2 },
    c5: { C5_1_GREENBLUE_SCORE: 0.35, C5_2_NBS_SCORE: 0.35, C5_3_MAINTENANCE_SCORE: 0.3 },
    c6: {
        FLOOD: [0.25, 0.25, 0.2, 0.3],
        FIRE: [0.25, 0.2, 0.2, 0.35],
        STORM: [0.25, 0.2, 0.25, 0.3],
        HEAT: [null, 0.25, 0.25, 0.5],
        PM25: [null, 0.2, 0.2, 0.6],
    } as Record<Hazard, (number | null)[]>,
    c7: {
        FLOOD: [0.4, 0.3, 0.3],
        FIRE: [0.4, 0.3, 0.3],
        STORM: [0.4, 0.3, 0.3],
        HEAT: [null, 0.6, 0.4],
        PM25: [null, 0.6, 0.4],
    } as Record<Hazard, (number | null)[]>,
    c8: { C8_1_FORMAL_NETWORK_COUNT: 0.2, C8_2_RESPONSE_HOURS: 0.3, C8_3_NETWORK_TYPES_SCORE: 0.2, C8_4_JOINT_EXPERIENCE_SCORE: 0.3 },
    cHazard: {
        FLOOD: [0.2, 0.2, 0.2, 0.25, 0.15],
        FIRE: [0.2, null, 0.3, 0.3, 0.2],
        STORM: [0.2, null, 0.25, 0.35, 0.2],
        HEAT: [0.25, 0.3, 0.1, 0.2, 0.15],
        PM25: [0.3, null, 0.15, 0.25, 0.3],
    } as Record<Hazard, (number | null)[]>,
};

const COLUMN_META: Record<string, { label: string; group: string; band: keyof typeof BANDS }> = {
    V_ELDERLY_COUNT: { label: 'ผู้สูงอายุ', group: 'welfare', band: 'share' },
    V_CHILD_COUNT: { label: 'เด็ก', group: 'welfare', band: 'share' },
    V_DISABLED_COUNT: { label: 'ผู้พิการ', group: 'welfare', band: 'share' },
    V_LOW_INCOME_COUNT: { label: 'ผู้มีรายได้น้อย', group: 'welfare', band: 'share' },
    V_BEDRIDDEN_COUNT: { label: 'ผู้ป่วยติดเตียง', group: 'health', band: 'share' },
    V_MEDICAL_DEPENDENT_COUNT: { label: 'ผู้พึ่งพาการแพทย์', group: 'health', band: 'share' },
    V_NCD_COUNT: { label: 'ผู้ป่วย NCDs', group: 'health', band: 'share' },
    V_ASSISTED_EVACUATION_COUNT: { label: 'ผู้ต้องช่วยอพยพ', group: 'health', band: 'share' },
    V_COMMUNICATION_ASSISTANCE_COUNT: { label: 'ผู้ต้องช่วยการสื่อสาร', group: 'health', band: 'share' },
    V_WARNING_ACCESS_GAP_SCORE: { label: 'ช่องว่างการเข้าถึงการเตือนภัย', group: 'public_relations', band: 'score' },
    C4_1_VOLUNTEER_PER_1000: { label: 'อาสาสมัครต่อ 1,000 คน', group: 'disaster', band: 'per1000' },
    C4_2_TRAINING_COUNT: { label: 'การอบรม/ทบทวน', group: 'disaster', band: 'times' },
    C4_3_RISK_COMM_SCORE: { label: 'การสื่อสารความเสี่ยง', group: 'public_relations', band: 'score' },
    C4_4_SCHOOL_DRR_EDUCATION_SCORE: { label: 'การศึกษา DRR ในโรงเรียน', group: 'education', band: 'score' },
    C5_1_GREENBLUE_SCORE: { label: 'พื้นที่สีเขียว–ฟ้า', group: 'engineering', band: 'score' },
    C5_2_NBS_SCORE: { label: 'NbS', group: 'engineering', band: 'score' },
    C5_3_MAINTENANCE_SCORE: { label: 'การบำรุงรักษา', group: 'engineering', band: 'score' },
    C6_1_RESPONSE_VEHICLE_ADEQUACY_SCORE: { label: 'ยานพาหนะ', group: 'disaster', band: 'score' },
    C6_2_LIFESAVING_SCORE: { label: 'อุปกรณ์ช่วยชีวิต', group: 'disaster', band: 'score' },
    C6_3_READINESS_SCORE: { label: 'ความพร้อมใช้งาน (ร้อยละ)', group: 'disaster', band: 'percent' },
    C6_4_HAZARD_EQUIPMENT_ADEQUACY_SCORE: { label: 'อุปกรณ์เฉพาะภัย', group: 'disaster', band: 'score' },
    C7_2_SAFE_PLACE_SCORE: { label: 'พื้นที่ปลอดภัย', group: 'disaster', band: 'score' },
    C7_3_DRILL_SCORE: { label: 'การฝึกซ้อม', group: 'disaster', band: 'score' },
    C8_1_FORMAL_NETWORK_COUNT: { label: 'เครือข่ายทางการ', group: 'disaster', band: 'count' },
    C8_2_RESPONSE_HOURS: { label: 'เวลาตอบสนอง', group: 'disaster', band: 'hours' },
    C8_3_NETWORK_TYPES_SCORE: { label: 'ความหลากหลายเครือข่าย', group: 'disaster', band: 'score' },
    C8_4_JOINT_EXPERIENCE_SCORE: { label: 'ประสบการณ์ร่วม', group: 'disaster', band: 'score' },
    ...Object.fromEntries(
        HAZARDS.map((hz) => [
            `V_INFRA_GAP_COUNT_${hz.key}`,
            { label: `จุดโครงสร้างพื้นฐานบกพร่อง (${hz.label})`, group: 'engineering', band: 'infra' as const },
        ]),
    ),
};

// ---- The calculation, recording every step (the "trace" a real engine would produce) ----
type Line = {
    column: string;
    label: string;
    group: string;
    shown: string | null;
    band: number | null;
    rule: string | null;
    weight: number | null; // null = not in this hazard's formula
    norm: number | null; // weight after dropping N/A items
    contrib: number | null;
};
type Part = { key: string; title: string; lines: Line[]; total: number | null; dropped: number };

function weighted(key: string, title: string, raw: Raw, items: [string, number | null, string?, string?][]): Part {
    const pop = raw.POPULATION ?? 1;
    const lines: Line[] = items.map(([column, weight, labelOverride, metaKey]) => {
        const meta = COLUMN_META[metaKey ?? column] ?? { label: column, group: 'disaster', band: 'score' };
        const value = raw[column];
        const b = value === null || value === undefined || weight === null ? null : BANDS[meta.band](value, pop);
        return {
            column,
            label: labelOverride ?? meta.label,
            group: meta.group,
            shown: b?.shown ?? null,
            band: b?.band ?? null,
            rule: b?.rule ?? null,
            weight,
            norm: null,
            contrib: null,
        };
    });
    const used = lines.filter((l) => l.weight !== null && l.band !== null);
    const sum = used.reduce((s, l) => s + (l.weight as number), 0);
    used.forEach((l) => {
        l.norm = (l.weight as number) / sum;
        l.contrib = l.norm * (l.band as number);
    });
    return {
        key,
        title,
        lines,
        total: used.length ? used.reduce((s, l) => s + (l.contrib as number), 0) : null,
        dropped: lines.filter((l) => l.weight !== null && l.band === null).length,
    };
}

// Combine sub-totals (e.g. V_SOCIAL…V_INFRA) with the hazard's weights, dropping N/A ones the same way.
function combine(parts: { key: string; label: string; value: number | null }[], weights: (number | null)[]) {
    const rows = parts.map((p, i) => ({ ...p, weight: weights[i], norm: null as number | null, contrib: null as number | null }));
    const used = rows.filter((r) => r.weight !== null && r.value !== null);
    const sum = used.reduce((s, r) => s + (r.weight as number), 0);
    used.forEach((r) => {
        r.norm = (r.weight as number) / sum;
        r.contrib = r.norm * (r.value as number);
    });
    return { rows, total: used.reduce((s, r) => s + (r.contrib as number), 0) };
}

function calculate(raw: Raw, h: Hazard) {
    const pop = raw.POPULATION as number;
    const eCol = `E_NUMERATOR_COUNT_${h}`;
    const eBand = BANDS.exposure(raw[eCol] as number, pop);
    const E = { column: eCol, shown: eBand.shown, band: eBand.band, rule: eBand.rule };

    const vSocial = weighted('V_SOCIAL', 'V_SOCIAL — กลุ่มเปราะบางทางสังคม', raw, Object.entries(W.vSocial));
    const vHealth = weighted('V_HEALTH', 'V_HEALTH — กลุ่มเปราะบางด้านสุขภาพ', raw, Object.entries(W.vHealth));
    const vAssist = weighted('V_ASSIST', 'V_ASSIST — กลุ่มที่ต้องได้รับความช่วยเหลือ', raw, Object.entries(W.vAssist));
    const vWarning = weighted('V_WARNING', 'V_WARNING — การเข้าถึงการเตือนภัย', raw, [['V_WARNING_ACCESS_GAP_SCORE', 1]]);
    const infraCol = `V_INFRA_GAP_COUNT_${h}`;
    const vInfra = weighted('V_INFRA', `V_INFRA — โครงสร้างพื้นฐาน (${h})`, raw, [[infraCol, 1]]);
    const vParts = [vSocial, vHealth, vAssist, vWarning, vInfra];
    const V = combine(
        vParts.map((p) => ({ key: p.key, label: p.key, value: p.total })),
        W.vHazard[h],
    );

    const c4 = weighted('C4', 'C4 — คนและการสื่อสาร', raw, Object.entries(W.c4));
    const c5 = weighted('C5', 'C5 — พื้นที่สีเขียว–ฟ้า / NbS', raw, Object.entries(W.c5));
    const c6Cols = ['C6_1_RESPONSE_VEHICLE_ADEQUACY_SCORE', 'C6_2_LIFESAVING_SCORE', 'C6_3_READINESS_SCORE', 'C6_4_HAZARD_EQUIPMENT_ADEQUACY_SCORE'];
    const c6 = weighted(
        'C6',
        `C6 — อุปกรณ์และทรัพยากร (${h})`,
        raw,
        c6Cols.map((c, i) => [c, W.c6[h][i]]),
    );
    const c71 = h === 'FLOOD' || h === 'FIRE' || h === 'STORM' ? `C7_1_${h}_SCORE` : 'C7_1_FLOOD_SCORE';
    const c7 = weighted('C7', `C7 — อพยพและพื้นที่ปลอดภัย (${h})`, raw, [
        [c71, W.c7[h][0], `แผนอพยพเฉพาะภัย (${c71})`, 'C7_2_SAFE_PLACE_SCORE'],
        ['C7_2_SAFE_PLACE_SCORE', W.c7[h][1]],
        ['C7_3_DRILL_SCORE', W.c7[h][2]],
    ]);
    const c8 = weighted('C8', 'C8 — เครือข่ายและการตอบสนอง', raw, Object.entries(W.c8));
    const cParts = [c4, c5, c6, c7, c8];
    const C = combine(
        cParts.map((p) => ({ key: p.key, label: p.key, value: p.total })),
        W.cHazard[h],
    );
    const cHevc = 1 + 0.8 * C.total;
    const factor = 1 - W.hevc.c * cHevc;
    const hevc = 20 * (W.hevc.E * E.band + W.hevc.V * V.total) * factor;

    return { E, vParts, V, cParts, C, cHevc, factor, hevc };
}

// Mock risk-level bands (the real ones are not on any settled page yet).
function level(score: number) {
    if (score < 25) return { label: 'ต่ำ', cls: 'bg-emerald-100 text-emerald-700' };
    if (score < 50) return { label: 'ปานกลาง', cls: 'bg-amber-100 text-amber-700' };
    if (score < 75) return { label: 'สูง', cls: 'bg-orange-100 text-orange-700' };
    return { label: 'สูงมาก', cls: 'bg-red-100 text-red-700' };
}

const communityCode = ref('R001');
const hazard = ref<Hazard>('FLOOD');
const community = computed(() => COMMUNITIES.find((c) => c.code === communityCode.value)!);
const result = computed(() => calculate(community.value.raw, hazard.value));
const multi = computed(() => {
    const rows = HAZARDS.map((hz) => ({ ...hz, hevc: calculate(community.value.raw, hz.key).hevc, weight: W.multi[hz.key] }));
    return { rows, total: rows.reduce((s, r) => s + r.hevc * r.weight, 0) };
});
const usedGroups = computed(() => Object.entries(community.value.batches).map(([group, b]) => ({ group, ...b })));

const openParts = ref<Record<string, boolean>>({ V_SOCIAL: true, V_HEALTH: true });
const toggle = (k: string) => (openParts.value[k] = !openParts.value[k]);

const f = (n: number | null, d = 2) => (n === null ? '–' : n.toFixed(d));
const themeOf = (g: string) => workGroupThemes[g] ?? defaultWorkGroupTheme;
const iconOf = (g: string) => workGroupIcons[g] ?? 'fa-solid fa-file-excel';
const hazardLabel = computed(() => HAZARDS.find((x) => x.key === hazard.value)!.label);
</script>

<template>
    <Head title="ตรวจสอบการคำนวณ (ตัวอย่าง)" />

    <AdminLayout title="ตรวจสอบการคำนวณ (ตัวอย่าง)">
        <div class="mb-4 border-l-4 border-blue-600 pl-4">
            <h1 class="text-2xl font-bold text-slate-800">ตรวจสอบการคำนวณ</h1>
            <p class="mt-1 text-sm text-slate-500">
                ดูที่มาของคะแนน HEVC ทีละขั้น: ค่าดิบมาจากงานไหน คอลัมน์ไหน แปลงเป็นระดับอย่างไร และคูณน้ำหนักเท่าไร
            </p>
        </div>

        <div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <i class="fa-solid fa-flask mt-0.5"></i>
            <div>
                <b>หน้าตัวอย่างเพื่อดูภาพเท่านั้น</b> — ค่าของชุมชน เลขรายการนำเข้า วันที่ เกณฑ์แปลงเป็นระดับ 0–5 และเกณฑ์ระดับความเสี่ยงเป็น<b
                    >ค่าสมมติ</b
                >
                ส่วน<b>สูตร HEVC และค่าน้ำหนัก</b>เป็นของจริงจากเกณฑ์กลาง หน้านี้จะลบออกหลังตัดสินใจ
            </div>
        </div>

        <!-- Controls -->
        <div class="mb-5 flex flex-wrap items-end gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <label class="block">
                <span class="mb-1.5 block text-xs font-medium text-slate-600">ชุมชน</span>
                <select v-model="communityCode" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                    <option v-for="c in COMMUNITIES" :key="c.code" :value="c.code">{{ c.code }} {{ c.name }}</option>
                </select>
            </label>
            <div>
                <span class="mb-1.5 block text-xs font-medium text-slate-600">ภัย</span>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="hz in HAZARDS"
                        :key="hz.key"
                        type="button"
                        class="rounded-xl px-3 py-2 text-sm"
                        :class="hazard === hz.key ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                        @click="hazard = hz.key"
                    >
                        <i :class="hz.icon" class="mr-1.5"></i>{{ hz.label }}
                    </button>
                </div>
            </div>
            <div class="ml-auto text-right text-xs text-slate-500">
                <div><i class="fa-solid fa-sliders mr-1"></i>เกณฑ์คะแนน: <b class="text-slate-700">2569–2575</b> (ใช้งานอยู่)</div>
                <div class="mt-1"><i class="fa-solid fa-calendar mr-1"></i>ข้อมูลที่ใช้: <b class="text-slate-700">ล่าสุดของแต่ละงาน</b></div>
            </div>
        </div>

        <!-- Result -->
        <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm lg:col-span-1">
                <p class="text-xs text-slate-500">{{ community.code }} {{ community.name }} · {{ hazardLabel }}</p>
                <p class="mt-1 text-5xl font-bold text-slate-800">{{ f(result.hevc, 1) }}</p>
                <span class="mt-2 inline-block rounded-lg px-2.5 py-1 text-sm font-medium" :class="level(result.hevc).cls">
                    ความเสี่ยง{{ level(result.hevc).label }}
                </span>
                <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-xl bg-sky-50 p-2">
                        <p class="text-[11px] text-sky-700">E การเปิดรับภัย</p>
                        <p class="text-lg font-bold text-sky-800">{{ f(result.E.band, 1) }}</p>
                    </div>
                    <div class="rounded-xl bg-pink-50 p-2">
                        <p class="text-[11px] text-pink-700">V ความเปราะบาง</p>
                        <p class="text-lg font-bold text-pink-800">{{ f(result.V.total) }}</p>
                    </div>
                    <div class="rounded-xl bg-emerald-50 p-2">
                        <p class="text-[11px] text-emerald-700">C_HEVC ศักยภาพ</p>
                        <p class="text-lg font-bold text-emerald-800">{{ f(result.cHevc) }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm lg:col-span-2">
                <h2 class="mb-3 font-semibold text-slate-800">สูตรที่ใช้ (แทนค่าจริง)</h2>
                <div class="space-y-2 rounded-xl bg-slate-50 p-4 font-mono text-sm text-slate-700">
                    <p>HEVC = 20 × (0.3846·E + 0.6154·V) × (1 − 0.16·C_HEVC)</p>
                    <p class="text-slate-500">C_HEVC = 1 + 0.8 × C_RAW = 1 + 0.8 × {{ f(result.C.total) }} = {{ f(result.cHevc) }}</p>
                    <p class="font-semibold text-blue-700">
                        = 20 × (0.3846 × {{ f(result.E.band, 0) }} + 0.6154 × {{ f(result.V.total) }}) × (1 − 0.16 × {{ f(result.cHevc) }})
                    </p>
                    <p class="font-semibold text-blue-700">
                        = 20 × {{ f(W.hevc.E * result.E.band + W.hevc.V * result.V.total, 3) }} × {{ f(result.factor, 3) }} =
                        <span class="rounded bg-blue-100 px-1.5">{{ f(result.hevc, 1) }}</span>
                    </p>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    <i class="fa-solid fa-circle-info mr-1"></i>C ยิ่งสูง (ชุมชนมีศักยภาพมาก) ตัวคูณยิ่งเล็ก คะแนนเสี่ยงจึงลดลง ·
                    ค่าสูงสุดที่เป็นไปได้คือ 84 (เมื่อ C_RAW = 0)
                </p>
            </div>
        </div>

        <!-- Data used -->
        <div class="mb-5 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 class="mb-1 font-semibold text-slate-800">ข้อมูลที่ใช้คำนวณ</h2>
            <p class="mb-3 text-xs text-slate-500">
                ข้อมูลล่าสุดของชุมชนนี้จากแต่ละงาน (ของจริง: กดเลขรายการเพื่อเปิด "ดูข้อมูล" ของรายการนำเข้านั้น)
            </p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="u in usedGroups" :key="u.group" class="flex items-center gap-3 rounded-xl border border-slate-100 p-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="themeOf(u.group).tile">
                        <i :class="iconOf(u.group)"></i>
                    </span>
                    <div class="min-w-0 text-xs">
                        <p class="font-medium text-slate-700">{{ GROUP_LABEL[u.group] }}</p>
                        <p class="truncate text-slate-500">
                            <span class="font-medium text-blue-600 underline decoration-dotted">#{{ u.id }}</span> · ประเมิน {{ u.date }} ·
                            {{ u.file }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- E -->
        <div class="mb-5 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-semibold text-slate-800">
                <span class="mr-2 rounded bg-sky-100 px-2 py-0.5 text-sky-700">E</span>การเปิดรับภัย = {{ f(result.E.band, 0) }}
            </h2>
            <div class="flex flex-wrap items-center gap-2 text-sm text-slate-700">
                <span class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs" :class="themeOf('disaster').tile">
                    <i :class="iconOf('disaster')"></i>{{ GROUP_LABEL.disaster }} #{{ community.batches.disaster.id }}
                </span>
                <code class="text-xs">{{ result.E.column }} ÷ POPULATION</code>
                <i class="fa-solid fa-arrow-right text-slate-300"></i>
                <span>{{ result.E.shown }}</span>
                <i class="fa-solid fa-arrow-right text-slate-300"></i>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">เกณฑ์ {{ result.E.rule }}</span>
                <i class="fa-solid fa-arrow-right text-slate-300"></i>
                <b>ระดับ {{ result.E.band }}</b>
            </div>
        </div>

        <!-- V and C -->
        <div
            v-for="side in [
                {
                    key: 'V',
                    title: 'ความเปราะบาง',
                    tag: 'bg-pink-100 text-pink-700',
                    parts: result.vParts,
                    combined: result.V,
                    note: 'น้ำหนัก V รายภัย',
                },
                {
                    key: 'C',
                    title: 'ศักยภาพ (C_RAW)',
                    tag: 'bg-emerald-100 text-emerald-700',
                    parts: result.cParts,
                    combined: result.C,
                    note: 'น้ำหนัก C รวมรายภัย',
                },
            ]"
            :key="side.key"
            class="mb-5 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"
        >
            <h2 class="mb-3 font-semibold text-slate-800">
                <span class="mr-2 rounded px-2 py-0.5" :class="side.tag">{{ side.key }}</span
                >{{ side.title }} = {{ f(side.combined.total) }}
            </h2>

            <!-- Combine step -->
            <div class="mb-4 overflow-x-auto rounded-xl border border-slate-100">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="px-3 py-2 font-medium">กลุ่ม</th>
                            <th class="px-3 py-2 text-right font-medium">ค่ากลุ่ม (0–5)</th>
                            <th class="px-3 py-2 text-right font-medium">{{ side.note }} ({{ hazard }})</th>
                            <th class="px-3 py-2 text-right font-medium">น้ำหนักหลังตัด N/A</th>
                            <th class="px-3 py-2 text-right font-medium">ผล</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in side.combined.rows"
                            :key="r.key"
                            class="border-t border-slate-50"
                            :class="{ 'text-slate-400': r.weight === null }"
                        >
                            <td class="px-3 py-1.5 font-medium">{{ r.key }}</td>
                            <td class="px-3 py-1.5 text-right">{{ f(r.value) }}</td>
                            <td class="px-3 py-1.5 text-right">{{ r.weight === null ? '– ไม่อยู่ในสูตรภัยนี้' : f(r.weight) }}</td>
                            <td class="px-3 py-1.5 text-right">{{ f(r.norm, 3) }}</td>
                            <td class="px-3 py-1.5 text-right">{{ f(r.contrib, 3) }}</td>
                        </tr>
                        <tr class="border-t border-slate-200 bg-slate-50 font-semibold">
                            <td class="px-3 py-1.5" colspan="4">รวม {{ side.key }}</td>
                            <td class="px-3 py-1.5 text-right">{{ f(side.combined.total, 3) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Each group, expandable -->
            <div v-for="p in side.parts" :key="p.key" class="mb-2 rounded-xl border border-slate-100">
                <button
                    type="button"
                    class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-slate-50"
                    @click="toggle(p.key)"
                >
                    <span class="font-medium text-slate-700">
                        <i class="fa-solid mr-2 text-xs text-slate-400" :class="openParts[p.key] ? 'fa-chevron-down' : 'fa-chevron-right'"></i
                        >{{ p.title }}
                        <span v-if="p.dropped" class="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700"
                            >N/A {{ p.dropped }} รายการ · ปรับน้ำหนักใหม่</span
                        >
                    </span>
                    <span class="font-semibold text-slate-800">{{ f(p.total) }}</span>
                </button>
                <div v-if="openParts[p.key]" class="overflow-x-auto border-t border-slate-100">
                    <table class="min-w-full text-xs">
                        <thead class="text-left text-slate-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">ตัวแปร</th>
                                <th class="px-3 py-2 font-medium">ที่มา</th>
                                <th class="px-3 py-2 font-medium">ค่าดิบ</th>
                                <th class="px-3 py-2 font-medium">แปลงเป็นระดับ</th>
                                <th class="px-3 py-2 text-right font-medium">น้ำหนัก</th>
                                <th class="px-3 py-2 text-right font-medium">หลังตัด N/A</th>
                                <th class="px-3 py-2 text-right font-medium">ผล</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="l in p.lines"
                                :key="l.column"
                                class="border-t border-slate-50"
                                :class="l.weight === null ? 'text-slate-300' : l.band === null ? 'bg-amber-50/50 text-slate-400' : 'text-slate-700'"
                            >
                                <td class="px-3 py-1.5">
                                    <p class="font-medium">{{ l.label }}</p>
                                    <code class="text-[10px] text-slate-400">{{ l.column }}</code>
                                </td>
                                <td class="px-3 py-1.5">
                                    <span
                                        class="inline-flex items-center gap-1 whitespace-nowrap rounded-md px-1.5 py-0.5"
                                        :class="themeOf(l.group).tile"
                                    >
                                        <i :class="iconOf(l.group)"></i>{{ GROUP_LABEL[l.group] }} #{{ community.batches[l.group]?.id }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-1.5">
                                    {{ l.weight === null ? '–' : (l.shown ?? 'ว่าง (ไม่มีข้อมูล)') }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-1.5">
                                    <template v-if="l.weight === null">ไม่อยู่ในสูตรภัยนี้</template>
                                    <template v-else-if="l.band === null"><span class="text-amber-700">N/A → ตัดออก</span></template>
                                    <template v-else>
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5">{{ l.rule }}</span> → <b>{{ l.band }}</b>
                                    </template>
                                </td>
                                <td class="px-3 py-1.5 text-right" :class="{ 'line-through': l.band === null && l.weight !== null }">
                                    {{ f(l.weight) }}
                                </td>
                                <td class="px-3 py-1.5 text-right">{{ f(l.norm, 3) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ f(l.contrib, 3) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Multi-hazard -->
        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-semibold text-slate-800">ภาพรวมหลายภัย (MULTI_HEVC) = {{ f(multi.total, 1) }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr class="border-b border-slate-100">
                            <th class="py-2 pr-4 font-medium">ภัย</th>
                            <th class="py-2 pr-4 text-right font-medium">HEVC</th>
                            <th class="py-2 pr-4 text-right font-medium">น้ำหนักภัยรวม</th>
                            <th class="py-2 text-right font-medium">ผล</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in multi.rows"
                            :key="r.key"
                            class="cursor-pointer border-b border-slate-50 hover:bg-slate-50"
                            :class="{ 'bg-blue-50/60': r.key === hazard }"
                            @click="hazard = r.key"
                        >
                            <td class="py-1.5 pr-4"><i :class="r.icon" class="mr-2 text-slate-400"></i>{{ r.label }}</td>
                            <td class="py-1.5 pr-4 text-right">
                                {{ f(r.hevc, 1) }}
                                <span class="ml-1 rounded px-1.5 text-[11px]" :class="level(r.hevc).cls">{{ level(r.hevc).label }}</span>
                            </td>
                            <td class="py-1.5 pr-4 text-right">{{ f(r.weight) }}</td>
                            <td class="py-1.5 text-right">{{ f(r.hevc * r.weight, 2) }}</td>
                        </tr>
                        <tr class="font-semibold">
                            <td class="py-2 pr-4" colspan="3">MULTI_HEVC</td>
                            <td class="py-2 text-right">{{ f(multi.total, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-400">กดแถวเพื่อดูรายละเอียดของภัยนั้น</p>
        </div>
    </AdminLayout>
</template>
