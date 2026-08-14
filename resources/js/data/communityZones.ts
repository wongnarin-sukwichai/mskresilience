import { Delaunay } from 'd3-delaunay';
// polygon-clipping's ESM build only has a default export (an object of the named functions) despite its
// .d.ts declaring named exports — `import { intersection }` type-checks but is `undefined` at runtime.
// The default import gets the real value; the `import type *` namespace import is only for referencing
// PC.Ring/PC.Polygon/PC.Pair as types (a default import alone can't be used as a type qualifier).
import type * as PC from 'polygon-clipping';
import polygonClipping from 'polygon-clipping';
import { communities, MUNICIPALITY_BOUNDARY, type Community } from './mahasarakham';

// PLACEHOLDER GEODATA — none of this is a real ชุมชน boundary. The municipality does not publish
// per-community boundary shapefiles (see the "Sourcing real geodata" note in CLAUDE.md); the project
// owner has requested one, and this file exists only to fill the gap visually until it arrives.
//
// Approach: a Voronoi diagram over the 32 (already-approximate) community center points in
// mahasarakham.ts, each cell clipped to the real MUNICIPALITY_BOUNDARY polygon. This produces
// area-like zones that tile the real municipal boundary with no gaps/overlaps — useful for an
// area-shaded risk map — but the cell edges are mathematical artifacts of the point layout, not
// surveyed community lines. Replace this whole file (delete the Voronoi/clipping logic, hardcode the
// real ring coordinates instead) the moment a real shapefile is available; don't leave this silently
// masquerading as real data past that point.

function closeRing(ring: PC.Ring): PC.Ring {
    const [firstX, firstY] = ring[0];
    const [lastX, lastY] = ring[ring.length - 1];
    if (firstX !== lastX || firstY !== lastY) return [...ring, ring[0]];
    return ring;
}

// polygon-clipping/GeoJSON convention is [lng, lat]; MUNICIPALITY_BOUNDARY is stored [lat, lng] for
// Leaflet, so this file flips coordinate order at its boundaries and keeps [lng, lat] internally.
const boundaryRing = closeRing(MUNICIPALITY_BOUNDARY.map(([lat, lng]) => [lng, lat]));
const boundaryPolygon: PC.Polygon = [boundaryRing];

const lats = MUNICIPALITY_BOUNDARY.map(([lat]) => lat);
const lngs = MUNICIPALITY_BOUNDARY.map(([, lng]) => lng);
const MARGIN_DEG = 0.02; // generous margin so edge Voronoi cells fully cover the boundary before clipping
const voronoiBounds: [number, number, number, number] = [
    Math.min(...lngs) - MARGIN_DEG,
    Math.min(...lats) - MARGIN_DEG,
    Math.max(...lngs) + MARGIN_DEG,
    Math.max(...lats) + MARGIN_DEG,
];

const delaunay = Delaunay.from(
    communities,
    (c) => c.lng,
    (c) => c.lat,
);
const voronoi = delaunay.voronoi(voronoiBounds);

export interface CommunityZone {
    id: number;
    name: string;
    activeRisk: Community['activeRisk'];
    lat: number;
    lng: number;
    /** [lat, lng] ring, ready for L.polygon — empty if clipping failed to produce a cell. */
    polygon: [number, number][];
}

export const communityZones: CommunityZone[] = communities.map((community, i) => {
    const cell = voronoi.cellPolygon(i);
    let polygon: [number, number][] = [];

    if (cell) {
        const cellRing = closeRing(cell.map(([lng, lat]) => [lng, lat] as PC.Pair));
        const clipped = polygonClipping.intersection([cellRing], boundaryPolygon);
        if (clipped.length > 0) {
            // A convex Voronoi cell clipped to a concave boundary can split into multiple pieces —
            // keep the largest by vertex count (a reasonable proxy for area here).
            const largest = clipped.reduce((a, b) => (a[0].length >= b[0].length ? a : b));
            polygon = largest[0].map(([lng, lat]) => [lat, lng]);
        }
    }

    return {
        id: community.id,
        name: community.name,
        activeRisk: community.activeRisk,
        lat: community.lat,
        lng: community.lng,
        polygon,
    };
});
