<?php

namespace App\Scoring;

/**
 * MCR2030 Alignment Evidence Screening per community (MCR2030_Alignment_Evidence_Community_v3.docx). It shows which
 * evidence the system has for each of the 10 Essentials. It is **not a score** (the doc's note 4: not a Disaster
 * Resilience Scorecard).
 *
 * - Each Essential lists evidence topics. A topic is found when any of its variables has data.
 * - Status: every topic found → 'full' (มีข้อมูล), some → 'partial' (มีบางส่วน), none → 'none' (ยังไม่พบข้อมูลประกอบ).
 * - The sentence names only the topics found, following the doc's wording ("ระบบพบข้อมูลเกี่ยวกับ…").
 * - A topic with `naOk` (C4_4 school DRR, which may be N/A) isn't counted against the status when absent.
 * - A topic with `noColumn` (RESPONSIBLE_AGENCY) has no input column in the import template, so it is never
 *   found and is listed as missing data (owner 2026-11-10: E1 can reach at most มีบางส่วน; never invent it).
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

    /**
     * @return array<string, array{label: string, topics: list<array{label: string, columns: list<string>, naOk?: bool, noColumn?: bool}>}>
     */
    public static function essentials(): array
    {
        return [
            'E1' => ['label' => 'การจัดองค์กรเพื่อความรีซิเลียนซ์', 'topics' => [
                ['label' => 'การกำหนดหน่วยงานรับผิดชอบ', 'columns' => ['responsible_agency'], 'noColumn' => true],
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
    public static function forCommunity(array $values): array
    {
        $out = [];
        foreach (self::essentials() as $key => $essential) {
            $found = [];
            $missing = [];
            $counted = 0;
            foreach ($essential['topics'] as $topic) {
                $has = ! ($topic['noColumn'] ?? false)
                    && collect($topic['columns'])->contains(fn ($c) => ($values[$c] ?? null) !== null && ($values[$c] ?? null) !== false);
                if ($has) {
                    $found[] = $topic['label'];
                    $counted++;
                } elseif (! ($topic['naOk'] ?? false)) {
                    $missing[] = $topic['label'].(($topic['noColumn'] ?? false) ? ' (ยังไม่มีช่องกรอกในแบบฟอร์มนำเข้า)' : '');
                    $counted++;
                }
            }
            $status = ! $found ? 'none' : ($missing ? 'partial' : 'full');
            $out[$key] = [
                'status' => $status,
                'found' => $found,
                'missing' => $missing,
                'sentence' => $found ? self::glue('ระบบพบข้อมูลเกี่ยวกับ', self::joinThai($found)) : 'ยังไม่พบข้อมูลประกอบ',
            ];
        }

        return $out;
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
