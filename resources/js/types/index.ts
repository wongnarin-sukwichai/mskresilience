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
    is_active: boolean;
    is_locked: boolean;
    weights: CriteriaWeights;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
