import { ref } from 'vue';
import { communities, type Community } from './mahasarakham';
import { calculateRecoveryScore, defaultCriteria } from './mockScoringCriteria';

// Shared mock event register — maps to the เหตุการณ์_LD sheet. Field set is the 25 independently-entered
// columns identified when analyzing the source workbook; COMMUNITY_NAME, MULTI_HAZARD_FLAG, and
// R_OBSERVED_SCORE are the 3 derived columns (denormalized lookup / computed from RELATED_HAZARDS /
// computed from the Recovery weights) — deliberately NOT stored here, see the compute* helpers below.
// Both Dashboard.vue (recent-events preview) and EventsLD.vue (full register) read this same ref, so
// "ดูทั้งหมด" on the dashboard shows a superset of what the preview already displayed.

export type VerificationStatus = 'ยืนยันแล้ว' | 'กำลังตรวจสอบ';

export interface EventRecord {
    id: string; // EVENT_ID
    communityId: number; // COMMUNITY_ID (FK)
    eventStart: string; // ISO date
    eventEnd: string; // ISO date
    primaryHazard: Community['activeRisk'];
    relatedHazards: Community['activeRisk'][];
    description: string;
    l1HousesDamaged: number;
    l2Injuries: number;
    l2Deaths: number;
    l3DamageValueThb: number;
    l4RecoveryDays: number;
    l5RepeatDamage: boolean;
    r1Observed: number;
    r2Observed: number;
    r3Observed: number;
    r4Observed: number;
    r5Observed: number;
    source: string;
    evidence: string;
    dataDate: string;
    responsibleAgency: string;
    verificationStatus: VerificationStatus;
    dataQuality: string;
    notes: string;
}

export const events = ref<EventRecord[]>([
    {
        id: 'EV0001',
        communityId: 2,
        eventStart: '2026-08-12',
        eventEnd: '2026-08-13',
        primaryHazard: 'flood',
        relatedHazards: ['wind'],
        description: 'ฝนตกหนักต่อเนื่องร่วมกับพายุ ทำให้ระบบระบายน้ำล้นและน้ำเข้าบ้านเรือนพื้นที่ลุ่มต่ำ',
        l1HousesDamaged: 42,
        l2Injuries: 2,
        l2Deaths: 0,
        l3DamageValueThb: 3850000,
        l4RecoveryDays: 18,
        l5RepeatDamage: true,
        r1Observed: 4,
        r2Observed: 3,
        r3Observed: 3,
        r4Observed: 4,
        r5Observed: 3,
        source: 'ฝ่ายป้องกันและบรรเทาสาธารณภัย',
        evidence: 'EVD-2026-001',
        dataDate: '2026-08-14',
        responsibleAgency: 'ฝ่ายป้องกันและบรรเทาสาธารณภัย',
        verificationStatus: 'ยืนยันแล้ว',
        dataQuality: 'พร้อมใช้เป็นค่าร่วม',
        notes: '',
    },
    {
        id: 'EV0002',
        communityId: 1,
        eventStart: '2026-08-11',
        eventEnd: '2026-08-11',
        primaryHazard: 'fire',
        relatedHazards: [],
        description: 'เกิดเพลิงไหม้บ้านพักอาศัยจากไฟฟ้าลัดวงจรในชุมชนที่มีทางเข้าออกค่อนข้างแคบ',
        l1HousesDamaged: 7,
        l2Injuries: 3,
        l2Deaths: 0,
        l3DamageValueThb: 1260000,
        l4RecoveryDays: 12,
        l5RepeatDamage: false,
        r1Observed: 3,
        r2Observed: 2,
        r3Observed: 3,
        r4Observed: 4,
        r5Observed: 4,
        source: 'งานป้องกันและระงับอัคคีภัย',
        evidence: 'EVD-2026-002',
        dataDate: '2026-08-12',
        responsibleAgency: 'งานป้องกันและระงับอัคคีภัย',
        verificationStatus: 'กำลังตรวจสอบ',
        dataQuality: 'คำเตือน',
        notes: 'รอหลักฐานภาพถ่ายเพิ่มเติม',
    },
    {
        id: 'EV0003',
        communityId: 6,
        eventStart: '2026-08-10',
        eventEnd: '2026-08-10',
        primaryHazard: 'wind',
        relatedHazards: [],
        description: 'ลมกระโชกแรงพัดต้นไม้และป้ายโฆษณาล้มทับบ้านเรือนและสายไฟ',
        l1HousesDamaged: 15,
        l2Injuries: 1,
        l2Deaths: 0,
        l3DamageValueThb: 890000,
        l4RecoveryDays: 7,
        l5RepeatDamage: false,
        r1Observed: 4,
        r2Observed: 3,
        r3Observed: 4,
        r4Observed: 3,
        r5Observed: 3,
        source: 'ฝ่ายป้องกันและบรรเทาสาธารณภัย',
        evidence: 'EVD-2026-003',
        dataDate: '2026-08-11',
        responsibleAgency: 'ฝ่ายป้องกันและบรรเทาสาธารณภัย',
        verificationStatus: 'ยืนยันแล้ว',
        dataQuality: 'พร้อมใช้เป็นค่าร่วม',
        notes: '',
    },
    {
        id: 'EV0004',
        communityId: 8,
        eventStart: '2026-08-09',
        eventEnd: '2026-08-12',
        primaryHazard: 'heat',
        relatedHazards: [],
        description: 'คลื่นความร้อนต่อเนื่อง 4 วัน อุณหภูมิสูงสุด 41.5°C ผู้สูงอายุเข้ารักษาภาวะฮีทสโตรก',
        l1HousesDamaged: 0,
        l2Injuries: 4,
        l2Deaths: 0,
        l3DamageValueThb: 0,
        l4RecoveryDays: 4,
        l5RepeatDamage: true,
        r1Observed: 3,
        r2Observed: 3,
        r3Observed: 3,
        r4Observed: 3,
        r5Observed: 2,
        source: 'สาธารณสุข',
        evidence: 'EVD-2026-004',
        dataDate: '2026-08-13',
        responsibleAgency: 'กองสาธารณสุขและสิ่งแวดล้อม',
        verificationStatus: 'ยืนยันแล้ว',
        dataQuality: 'พร้อมใช้เป็นค่าร่วม',
        notes: '',
    },
    {
        id: 'EV0005',
        communityId: 4,
        eventStart: '2026-08-08',
        eventEnd: '2026-08-08',
        primaryHazard: 'pm25',
        relatedHazards: [],
        description: 'ค่าฝุ่น PM2.5 เกินมาตรฐานจากการเผาในที่โล่งรอบเมือง',
        l1HousesDamaged: 0,
        l2Injuries: 0,
        l2Deaths: 0,
        l3DamageValueThb: 0,
        l4RecoveryDays: 1,
        l5RepeatDamage: true,
        r1Observed: 2,
        r2Observed: 2,
        r3Observed: 2,
        r4Observed: 2,
        r5Observed: 3,
        source: 'กองสาธารณสุขและสิ่งแวดล้อม',
        evidence: 'EVD-2026-005',
        dataDate: '2026-08-09',
        responsibleAgency: 'กองสาธารณสุขและสิ่งแวดล้อม',
        verificationStatus: 'กำลังตรวจสอบ',
        dataQuality: 'คำเตือน',
        notes: '',
    },
    {
        id: 'EV0006',
        communityId: 10,
        eventStart: '2026-06-02',
        eventEnd: '2026-06-03',
        primaryHazard: 'flood',
        relatedHazards: [],
        description: 'น้ำท่วมขังจากฝนตกหนักในพื้นที่ลุ่มต่ำ',
        l1HousesDamaged: 9,
        l2Injuries: 0,
        l2Deaths: 0,
        l3DamageValueThb: 420000,
        l4RecoveryDays: 5,
        l5RepeatDamage: false,
        r1Observed: 4,
        r2Observed: 4,
        r3Observed: 3,
        r4Observed: 4,
        r5Observed: 4,
        source: 'ฝ่ายป้องกันและบรรเทาสาธารณภัย',
        evidence: 'EVD-2026-006',
        dataDate: '2026-06-05',
        responsibleAgency: 'ฝ่ายป้องกันและบรรเทาสาธารณภัย',
        verificationStatus: 'ยืนยันแล้ว',
        dataQuality: 'พร้อมใช้เป็นค่าร่วม',
        notes: '',
    },
    {
        id: 'EV0007',
        communityId: 16,
        eventStart: '2026-04-20',
        eventEnd: '2026-04-20',
        primaryHazard: 'fire',
        relatedHazards: [],
        description: 'เพลิงไหม้ร้านค้าในตลาดชุมชนจากการจุดธูปเทียนบูชา',
        l1HousesDamaged: 3,
        l2Injuries: 0,
        l2Deaths: 0,
        l3DamageValueThb: 560000,
        l4RecoveryDays: 9,
        l5RepeatDamage: false,
        r1Observed: 3,
        r2Observed: 3,
        r3Observed: 3,
        r4Observed: 3,
        r5Observed: 3,
        source: 'งานป้องกันและระงับอัคคีภัย',
        evidence: 'EVD-2026-007',
        dataDate: '2026-04-22',
        responsibleAgency: 'งานป้องกันและระงับอัคคีภัย',
        verificationStatus: 'ยืนยันแล้ว',
        dataQuality: 'พร้อมใช้เป็นค่าร่วม',
        notes: '',
    },
    {
        id: 'EV0008',
        communityId: 12,
        eventStart: '2026-02-14',
        eventEnd: '2026-02-14',
        primaryHazard: 'pm25',
        relatedHazards: [],
        description: 'ฝุ่น PM2.5 เกินมาตรฐานช่วงหมอกควันภาคเหนือลอยเข้าพื้นที่',
        l1HousesDamaged: 0,
        l2Injuries: 1,
        l2Deaths: 0,
        l3DamageValueThb: 0,
        l4RecoveryDays: 2,
        l5RepeatDamage: true,
        r1Observed: 2,
        r2Observed: 2,
        r3Observed: 2,
        r4Observed: 3,
        r5Observed: 2,
        source: 'กองสาธารณสุขและสิ่งแวดล้อม',
        evidence: 'EVD-2026-008',
        dataDate: '2026-02-16',
        responsibleAgency: 'กองสาธารณสุขและสิ่งแวดล้อม',
        verificationStatus: 'ยืนยันแล้ว',
        dataQuality: 'พร้อมใช้เป็นค่าร่วม',
        notes: '',
    },
]);

// ── Derived fields — computed on read, never stored ──────────────────────
export function communityName(communityId: number): string {
    return communities.find((c) => c.id === communityId)?.name ?? 'ไม่พบชุมชน';
}

export function isMultiHazard(event: EventRecord): boolean {
    return event.relatedHazards.length > 0;
}

export function eventRecoveryScore(event: EventRecord): number {
    return calculateRecoveryScore(defaultCriteria().weights, {
        r1: event.r1Observed,
        r2: event.r2Observed,
        r3: event.r3Observed,
        r4: event.r4Observed,
        r5: event.r5Observed,
    });
}

export function nextEventId(): string {
    const maxNum = Math.max(0, ...events.value.map((e) => parseInt(e.id.replace('EV', ''), 10) || 0));
    return `EV${String(maxNum + 1).padStart(4, '0')}`;
}
