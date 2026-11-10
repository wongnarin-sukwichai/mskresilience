import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { status: string | null; error: string | null };
    communityIndex: { code: string; name: string }[];
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export type UserRole = 'admin' | 'staff' | 'member';

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: UserRole;
    /** WorkGroup enum values (app/Enums/WorkGroup.php) — only meaningful for staff (admin imports all, member none). */
    work_groups: string[] | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface ManagedCommunity {
    id: number;
    code: string;
    name: string;
    created_at: string;
    updated_at: string;
}

// เกณฑ์กลาง — structure comes from App\Scoring\CriteriaSchema (sent as the `schema` prop), never re-declared here.
export type CriteriaWeights = Record<string, Record<string, Record<string, number>>>;

export interface CriteriaSection {
    key: string;
    title: string;
    description: string | null;
    layout: 'list' | 'matrix';
    sum: boolean;
    fixed: boolean;
    warnAbove: number | null;
    columns: { key: string; label: string }[];
    rows: { key: string; label: string; cells: Record<string, number | null> }[];
}

export interface CriteriaTab {
    key: string;
    label: string;
    sections: CriteriaSection[];
}

export interface ScoringCriteriaSet {
    id: number;
    name: string;
    /** First ปีงบประมาณ (พ.ศ.) this set applies to. */
    start_fiscal_year: number;
    /** Last fiscal year it covers (the year before the next set starts); null = open-ended. */
    end_fiscal_year: number | null;
    /** Fiscal years with imported data that resolve to this set; non-empty = in use, read-only. */
    used_years: number[];
    is_locked: boolean;
    weights: CriteriaWeights;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

// --- Calc engine output (App\Scoring\RiskEngine) -------------------------------------------------------------
export type HazardKey = 'FLOOD' | 'FIRE' | 'STORM' | 'HEAT' | 'PM25';
export type RiskLevelKey = 'low' | 'moderate' | 'high' | 'very_high';

export interface CriteriaSetRef {
    id: number;
    name: string;
    startFiscalYear: number;
}

/** A population-weighted city average; delta is over the communities that have a previous round. */
export interface CityAverage {
    value: number | null;
    level: RiskLevelKey | null;
    communities: number;
    delta: number | null;
    deltaCommunities: number;
}

export interface CityRisk {
    multi: CityAverage & { dominant: HazardKey | null };
    hazards: Record<HazardKey, CityAverage & { v: number | null; vDelta: number | null; cRaw: number | null; cRawDelta: number | null }>;
    groups: Record<string, number | null>;
    counts: Record<string, number>;
    totalCommunities: number;
    scoredCommunities: number;
    missing: { code: string; name: string; sheets: string[] }[];
    highRiskCommunities: number;
    dataDate: string | null;
    previousDataDate: string | null;
    criteriaSets: CriteriaSetRef[];
    previousCriteriaSets: CriteriaSetRef[];
}

export interface CommunityRiskSummary {
    code: string;
    name: string;
    status: 'ok' | 'missing';
    multi: { hevc: number | null; level: RiskLevelKey | null; dominant: HazardKey | null } | null;
    hazards: Record<HazardKey, { hevc: number | null; level: RiskLevelKey | null }> | null;
}

export interface HazardResult {
    hevc: number | null;
    level: RiskLevelKey | null;
    e: number | null;
    ePercent: number | null;
    v: number | null;
    cRaw: number | null;
    cHevc: number | null;
}

/** One community as page 3 receives it (CommunityMapController). */
export interface CommunityRiskDetail {
    code: string;
    name: string;
    status: 'ok' | 'missing';
    missingSheets: string[];
    dataDate: string | null;
    criteriaSet: CriteriaSetRef | null;
    population: number | null;
    households: number | null;
    areaKm2: number | null;
    responseHours: number | null;
    counts: Record<string, number | null>;
    multi: { hevc: number | null; level: RiskLevelKey | null; dominant: HazardKey | null } | null;
    hazards: Record<HazardKey, HazardResult> | null;
    groups: Record<string, number | null> | null;
    recovery: number | null;
    previous: { dataDate: string; multi: number | null; hazards: Record<HazardKey, number | null> } | null;
}

/** City-level block for page 4 (RiskFactorsController). */
export interface CityFactors {
    dominant: HazardKey | null;
    hazards: CityRisk['hazards'];
    exposure: Record<HazardKey, { count: number; percent: number | null; score: number | null }>;
    groups: Record<string, number | null>;
    groupDeltas: Record<string, number | null>;
    counts: Record<string, number>;
    scoredCommunities: number;
    totalCommunities: number;
    dataDate: string | null;
    previousDataDate: string | null;
    criteriaSets: CriteriaSetRef[];
}

/** One community as page 4 receives it. */
export interface CommunityFactors {
    code: string;
    name: string;
    status: 'ok' | 'missing';
    missingSheets: string[];
    dataDate: string | null;
    criteriaSet: CriteriaSetRef | null;
    population: number | null;
    counts: Record<string, number | null>;
    hazards: Record<HazardKey, HazardResult> | null;
    groups: Record<string, number | null> | null;
    previous: {
        dataDate: string;
        groups: Record<string, number | null>;
        hazards: Record<HazardKey, { v: number | null; cRaw: number | null }>;
    } | null;
}

/** One event as page 5 receives it (ImpactRecoveryController). */
export interface ImpactEvent {
    id: string;
    code: string;
    name: string;
    start: string;
    end: string | null;
    fiscalYear: number;
    hazard: HazardKey;
    related: HazardKey[];
    multi: boolean;
    description: string | null;
    loss: {
        houses: number | null;
        injury: number | null;
        death: number | null;
        damageThb: number | null;
        recoveryDays: number | null;
        repeat: number | null;
    };
    finance: { F1: number | null; F2: number | null; F3: number | null; F4: number | null; F5: number | null; importedF1Mismatch: boolean };
    observed: { items: Record<string, number | null>; avg: number | null };
    readiness: { date: string; items: Record<string, number | null>; avg: number | null } | null;
    criteriaSet: string | null;
}

// --- Page 6 (Mcr2030KpiController) -----------------------------------------------------------------------------
export type KpiKind = 'score' | 'percent' | 'pending';

export interface KpiDefinition {
    agency: string;
    label: string;
    kind: KpiKind;
    formula: string;
}

export interface KpiValue {
    score: number | null;
    raw: number | null;
    previous: number | null;
    updated: string | null;
    hasData: boolean;
    delta?: number | null;
    communities?: number;
}

export type McrStatus = 'full' | 'partial' | 'none';

export interface McrCommunityEvidence {
    status: McrStatus;
    found: string[];
    missing: string[];
    sentence: string;
}

export interface McrCityEvidence {
    full: number;
    partial: number;
    none: number;
    found: string[];
    missing: string[];
}
