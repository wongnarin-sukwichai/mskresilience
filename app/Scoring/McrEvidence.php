<?php

namespace App\Scoring;

use App\Enums\WorkGroup;
use App\Import\ImportColumns;

/**
 * MCR2030 Alignment Evidence Screening per community (MCR2030_Alignment_Evidence_Community_v3.docx). It shows which
 * evidence the system has for each of the 10 Essentials. It is **not a score** (the doc's note 4: not a Disaster
 * Resilience Scorecard).
 *
 * - Each Essential lists evidence topics. A topic is found when any of its variables has data.
 * - Status: every topic found → 'full' (มีข้อมูล), some → 'partial' (มีบางส่วน), none → 'none' (ยังไม่พบข้อมูลประกอบ).
 * - The sentence names only the topics found, following the doc's wording ("ระบบพบข้อมูลเกี่ยวกับ…").
 * - A topic with `naOk` (C4_4 school DRR, which may be N/A) isn't counted against the status when absent.
 * - E1 "การกำหนดหน่วยงานรับผิดชอบ" (RESPONSIBLE_AGENCY in the doc) has no column: the client answered (2026-11-23)
 *   that the import is already split by กลุ่มงาน, so check that instead. It is found when the community's data in
 *   the round came in through the responsible กลุ่มงาน (IMPORTED_BY = the list of those groups), and the sentence
 *   names them.
 */
class McrEvidence
{
    private const E_COUNTS = ['e_numerator_count_flood', 'e_numerator_count_fire', 'e_numerator_count_storm', 'e_numerator_count_heat', 'e_numerator_count_pm25'];

    private const V_INFRA = ['v_infra_gap_count_flood', 'v_infra_gap_count_fire', 'v_infra_gap_count_storm', 'v_infra_gap_count_heat', 'v_infra_gap_count_pm25'];

    private const V_PEOPLE = ['v_elderly_count', 'v_child_count', 'v_disabled_count', 'v_low_income_count', 'v_bedridden_count', 'v_ncd_count'];

    private const V_HELP = ['v_medical_dependent_count', 'v_assisted_evacuation_count', 'v_communication_assistance_count'];

    private const C5 = ['c5_1_greenblue_score', 'c5_2_nbs_score', 'c5_3_maintenance_score'];

    private const C8 = ['c8_1_formal_network_count', 'c8_3_network_types_score', 'c8_4_joint_experience_score'];

    /** Pseudo-columns filled from events (any event of the community) and from the engine. */
    public const EVENT_LOSS = 'event:loss';

    public const EVENT_FINANCE_RESPONSE = 'event:f2';

    public const EVENT_FINANCE_RECOVERY = 'event:f3';

    public const EVENT_FINANCE_REDUCTION = 'event:f4';

    public const EVENT_FINANCE_RELIEF = 'event:f5';

    public const EVENT_DAMAGE_VALUE = 'event:l3';

    public const EVENT_OBSERVED = 'event:observed';

    public const HEVC = 'engine:hevc';

    /** The กลุ่มงาน (labels) whose imports supplied the community's data in the round; filled by the controller. */
    public const IMPORTED_BY = 'system:imported_by';

    /**
     * @return array<string, array{label: string, topics: list<array{label: string, columns: list<string>, naOk?: bool}>}>
     */
    public static function essentials(): array
    {
        return [
            'E1' => ['label' => 'การจัดองค์กรเพื่อความรีซิเลียนซ์', 'topics' => [
                ['label' => 'การกำหนดหน่วยงานรับผิดชอบ', 'columns' => [self::IMPORTED_BY]],
                ['label' => 'เครือข่ายที่เป็นทางการ', 'columns' => ['c8_1_formal_network_count']],
                ['label' => 'ความหลากหลายของภาคส่วน', 'columns' => ['c8_3_network_types_score']],
                ['label' => 'ประสบการณ์การทำงานร่วมกันของชุมชน', 'columns' => ['c8_4_joint_experience_score']],
            ]],
            'E2' => ['label' => 'การระบุและเข้าใจความเสี่ยง', 'topics' => [
                ['label' => 'ประชากรพื้นฐาน', 'columns' => ['population', 'households']],
                ['label' => 'การเปิดรับภัย', 'columns' => self::E_COUNTS],
                ['label' => 'กลุ่มเปราะบาง', 'columns' => self::V_PEOPLE],
                ['label' => 'ความต้องการช่วยเหลือ', 'columns' => self::V_HELP],
                ['label' => 'ช่องว่างการเข้าถึงคำเตือน', 'columns' => ['v_warning_access_gap_score']],
                ['label' => 'ช่องว่างโครงสร้าง', 'columns' => self::V_INFRA],
                ['label' => 'คะแนนความเสี่ยงรายภัย/รวม', 'columns' => [self::HEVC]],
                ['label' => 'ผลกระทบจากเหตุการณ์ที่ผ่านมา', 'columns' => [self::EVENT_LOSS]],
            ]],
            'E3' => ['label' => 'ศักยภาพทางการเงิน', 'topics' => [
                ['label' => 'งบเฉลี่ย 3 ปี', 'columns' => ['m_budget_avg_3y']],
                ['label' => 'มูลค่าความเสียหาย', 'columns' => [self::EVENT_DAMAGE_VALUE]],
                ['label' => 'ค่าใช้จ่ายตอบสนอง', 'columns' => [self::EVENT_FINANCE_RESPONSE]],
                ['label' => 'ค่าใช้จ่ายฟื้นฟู', 'columns' => [self::EVENT_FINANCE_RECOVERY]],
                ['label' => 'งบลดความเสี่ยง', 'columns' => [self::EVENT_FINANCE_REDUCTION]],
                ['label' => 'เงินเยียวยา/ชดเชย', 'columns' => [self::EVENT_FINANCE_RELIEF]],
            ]],
            'E4' => ['label' => 'การพัฒนาเมืองที่คำนึงถึงความเสี่ยง', 'topics' => [
                ['label' => 'การเปิดรับภัย', 'columns' => [...self::E_COUNTS, 'area_km2']],
                ['label' => 'ช่องว่างทางกายภาพ', 'columns' => self::V_INFRA],
                ['label' => 'องค์ประกอบ Green–Blue/NbS', 'columns' => self::C5],
            ]],
            'E5' => ['label' => 'การปกป้องระบบนิเวศและแนวกันชนธรรมชาติ', 'topics' => [
                ['label' => 'Green–Blue Infrastructure', 'columns' => ['c5_1_greenblue_score']],
                ['label' => 'การแก้ปัญหาโดยใช้ธรรมชาติเป็นฐาน', 'columns' => ['c5_2_nbs_score']],
                ['label' => 'การดูแลรักษาพื้นที่ดังกล่าว', 'columns' => ['c5_3_maintenance_score']],
            ]],
            'E6' => ['label' => 'ศักยภาพเชิงสถาบัน', 'topics' => [
                ['label' => 'กำลังคน/อาสาสมัคร', 'columns' => ['c4_1_volunteer_per_1000']],
                ['label' => 'การฝึกอบรม', 'columns' => ['c4_2_training_count']],
                ['label' => 'การสื่อสารความเสี่ยง', 'columns' => ['c4_3_risk_comm_score']],
                ['label' => 'เครือข่าย', 'columns' => self::C8],
                ['label' => 'การพัฒนาศักยภาพผ่านสถานศึกษา', 'columns' => ['c4_4_school_drr_education_score'], 'naOk' => true],
            ]],
            'E7' => ['label' => 'ศักยภาพทางสังคม', 'topics' => [
                ['label' => 'ประชากรที่ควรให้ความสำคัญ', 'columns' => self::V_PEOPLE],
                ['label' => 'ความต้องการความช่วยเหลือ', 'columns' => self::V_HELP],
                ['label' => 'อาสาสมัคร', 'columns' => ['c4_1_volunteer_per_1000']],
                ['label' => 'การสื่อสารความเสี่ยง', 'columns' => ['c4_3_risk_comm_score']],
                ['label' => 'ช่องว่างการเข้าถึงคำเตือน', 'columns' => ['v_warning_access_gap_score']],
                ['label' => 'การเรียนรู้ในโรงเรียน', 'columns' => ['c4_4_school_drr_education_score'], 'naOk' => true],
                ['label' => 'การซ้อมเตรียมพร้อม', 'columns' => ['c7_3_drill_score']],
            ]],
            'E8' => ['label' => 'ความรีซิเลียนซ์ของโครงสร้างพื้นฐาน', 'topics' => [
                ['label' => 'ช่องว่างทางกายภาพ', 'columns' => self::V_INFRA],
                ['label' => 'องค์ประกอบที่ช่วยลดความเสี่ยง', 'columns' => self::C5],
                ['label' => 'ความเพียงพอและความพร้อมของพื้นที่ปลอดภัย', 'columns' => ['c7_2_safe_place_score']],
            ]],
            'E9' => ['label' => 'การเตรียมพร้อมและตอบสนอง', 'topics' => [
                ['label' => 'การเข้าถึงคำเตือนและการสื่อสารความเสี่ยง', 'columns' => ['v_warning_access_gap_score', 'c4_3_risk_comm_score']],
                ['label' => 'กำลังคนและการฝึกอบรม', 'columns' => ['c4_1_volunteer_per_1000', 'c4_2_training_count']],
                ['label' => 'ยานพาหนะ/อุปกรณ์และความพร้อม', 'columns' => ['c6_1_response_vehicle_adequacy_score', 'c6_2_lifesaving_score', 'c6_3_readiness_score', 'c6_4_hazard_equipment_adequacy_score']],
                ['label' => 'การอพยพ/พื้นที่ปลอดภัย', 'columns' => ['c7_1_flood_score', 'c7_1_fire_score', 'c7_1_storm_score', 'c7_2_safe_place_score', 'v_assisted_evacuation_count', 'v_communication_assistance_count']],
                ['label' => 'การซ้อม', 'columns' => ['c7_3_drill_score']],
                ['label' => 'การประสานตอบสนอง', 'columns' => [...self::C8, 'c8_2_response_hours']],
            ]],
            'E10' => ['label' => 'การฟื้นฟูและ Build Back Better', 'topics' => [
                ['label' => 'การวางแผนฟื้นฟู', 'columns' => ['r1_recovery_plan_score']],
                ['label' => 'Build Back Better', 'columns' => ['r2_build_back_better_score']],
                ['label' => 'การฟื้นฟูเศรษฐกิจและสังคม', 'columns' => ['r3_socioeconomic_recov_score']],
                ['label' => 'การดูแลกลุ่มเปราะบาง', 'columns' => ['r4_vulner_group_recov_score']],
                ['label' => 'การเรียนรู้และปรับระบบ', 'columns' => ['r5_learning_sys_improv_score']],
                ['label' => 'ผลการฟื้นตัวจากเหตุการณ์', 'columns' => [self::EVENT_OBSERVED]],
                ['label' => 'งบประมาณด้านการเยียวยา ฟื้นฟูและลดความเสี่ยงซ้ำ', 'columns' => [self::EVENT_FINANCE_RECOVERY, self::EVENT_FINANCE_REDUCTION, self::EVENT_FINANCE_RELIEF]],
            ]],
        ];
    }

    /**
     * One community's screening: Essential → status, found topic labels, missing topic labels, sentence.
     *
     * @param  array<string, mixed>  $values  input columns + the pseudo-columns above (true/value when present)
     * @return array<string, array{status: string, found: list<string>, missing: list<string>, sentence: string}>
     */
    public static function forCommunity(array $values, bool $hasEvents = true): array
    {
        $out = [];
        foreach (self::essentials() as $key => $essential) {
            $found = [];
            $foundForSentence = [];
            $missing = [];
            $counted = 0;
            foreach ($essential['topics'] as $topic) {
                $has = collect($topic['columns'])->contains(fn ($c) => ! in_array($values[$c] ?? null, [null, false, []], true));
                // A topic that only events can show (losses, event spending, observed recovery) doesn't count against
                // a community that had no event (owner 2026-11-23): there was nothing to record.
                $eventOnly = collect($topic['columns'])->every(fn ($c) => str_starts_with($c, 'event:'));
                if ($has) {
                    $found[] = $topic['label'];
                    // The sentence names who supplied it: "… การกำหนดหน่วยงานรับผิดชอบ (ข้อมูลนำเข้าโดย งานป้องกันภัย, …)".
                    $agencies = $topic['columns'] === [self::IMPORTED_BY] ? (array) ($values[self::IMPORTED_BY] ?? []) : [];
                    $foundForSentence[] = $topic['label'].($agencies ? ' (ข้อมูลนำเข้าโดย '.implode(', ', $agencies).')' : '');
                    $counted++;
                } elseif ($eventOnly && ! $hasEvents) {
                    continue;
                } elseif (! ($topic['naOk'] ?? false)) {
                    $missing[] = $topic['label'];
                    $counted++;
                }
            }
            $status = ! $found ? 'none' : ($missing ? 'partial' : 'full');
            $out[$key] = [
                'status' => $status,
                // For the coverage gauge only (TOR 4.1.1(3)-2): full 1, partial 0.5, none 0. Not a resilience score.
                'points' => self::POINTS[$status],
                'found' => $found,
                'missing' => $missing,
                'sentence' => $found ? self::glue('ระบบพบข้อมูลเกี่ยวกับ', self::joinThai($foundForSentence)) : 'ยังไม่พบข้อมูลประกอบ',
            ];
        }

        return $out;
    }

    /** Gauge points per status (owner 2026-11-23, option ก). */
    public const POINTS = ['full' => 1.0, 'partial' => 0.5, 'none' => 0.0];

    /**
     * Evidence coverage 0–10 for one community (TOR 4.1.1(3)-2, คุณลักษณะ 2.4-2): the sum of the 10 Essentials'
     * points. Named "ความครอบคลุมของหลักฐาน", never a score of how resilient the community is (doc note 4).
     *
     * @param  array<string, array{points: float}>  $screening  forCommunity()'s result
     */
    public static function coverage(array $screening): float
    {
        return array_sum(array_column($screening, 'points'));
    }

    /**
     * The variable ↔ Essential mapping table (วัตถุประสงค์ข้อ 4, TOR 11.3): every topic of every Essential with the
     * input columns that show it and the กลุ่มงาน sheet each comes from.
     *
     * @return list<array{essential: string, label: string, topic: string, columns: list<array{name: string, source: string}>}>
     */
    public static function mapping(): array
    {
        $sources = [];
        foreach (WorkGroup::cases() as $group) {
            foreach (ImportColumns::for($group) as $column) {
                $sources[strtolower($column)] ??= $group->label();
            }
        }
        $pseudo = [
            self::EVENT_LOSS => ['L1–L5 ความสูญเสียจากเหตุการณ์', WorkGroup::DisasterEvent->label()],
            self::EVENT_DAMAGE_VALUE => ['L3_DAMAGE_VALUE_THB', WorkGroup::DisasterEvent->label()],
            self::EVENT_FINANCE_RESPONSE => ['F2_EVENT_RESPONSE_COST_THB', WorkGroup::DisasterEvent->label()],
            self::EVENT_FINANCE_RECOVERY => ['F3_EVENT_RECOVERY_COST_THB', WorkGroup::DisasterEvent->label()],
            self::EVENT_FINANCE_REDUCTION => ['F4_EVENT_RISK_REDUCTION_BUDGET_THB', WorkGroup::DisasterEvent->label()],
            self::EVENT_FINANCE_RELIEF => ['F5_EVENT_RELIEF_COMPENSATION_THB', WorkGroup::DisasterEvent->label()],
            self::EVENT_OBSERVED => ['R1–R5_OBSERVED, L4_RECOVERY_DAYS', WorkGroup::DisasterEvent->label()],
            self::HEVC => ['คะแนน HEVC รายภัยและรวม', 'ระบบคำนวณ'],
            self::IMPORTED_BY => ['กลุ่มงานที่นำเข้าข้อมูลของชุมชน', 'การแบ่งกลุ่มงานนำเข้าข้อมูล (ประวัตินำเข้า)'],
        ];

        $rows = [];
        foreach (self::essentials() as $key => $essential) {
            foreach ($essential['topics'] as $topic) {
                $rows[] = [
                    'essential' => $key,
                    'label' => $essential['label'],
                    'topic' => $topic['label'],
                    'columns' => array_map(fn ($c) => isset($pseudo[$c])
                        ? ['name' => $pseudo[$c][0], 'source' => $pseudo[$c][1]]
                        : ['name' => strtoupper($c), 'source' => $sources[$c] ?? '–'], $topic['columns']),
                ];
            }
        }

        return $rows;
    }

    /**
     * "ก", "ก และ ข", "ก ข และ ค".
     *
     * @param  list<string>  $items
     */
    private static function joinThai(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);

        return self::glue(implode(' ', $items).' และ', $last);
    }

    /**
     * Thai joins words without a space, but a Latin word (e.g. "Green–Blue") reads better after one.
     */
    private static function glue(string $thai, string $next): string
    {
        return $thai.(preg_match('/^[A-Za-z]/', $next) ? ' ' : '').$next;
    }
}
