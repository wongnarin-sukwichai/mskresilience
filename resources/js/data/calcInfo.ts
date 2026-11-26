// Types + helpers for the admin-only ⓘ on calculated cards (components/admin/CalcInfo.vue, owner 2026-11-26).
// The sources come from App\Scoring\CalcSources::forRun().

export interface CalcBatch {
    id: number;
    file: string | null;
    source: 'file' | 'web_add' | 'web_edit' | 'web_delete';
    at: string;
    user: string | null;
    communities: number;
}
export interface CalcSheet {
    label: string;
    columns: string[];
    batches: CalcBatch[];
}
/** sheet key (disaster, welfare, …) → what this round read from it. */
export type CalcSources = Record<string, CalcSheet>;

export interface CalcValue {
    label: string;
    value: string;
    /** Draws the row as the result line. */
    result?: boolean;
}

/** One line of the แทนค่า card: the formula with real numbers. `result` lines are the number shown on the card. */
export interface CalcStep {
    label?: string;
    text: string;
    result?: boolean;
}

const nf = (digits: number) => new Intl.NumberFormat('th-TH', { minimumFractionDigits: digits, maximumFractionDigits: digits });
/** A number for the แทนค่า card, with thousands separators. */
export function num(v: number | null | undefined, digits = 0): string {
    return v === null || v === undefined ? '–' : nf(digits).format(v);
}

/** The sheet keys whose columns include any of the given input columns (lower-case, e.g. "population"). */
export function sheetsWithColumns(sources: CalcSources | null | undefined, columns: string[]): string[] {
    if (!sources) return [];
    return Object.keys(sources).filter((k) => columns.some((c) => sources[k].columns.includes(c)));
}
