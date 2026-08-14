import type { Community } from './mahasarakham';

// Shared hazard iconography/labels used across the admin panel (Dashboard, รายภัย, เหตุการณ์ L&D, ...).
// riskNameTH in mahasarakham.ts is display copy for the public map (popups/legends) and is intentionally
// verbose ("ฝุ่น PM 2.5 เกินมาตรฐาน") — it overflows tight layout spots like filter panels/table cells.
// Use hazardShortLabel here instead for anything space-constrained.

export const hazardOrder: Community['activeRisk'][] = ['flood', 'fire', 'wind', 'heat', 'pm25'];

export const hazardShortLabel: Record<Community['activeRisk'], string> = {
    flood: 'อุทกภัย',
    fire: 'อัคคีภัย',
    wind: 'วาตภัย',
    heat: 'คลื่นความร้อน',
    pm25: 'PM 2.5',
    none: 'ไม่มี',
};

export const hazardIcons: Record<Community['activeRisk'], string> = {
    flood: 'fa-solid fa-water',
    fire: 'fa-solid fa-fire',
    wind: 'fa-solid fa-wind',
    heat: 'fa-solid fa-temperature-arrow-up',
    pm25: 'fa-solid fa-smog',
    none: 'fa-solid fa-check',
};

export const hazardBadgeClass: Record<Community['activeRisk'], { bg: string; color: string }> = {
    flood: { bg: 'bg-blue-50', color: 'text-blue-600' },
    fire: { bg: 'bg-red-50', color: 'text-red-600' },
    wind: { bg: 'bg-teal-50', color: 'text-teal-600' },
    heat: { bg: 'bg-orange-50', color: 'text-orange-600' },
    pm25: { bg: 'bg-purple-50', color: 'text-purple-600' },
    none: { bg: 'bg-slate-100', color: 'text-slate-500' },
};

export type RiskLevel = 'low' | 'medium' | 'high' | 'critical';

// Bands match 02_เกณฑ์คะแนน's "Risk level" lookup table exactly: <25 / 25–<50 / 50–<75 / ≥75.
export const riskLevelMeta: Record<RiskLevel, { label: string; color: string }> = {
    low: { label: 'ต่ำ', color: '#22c55e' },
    medium: { label: 'ปานกลาง', color: '#eab308' },
    high: { label: 'สูง', color: '#f97316' },
    critical: { label: 'สูงมาก', color: '#ef4444' },
};

export function riskLevelFromScore(score: number): RiskLevel {
    if (score >= 75) return 'critical';
    if (score >= 50) return 'high';
    if (score >= 25) return 'medium';
    return 'low';
}

// Deterministic pseudo-random 0-100 value from an integer seed (stable across reloads, unlike Math.random()).
export function seededValue(seed: number): number {
    const x = Math.sin(seed * 12.9898) * 43758.5453;
    return Math.abs(x - Math.floor(x)) * 100;
}
