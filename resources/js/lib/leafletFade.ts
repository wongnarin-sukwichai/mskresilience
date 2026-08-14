import type L from 'leaflet';

// Shared fade-in/fade-out for toggling Leaflet FeatureGroups (used by both the public map's hazard
// filter in Welcome.vue and the admin risk map's filter in Dashboard.vue) — Leaflet has no built-in
// transition for vector layers (that's a raster tile-layer-only feature), so this reaches into each
// layer's underlying DOM element and drives a plain CSS opacity transition.
//
// `getElement()` is the official API for Marker/DivOverlay-based layers; `_path` is the private (but
// long-stable and widely relied-upon) property Path-based layers (Polygon/Polyline/Circle) use to hold
// their SVG element when rendered with the default SVG renderer — there's no public equivalent for
// those. Guarded with optional chaining so a future Leaflet version that removes `_path` just silently
// skips the animation (layers would still show/hide correctly, just without the fade).

const FADE_MS = 300;

function fadableElement(layer: L.Layer): HTMLElement | null {
    const withElement = layer as unknown as { getElement?: () => HTMLElement | undefined; _path?: HTMLElement };
    return withElement.getElement?.() ?? withElement._path ?? null;
}

export function fadeInGroup(group: L.FeatureGroup, map: L.Map) {
    group.addTo(map);
    group.eachLayer((layer) => {
        const el = fadableElement(layer);
        if (!el) return;
        el.style.transition = 'none';
        el.style.opacity = '0';
        void el.offsetWidth; // force a reflow so the browser registers opacity:0 before animating to 1
        el.style.transition = `opacity ${FADE_MS}ms ease`;
        el.style.opacity = '1';
    });
}

/**
 * @param shouldRemove Re-checked when the fade finishes — guards against a group that got toggled back
 *   on mid-fade being yanked off the map by a stale removal from the earlier fade-out.
 */
export function fadeOutGroup(group: L.FeatureGroup, map: L.Map, shouldRemove: () => boolean = () => true) {
    group.eachLayer((layer) => {
        const el = fadableElement(layer);
        if (!el) return;
        el.style.transition = `opacity ${FADE_MS}ms ease`;
        el.style.opacity = '0';
    });
    window.setTimeout(() => {
        if (shouldRemove()) map.removeLayer(group);
    }, FADE_MS);
}
