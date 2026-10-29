import { communityBoundaries } from './communityBoundaries';
import { communities, type Community } from './mahasarakham';

// Real ชุมชน boundary polygons — see communityBoundaries.ts for provenance (a digitized shapefile
// the project owner supplied, matched to `communities[].id` by name). This file used to generate a
// Voronoi-diagram approximation over the community center points, clipped to MUNICIPALITY_BOUNDARY,
// as a stand-in until the real shapefile arrived; that generation logic is gone now that it has.

export interface CommunityZone {
    id: number;
    name: string;
    activeRisk: Community['activeRisk'];
    lat: number;
    lng: number;
    /** One [lat, lng] ring per polygon part, ready for L.polygon() — empty if no boundary data. */
    polygon: [number, number][][];
}

const ringsById = new Map(communityBoundaries.map((b) => [b.id, b.rings]));

export const communityZones: CommunityZone[] = communities.map((community) => ({
    id: community.id,
    name: community.name,
    activeRisk: community.activeRisk,
    lat: community.lat,
    lng: community.lng,
    polygon: ringsById.get(community.id) ?? [],
}));
