<?php

namespace App\Scoring;

/**
 * KPI รายหน่วยงาน, spec v5 §5.6–5.9. Each KPI is a 0–5 score where 5 is always better (spec "ทิศทางคะแนน KPI").
 * Owner decisions (2026-11-10):
 * - KPI_C7 follows the spec formula as written: AVG(C7_1_FLOOD, C7_1_FIRE, C7_2), without STORM. Storm is still
 *   to be confirmed with the client.
 * - The low-income KPI has no usable formula yet, so it is 'pending'.
 * - Percent KPIs use the §5.7 band, which the spec calls a test default (Bands::percent has the same bands).
 * - City level: score KPIs are population-weighted (like pages 2–4); percent KPIs pool Σnumerator / Σdenominator
 *   per group across the city before averaging the groups.
 *
 * Inputs are table columns (lower-case headers) from the assessment sheets plus kpi_records. Null = N/A or missing.
 */
class KpiCalculator
{
    public const SCORE = 'score';

    public const PERCENT = 'percent';

    public const PENDING = 'pending';

    /**
     * key → agency, label, kind, formula text, source columns. Percent KPIs list [numerator, denominator] pairs.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'KPI_C6' => ['agency' => 'งาน ปภ.', 'label' => 'ความพร้อมทรัพยากรตอบโต้เหตุ', 'kind' => self::SCORE,
                'formula' => 'เฉลี่ยคะแนน C6_1–C6_4 (C6_3 ร้อยละ แปลงเป็น 0–5 ก่อน) ตัด N/A ออก',
                'columns' => ['c6_1_response_vehicle_adequacy_score', 'c6_2_lifesaving_score', 'c6_3_readiness_score', 'c6_4_hazard_equipment_adequacy_score']],
            'KPI_C7' => ['agency' => 'งาน ปภ.', 'label' => 'ความพร้อมอพยพและพื้นที่ปลอดภัย', 'kind' => self::SCORE,
                'formula' => 'เฉลี่ย C7_1_FLOOD, C7_1_FIRE, C7_2 ตัด N/A ออก (ตามสเปก v5 — รอยืนยันเรื่องวาตภัย)',
                'columns' => ['c7_1_flood_score', 'c7_1_fire_score', 'c7_2_safe_place_score']],
            'KPI_C7_3' => ['agency' => 'งาน ปภ.', 'label' => 'การแก้ไขหลังการซ้อม', 'kind' => self::SCORE,
                'formula' => 'ใช้ C7_3 โดยตรง', 'columns' => ['c7_3_drill_score']],
            'KPI_C8_2' => ['agency' => 'งาน ปภ.', 'label' => 'เวลาตอบสนอง', 'kind' => self::SCORE,
                'formula' => 'แปลงชั่วโมง: ≤1=5, >1–3=4, >3–6=3, >6–12=2, >12–24=1, >24=0', 'columns' => ['c8_2_response_hours']],
            'KPI_C4_3' => ['agency' => 'ประชาสัมพันธ์', 'label' => 'การรับรู้และเข้าใจคำเตือน', 'kind' => self::SCORE,
                'formula' => 'ใช้ C4_3 โดยตรง', 'columns' => ['c4_3_risk_comm_score']],
            'KPI_WARNING' => ['agency' => 'ประชาสัมพันธ์', 'label' => 'การลดช่องว่างการเข้าถึงคำเตือน', 'kind' => self::SCORE,
                'formula' => '5 − V_WARNING_ACCESS_GAP_SCORE', 'columns' => ['v_warning_access_gap_score']],
            'KPI_LOW_INCOME' => ['agency' => 'กองสวัสดิการสังคม', 'label' => 'การลดความเปราะบางด้านรายได้', 'kind' => self::PENDING,
                'formula' => 'คำนวณ % ผู้ที่พ้นเกณฑ์จากผู้ติดตามได้ แล้วเทียบเกณฑ์ 0–5 — รอกำหนดตัวหารที่ชัดเจน',
                'columns' => ['kpi_low_income_exit_verified_count', 'kpi_low_income_followup_count', 'v_low_income_count']],
            'KPI_VULNERABLE' => ['agency' => 'กองสวัสดิการสังคม', 'label' => 'การคุ้มครองกลุ่มเปราะบาง', 'kind' => self::PERCENT,
                'formula' => 'เฉลี่ย % มีแผนของผู้สูงอายุ ผู้พิการ เด็ก → เทียบเกณฑ์ 0–5',
                'pairs' => [['kpi_elderly_plan_count', 'v_elderly_count'], ['kpi_disabled_plan_count', 'v_disabled_count'], ['kpi_child_plan_count', 'v_child_count']]],
            'KPI_MEDICAL' => ['agency' => 'สำนักสาธารณสุขและสิ่งแวดล้อม', 'label' => 'ความพร้อมผู้พึ่งพาบริการแพทย์', 'kind' => self::PERCENT,
                'formula' => 'เฉลี่ย % พร้อมของผู้พึ่งพาบริการแพทย์และผู้ป่วยติดเตียง → เทียบเกณฑ์ 0–5',
                'pairs' => [['kpi_medical_plan_ready_count', 'v_medical_dependent_count'], ['kpi_bedridden_plan_ready_count', 'v_bedridden_count']]],
            'KPI_ASSIST' => ['agency' => 'สำนักสาธารณสุขและสิ่งแวดล้อม', 'label' => 'ความพร้อมช่วยอพยพ', 'kind' => self::PERCENT,
                'formula' => '% ผู้ต้องช่วยอพยพที่มีผู้รับผิดชอบและแผน → เทียบเกณฑ์ 0–5',
                'pairs' => [['kpi_assisted_evac_ready_count', 'v_assisted_evacuation_count']]],
            'KPI_INFRA_FLOOD' => ['agency' => 'สำนักช่าง', 'label' => 'การลดช่องว่างโครงสร้างน้ำท่วม', 'kind' => self::SCORE,
                'formula' => '5 − V_INFRA_FLOOD', 'columns' => ['v_infra_gap_count_flood']],
            'KPI_INFRA_FIRE' => ['agency' => 'สำนักช่าง', 'label' => 'การลดช่องว่างโครงสร้างอัคคีภัย', 'kind' => self::SCORE,
                'formula' => '5 − V_INFRA_FIRE', 'columns' => ['v_infra_gap_count_fire']],
            'KPI_C5' => ['agency' => 'สำนักช่าง', 'label' => 'ความพร้อมใช้งาน Green-Blue / NbS', 'kind' => self::SCORE,
                'formula' => '0.35 C5_1 + 0.35 C5_2 + 0.30 C5_3 (ตัด N/A ออกและปรับน้ำหนัก)',
                'columns' => ['c5_1_greenblue_score', 'c5_2_nbs_score', 'c5_3_maintenance_score']],
            'KPI_C4_4' => ['agency' => 'กองการศึกษา', 'label' => 'ความพร้อมด้าน DRR ของโรงเรียน', 'kind' => self::SCORE,
                'formula' => 'ใช้ C4_4 โดยตรง', 'columns' => ['c4_4_school_drr_education_score']],
        ];
    }

    /**
     * One community's KPIs: key → [score, raw]. `raw` is the value before banding (hours, %, …) when there is one.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, array{score: float|null, raw: float|null}>
     */
    public static function forCommunity(array $in): array
    {
        $n = fn (string $col) => isset($in[$col]) && $in[$col] !== '' ? (float) $in[$col] : null;
        $avg = function (array $values): ?float {
            $values = array_filter($values, fn ($v) => $v !== null);

            return $values ? array_sum($values) / count($values) : null;
        };
        $minus = fn (?float $v) => $v === null ? null : 5 - $v;

        $out = [];
        foreach (self::definitions() as $key => $def) {
            $out[$key] = match ($key) {
                'KPI_C6' => ['score' => $avg([$n('c6_1_response_vehicle_adequacy_score'), $n('c6_2_lifesaving_score'),
                    Bands::percent($n('c6_3_readiness_score')), $n('c6_4_hazard_equipment_adequacy_score')]), 'raw' => null],
                'KPI_C7' => ['score' => $avg([$n('c7_1_flood_score'), $n('c7_1_fire_score'), $n('c7_2_safe_place_score')]), 'raw' => null],
                'KPI_C7_3' => ['score' => $n('c7_3_drill_score'), 'raw' => null],
                'KPI_C8_2' => ['score' => self::float(Bands::responseHours($n('c8_2_response_hours'))), 'raw' => $n('c8_2_response_hours')],
                'KPI_C4_3' => ['score' => $n('c4_3_risk_comm_score'), 'raw' => null],
                'KPI_WARNING' => ['score' => $minus($n('v_warning_access_gap_score')), 'raw' => $n('v_warning_access_gap_score')],
                'KPI_INFRA_FLOOD' => ['score' => $minus($n('v_infra_gap_count_flood')), 'raw' => $n('v_infra_gap_count_flood')],
                'KPI_INFRA_FIRE' => ['score' => $minus($n('v_infra_gap_count_fire')), 'raw' => $n('v_infra_gap_count_fire')],
                'KPI_C5' => ['score' => self::weighted([[0.35, $n('c5_1_greenblue_score')], [0.35, $n('c5_2_nbs_score')], [0.30, $n('c5_3_maintenance_score')]]), 'raw' => null],
                'KPI_C4_4' => ['score' => $n('c4_4_school_drr_education_score'), 'raw' => null],
                'KPI_LOW_INCOME' => ['score' => null, 'raw' => null],
                default => self::percentKpi($def['pairs'], fn (string $col) => $n($col)),
            };
        }

        return $out;
    }

    /**
     * Percent KPI from [numerator, denominator] pairs (spec §5.9): each group's % (denominator 0 → that group is
     * N/A), averaged over the groups that have one, then banded 0–5.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @param  callable(string): ?float  $value
     * @return array{score: float|null, raw: float|null}
     */
    public static function percentKpi(array $pairs, callable $value): array
    {
        $percents = [];
        foreach ($pairs as [$num, $den]) {
            $a = $value($num);
            $b = $value($den);
            if ($a !== null && $b !== null && $b > 0) {
                $percents[] = $a / $b * 100;
            }
        }
        if (! $percents) {
            return ['score' => null, 'raw' => null];
        }
        $pct = array_sum($percents) / count($percents);

        return ['score' => (float) Bands::percent($pct), 'raw' => $pct];
    }

    /**
     * Whether any of a percent KPI's numerators has data (otherwise the page says "รอข้อมูล KPI").
     *
     * @param  array<string, mixed>  $in
     */
    public static function hasKpiData(string $key, array $in): bool
    {
        $def = self::definitions()[$key];

        return collect($def['pairs'] ?? [])->contains(fn ($p) => ($in[$p[0]] ?? null) !== null);
    }

    /**
     * Σ(w·x) / Σ(w) over the non-null values (N/A is dropped and the rest re-normalized).
     *
     * @param  list<array{0: float, 1: float|null}>  $terms  [weight, value]
     */
    private static function weighted(array $terms): ?float
    {
        $sum = 0.0;
        $w = 0.0;
        foreach ($terms as [$weight, $v]) {
            if ($v !== null) {
                $sum += $weight * $v;
                $w += $weight;
            }
        }

        return $w > 0 ? $sum / $w : null;
    }

    private static function float(?int $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
