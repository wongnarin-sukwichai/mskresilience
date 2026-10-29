// FontAwesome icon + card color theme per กลุ่มงาน (App\Enums\WorkGroup values). Labels/sheet names come
// from the server (WorkGroup::options()) — only presentation lives here. Class strings are written out in
// full (not built from a color name) so Tailwind's content scan picks them up.
export const workGroupIcons: Record<string, string> = {
    disaster_event: 'fa-solid fa-house-flood-water',
    disaster: 'fa-solid fa-shield-halved',
    health: 'fa-solid fa-briefcase-medical',
    welfare: 'fa-solid fa-hand-holding-heart',
    education: 'fa-solid fa-graduation-cap',
    engineering: 'fa-solid fa-helmet-safety',
    public_relations: 'fa-solid fa-bullhorn',
    kpi: 'fa-solid fa-chart-line',
};

export interface WorkGroupTheme {
    /** Card background gradient (fades from white into the group color, bottom-right). */
    card: string;
    /** Colored drop shadow under the card (tinted to the group color). */
    shadow: string;
    /** Small icon tile, top-left. */
    tile: string;
    /** Large decorative icon, bottom-right. */
    deco: string;
    /** Soft blurred color blob behind the decorative icon. */
    glow: string;
}

export const workGroupThemes: Record<string, WorkGroupTheme> = {
    disaster_event: { card: 'to-sky-100', shadow: 'shadow-sky-200/60', tile: 'bg-sky-100 text-sky-600', deco: 'text-sky-300', glow: 'bg-sky-300' },
    disaster: {
        card: 'to-orange-100',
        shadow: 'shadow-orange-200/60',
        tile: 'bg-orange-100 text-orange-500',
        deco: 'text-orange-300',
        glow: 'bg-orange-300',
    },
    health: {
        card: 'to-emerald-100',
        shadow: 'shadow-emerald-200/60',
        tile: 'bg-emerald-100 text-emerald-600',
        deco: 'text-emerald-300',
        glow: 'bg-emerald-300',
    },
    welfare: { card: 'to-pink-100', shadow: 'shadow-pink-200/60', tile: 'bg-pink-100 text-pink-500', deco: 'text-pink-300', glow: 'bg-pink-300' },
    education: {
        card: 'to-violet-100',
        shadow: 'shadow-violet-200/60',
        tile: 'bg-violet-100 text-violet-600',
        deco: 'text-violet-300',
        glow: 'bg-violet-300',
    },
    engineering: {
        card: 'to-amber-100',
        shadow: 'shadow-amber-200/60',
        tile: 'bg-amber-100 text-amber-500',
        deco: 'text-amber-300',
        glow: 'bg-amber-300',
    },
    public_relations: {
        card: 'to-blue-100',
        shadow: 'shadow-blue-200/60',
        tile: 'bg-blue-100 text-blue-600',
        deco: 'text-blue-300',
        glow: 'bg-blue-300',
    },
    kpi: {
        card: 'to-purple-100',
        shadow: 'shadow-purple-200/60',
        tile: 'bg-purple-100 text-purple-600',
        deco: 'text-purple-300',
        glow: 'bg-purple-300',
    },
};

export const defaultWorkGroupTheme: WorkGroupTheme = {
    card: 'to-slate-100',
    shadow: 'shadow-slate-200/60',
    tile: 'bg-slate-100 text-slate-600',
    deco: 'text-slate-300',
    glow: 'bg-slate-300',
};
