// Display helpers for the calc engine's output (App\Scoring\RiskEngine), shared by the dashboard pages.
// Keys match the engine: hazards FLOOD/FIRE/STORM/HEAT/PM25, levels low/moderate/high/very_high.
// hazardMeta.ts is the older public-map module (keys flood/wind/…, old level names); don't mix the two.
import type { HazardKey, RiskLevelKey } from '@/types';

export const HAZARD_KEYS: HazardKey[] = ['FLOOD', 'FIRE', 'STORM', 'HEAT', 'PM25'];

// Spec v5 §2.1 filter wording.
export const hazardLabel: Record<HazardKey, string> = {
    FLOOD: 'น้ำท่วม',
    FIRE: 'อัคคีภัย',
    STORM: 'วาตภัย',
    HEAT: 'ความร้อน',
    PM25: 'ฝุ่น PM2.5',
};

export const hazardIcon: Record<HazardKey, string> = {
    FLOOD: 'fa-solid fa-water',
    FIRE: 'fa-solid fa-fire',
    STORM: 'fa-solid fa-wind',
    HEAT: 'fa-solid fa-temperature-arrow-up',
    PM25: 'fa-solid fa-smog',
};

// Spec v5 §1.3: 0–24 ต่ำ (green), 25–49 ปานกลาง (yellow), 50–74 ค่อนข้างสูง (orange), 75–100 สูง (red).
export const riskLevels: Record<RiskLevelKey, { label: string; range: string; color: string; badge: string }> = {
    low: { label: 'ต่ำ', range: '0–24', color: '#22c55e', badge: 'bg-green-100 text-green-700' },
    moderate: { label: 'ปานกลาง', range: '25–49', color: '#eab308', badge: 'bg-yellow-100 text-yellow-700' },
    high: { label: 'ค่อนข้างสูง', range: '50–74', color: '#f97316', badge: 'bg-orange-100 text-orange-700' },
    very_high: { label: 'สูง', range: '75–100', color: '#ef4444', badge: 'bg-red-100 text-red-700' },
};

export const RISK_LEVEL_KEYS: RiskLevelKey[] = ['low', 'moderate', 'high', 'very_high'];

// Same rule as App\Scoring\RiskLevel::of(): the band comes from the value rounded to 1 decimal.
export function levelOf(value: number): RiskLevelKey {
    const v = Math.round(value * 10) / 10;
    if (v < 25) return 'low';
    if (v < 50) return 'moderate';
    if (v < 75) return 'high';
    return 'very_high';
}

// Dashboard names for the score groups (สมการการคำนวณ_hevc_v4.docx "Dashboard ใช้ …").
export const groupLabel: Record<string, string> = {
    C4: 'คนและการสื่อสาร',
    C5: 'พื้นที่สีเขียว–ฟ้า / NbS',
    C6: 'อุปกรณ์และทรัพยากร',
    C7: 'อพยพและพื้นที่ปลอดภัย',
    C8: 'เครือข่ายและการตอบสนอง',
    V_WARNING: 'การเข้าถึงคำเตือนภัย',
    V_INFRA: 'โครงสร้างพื้นฐาน',
};

export function formatScore(value: number | null | undefined, digits = 1): string {
    return value === null || value === undefined ? '–' : value.toFixed(digits);
}

export function formatNumber(value: number | null | undefined): string {
    return value === null || value === undefined ? '–' : value.toLocaleString('th-TH');
}

export function formatPercent(part: number, whole: number): string {
    return whole > 0 ? `${((part / whole) * 100).toFixed(1)}%` : '–';
}

export function formatThaiDate(value: string | null | undefined): string {
    if (!value) return '–';
    return new Date(`${value}T00:00:00`).toLocaleDateString('th-TH-u-ca-buddhist', { year: 'numeric', month: 'short', day: 'numeric' });
}

// 0–5 score layers on the map (E, V, C). One rule for every layer: red = bad (owner 2026-11-10). E and V are bad
// when high; C (capacity) is bad when low, so its colors run the other way.
export const SCORE_PALETTE = ['#16a34a', '#86efac', '#fde047', '#fb923c', '#ef4444', '#991b1b'];

export function scoreClass(value: number): number {
    return Math.max(0, Math.min(5, Math.round(value)));
}

export function scoreColor(value: number, goodWhenHigh: boolean): string {
    const k = scoreClass(value);
    return SCORE_PALETTE[goodWhenHigh ? 5 - k : k];
}
