<?php

namespace App\Demo;

use App\Enums\WorkGroup;
use App\Import\ImportColumns;

/**
 * MOCK data for the TOR acceptance demo (owner 2026-11-23): 3 fiscal years (2567–2569) of all 8 import sheets for
 * the 32 communities. NOT real data. The home page shows a "ข้อมูลสมมุติ" banner while it is in the system, and it
 * is cleared before go-live (memory: project_tor_gap_decisions_2026_11_23 → go-live cleanup).
 *
 * Deterministic (fixed seed), so `php artisan demo:data` always builds the same files. Each community has a risk
 * factor r (0 = safe, 1 = worst) per year; exposure, vulnerable groups and gaps rise with r, capacity falls. The
 * stories the demo needs (TOR features) are set on purpose:
 * - Most communities improve slightly each year (trend down).
 * - R003 and R022 stay ค่อนข้างสูง all 3 years (continuous-high alert); R003 has a low budget, R022 a high one.
 * - R017 rises every year and reaches สูง in 2569, with weak recovery plans (high risk + low recovery flag).
 * - R009 has a big flood in FY2568 with a large F4 risk-reduction budget, and its risk drops in 2569
 *   (before–after investment).
 * - R005 and R012 rise from ต่ำ/ปานกลาง (rising-risk flag).
 * - In 2567 some MCR evidence columns are blank; by 2569 they are filled (MCR coverage improves).
 * - Events spread across the months and hazards of each year, with observed recovery differing from readiness.
 */
class MockData
{
    public const YEARS = [2567, 2568, 2569];

    /** code → [population, households, area km², 3-year budget] (FY2568 base). */
    private const BASE = [
        'R001' => [1240, 460, 0.72, 850000], 'R002' => [980, 365, 0.58, 520000], 'R003' => [1510, 560, 0.83, 350000],
        'R004' => [1880, 690, 1.05, 1680000], 'R005' => [1120, 415, 0.66, 690000], 'R006' => [1430, 525, 0.79, 980000],
        'R007' => [1260, 470, 0.74, 760000], 'R008' => [910, 340, 0.51, 380000], 'R009' => [1490, 550, 0.81, 620000],
        'R010' => [1710, 630, 0.92, 1340000], 'R011' => [1060, 395, 0.60, 610000], 'R012' => [1340, 495, 0.76, 900000],
        'R013' => [970, 360, 0.55, 450000], 'R014' => [1190, 440, 0.63, 720000], 'R015' => [1390, 515, 0.77, 1010000],
        'R016' => [1080, 400, 0.59, 560000], 'R017' => [1540, 570, 0.85, 480000], 'R018' => [820, 305, 0.48, 300000],
        'R019' => [1320, 490, 0.73, 880000], 'R020' => [1150, 425, 0.64, 670000], 'R021' => [860, 320, 0.50, 410000],
        'R022' => [1620, 600, 0.89, 1450000], 'R023' => [1290, 480, 0.71, 830000], 'R024' => [1100, 410, 0.62, 790000],
        'R025' => [1460, 540, 0.80, 1180000], 'R026' => [940, 350, 0.54, 470000], 'R027' => [1810, 665, 1.02, 1760000],
        'R028' => [1270, 470, 0.70, 810000], 'R029' => [1130, 420, 0.61, 640000], 'R030' => [1500, 555, 0.84, 1310000],
        'R031' => [900, 335, 0.52, 390000], 'R032' => [1360, 505, 0.75, 940000],
    ];

    /** Risk factor per year for the story communities (others are derived from the seed). */
    private const STORY_RISK = [
        'R003' => [0.85, 0.86, 0.85],
        'R022' => [0.85, 0.87, 0.85],
        'R017' => [0.62, 0.82, 0.98],
        'R009' => [0.80, 0.83, 0.52],
        'R012' => [0.32, 0.48, 0.64],
        'R005' => [0.24, 0.37, 0.50],
    ];

    /** Low-lying communities near the canals: flood exposure counts fully. */
    private const FLOOD_PRONE = ['R003', 'R006', 'R009', 'R010', 'R017', 'R022', 'R025'];

    /** Dense commercial blocks: fire exposure is higher. */
    private const FIRE_PRONE = ['R004', 'R027', 'R001', 'R019'];

    private const HAZARDS = ['FLOOD', 'FIRE', 'STORM', 'HEAT', 'PM25'];

    /** @var array<string, string> code → name */
    private array $names;

    /** @var array<string, array<int, float>> code → year → r */
    private array $risk = [];

    /**
     * @param  array<string, string>  $names  community code → name (from the communities table)
     */
    public function __construct(array $names)
    {
        $this->names = $names;
        mt_srand(20261123);
        foreach (array_keys(self::BASE) as $code) {
            if (isset(self::STORY_RISK[$code])) {
                $this->risk[$code] = array_combine(self::YEARS, self::STORY_RISK[$code]);

                continue;
            }
            // Ordinary communities: low to middle risk (about a third ปานกลาง), improving a little each year.
            $r = 0.2 + 0.46 * $this->rand();
            foreach (self::YEARS as $year) {
                $this->risk[$code][$year] = round(max(0.05, $r), 3);
                $r -= 0.02 + 0.04 * $this->rand();
            }
        }
    }

    /**
     * The header row plus data rows of one sheet for one fiscal year.
     *
     * @return list<list<mixed>>
     */
    public function grid(WorkGroup $group, int $year): array
    {
        $headers = ImportColumns::for($group);
        mt_srand(crc32($group->value.$year));
        $rows = $group === WorkGroup::DisasterEvent ? $this->events($year) : $this->communityRows($group, $year);

        return [$headers, ...array_map(fn ($row) => array_map(fn ($h) => $row[$h] ?? null, $headers), $rows)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function communityRows(WorkGroup $group, int $year): array
    {
        $rows = [];
        $index = 0;
        foreach (self::BASE as $code => [$pop0, $hh0, $area, $budget0]) {
            $yi = array_search($year, self::YEARS, true);
            $r = $this->risk[$code][$year];
            $c = $this->capacity($code, $year);
            $pop = (int) round($pop0 * (1 + 0.012 * ($yi - 1)));
            $row = [
                'ASSESSMENT_DATE' => $this->assessmentDate($year, $index),
                'COMMUNITY_ID' => $code,
                'COMMUNITY_NAME' => $this->names[$code] ?? null,
            ];
            // MCR evidence fills in over the years: some columns are blank in 2567, fewer in 2568.
            $blank = fn (int $every) => $yi === 0 && $index % $every === 0 || $yi === 1 && $index % ($every * 2) === 0;
            // R017 in 2569 is the one สูง community: its vulnerable groups are far above the city's.
            $vf = $this->extreme($code, $year) ? 1.7 : 1.0;

            $row += match ($group) {
                WorkGroup::Disaster => $this->disaster($code, $year, $r, $c, $pop, $hh0, $area, $budget0, $blank),
                WorkGroup::Welfare => [
                    'V_ELDERLY_COUNT' => $this->share($pop, (7 + 24 * $r) * $vf),
                    'V_CHILD_COUNT' => $this->share($pop, (9 + 16 * $r) * $vf),
                    'V_DISABLED_COUNT' => $this->share($pop, (1.5 + 14 * $r) * $vf),
                    'V_LOW_INCOME_COUNT' => $this->share($pop, (4 + 28 * $r) * $vf),
                ],
                WorkGroup::Health => [
                    'V_BEDRIDDEN_COUNT' => $this->share($pop, (0.4 + 6 * $r) * $vf),
                    'V_MEDICAL_DEPENDENT_COUNT' => $this->share($pop, (0.8 + 9 * $r) * $vf),
                    'V_ASSISTED_EVACUATION_COUNT' => $this->share($pop, (0.8 + 12 * $r) * $vf),
                    'V_COMMUNICATION_ASSISTANCE_COUNT' => $this->share($pop, (0.5 + 9 * $r) * $vf),
                    'V_NCD_COUNT' => $this->share($pop, (9 + 26 * $r) * $vf),
                ],
                WorkGroup::Education => ['C4_4_SCHOOL_DRR_EDUCATION_SCORE' => $blank(5) ? null : $this->score(5 * $c)],
                WorkGroup::Engineering => $this->engineering($code, $year, $r, $blank),
                WorkGroup::PublicRelations => [
                    'V_WARNING_ACCESS_GAP_SCORE' => $this->score(0.5 + 4.5 * $r),
                    'C4_3_RISK_COMM_SCORE' => $this->score(5 * $c),
                ],
                WorkGroup::Kpi => $this->kpi($pop, $r, $yi),
                default => [],
            };
            $rows[] = $row;
            $index++;
        }

        return $rows;
    }

    /**
     * @param  callable(int): bool  $blank
     * @return array<string, mixed>
     */
    private function disaster(string $code, int $year, float $r, float $c, int $pop, int $hh0, float $area, int $budget0, callable $blank): array
    {
        $yi = array_search($year, self::YEARS, true);
        $exposed = [];
        foreach (self::HAZARDS as $h) {
            $prone = $this->extreme($code, $year) ? 1.0 : match ($h) {
                'FLOOD' => in_array($code, self::FLOOD_PRONE, true) ? 1.0 : 0.45,
                'FIRE' => in_array($code, self::FIRE_PRONE, true) ? 0.85 : 0.4,
                'STORM' => 0.75,
                'HEAT' => 0.85,
                'PM25' => 0.95,
            };
            $pct = min(98, 100 * $prone * (0.06 + 0.94 * $r) + $this->noise(4));
            $exposed["E_NUMERATOR_COUNT_{$h}"] = (int) round($pop * max(0, $pct) / 100);
        }
        // The 3-year average budget moves slowly; R009's FY2569 average includes the FY2568 flood investment.
        $budget = $budget0 * (0.94 + 0.06 * $yi) * (1 + $this->noise(0.03));
        if ($code === 'R009' && $year === 2569) {
            $budget += 1850000 / 3;
        }
        $recovery = $code === 'R017' ? 0.15 : $c;

        return [
            'TAMBON' => 'ตลาด', 'AMPHOE' => 'เมืองมหาสารคาม', 'PROVINCE' => 'มหาสารคาม',
            'POPULATION' => $pop,
            'HOUSEHOLDS' => (int) round($hh0 * (1 + 0.01 * ($yi - 1))),
            'AREA_KM2' => $area,
            ...$exposed,
            'C4_1_VOLUNTEER_PER_1000' => round(max(0.5, 1 + 11 * $c + $this->noise(0.8)), 2),
            'C4_2_TRAINING_COUNT' => max(0, (int) round(4.5 * $c + $this->noise(0.6))),
            'C6_1_RESPONSE_VEHICLE_ADEQUACY_SCORE' => $this->score(5 * $c),
            'C6_2_LIFESAVING_SCORE' => $this->score(5 * $c),
            'C6_3_READINESS_SCORE' => round(min(100, max(5, 10 + 88 * $c + $this->noise(5))), 2),
            'C6_4_HAZARD_EQUIPMENT_ADEQUACY_SCORE' => $this->score(5 * $c),
            'C7_1_FLOOD_SCORE' => $this->score(5 * $c),
            'C7_1_FIRE_SCORE' => $this->score(5 * $c),
            'C7_1_STORM_SCORE' => $this->score(5 * $c),
            'C7_2_SAFE_PLACE_SCORE' => $this->score(5 * $c),
            'C7_3_DRILL_SCORE' => $this->score(5 * $c),
            'C8_1_FORMAL_NETWORK_COUNT' => max(0, (int) round(5 * $c + $this->noise(0.6))),
            'C8_2_RESPONSE_HOURS' => round(max(0.5, 0.5 + 30 * (1 - $c) ** 2 + $this->noise(0.5)), 2),
            'C8_3_NETWORK_TYPES_SCORE' => $this->score(5 * $c),
            'C8_4_JOINT_EXPERIENCE_SCORE' => $this->score(5 * $c),
            'R1_RECOVERY_PLAN_SCORE' => $blank(4) ? null : $this->score(5 * $recovery),
            'R2_BUILD_BACK_BETTER_SCORE' => $blank(4) ? null : $this->score(5 * $recovery),
            'R3_SOCIOECONOMIC_RECOV_SCORE' => $blank(4) ? null : $this->score(5 * $recovery),
            'R4_VULNER_GROUP_RECOV_SCORE' => $blank(4) ? null : $this->score(5 * $recovery),
            'R5_LEARNING_SYS_IMPROV_SCORE' => $blank(4) ? null : $this->score(5 * $recovery),
            'M_BUDGET_AVG_3Y' => round($budget / 1000) * 1000,
        ];
    }

    /**
     * @param  callable(int): bool  $blank
     * @return array<string, mixed>
     */
    private function engineering(string $code, int $year, float $r, callable $blank): array
    {
        $c = $this->capacity($code, $year);
        // R009 rebuilt drains and embankments with the FY2568 flood budget: its flood/storm gaps fall in 2569.
        $fixed = $code === 'R009' && $year === 2569 ? 2 : 0;
        $gap = fn (float $weight, int $minus = 0) => $this->score(max(0, 5 * $r * $weight - $minus));

        return [
            'V_INFRA_GAP_COUNT_FLOOD' => $gap(in_array($code, self::FLOOD_PRONE, true) ? 1.05 : 0.7, $fixed),
            'V_INFRA_GAP_COUNT_FIRE' => $gap(in_array($code, self::FIRE_PRONE, true) ? 1.0 : 0.6),
            'V_INFRA_GAP_COUNT_STORM' => $gap(0.85, $fixed),
            'V_INFRA_GAP_COUNT_HEAT' => $gap(0.8),
            'V_INFRA_GAP_COUNT_PM25' => $gap(0.75),
            'C5_1_GREENBLUE_SCORE' => $blank(3) ? null : $this->score(5 * $c),
            'C5_2_NBS_SCORE' => $blank(3) ? null : $this->score(5 * $c),
            'C5_3_MAINTENANCE_SCORE' => $blank(3) ? null : $this->score(5 * $c),
        ];
    }

    /**
     * KPI counts: plans cover a growing share of each group each year, never more than the group itself.
     *
     * @return array<string, mixed>
     */
    private function kpi(int $pop, float $r, int $yi): array
    {
        $cover = fn (float $groupPct) => (int) floor($this->share($pop, $groupPct) * min(0.95, 0.35 + 0.2 * $yi + $this->noise(0.06)));
        $lowIncome = $this->share($pop, 4 + 28 * $r);
        $exit = (int) round($lowIncome * (0.04 + 0.04 * $yi));

        return [
            'KPI_LOW_INCOME_EXIT_VERIFIED_COUNT' => $exit,
            'KPI_LOW_INCOME_FOLLOWUP_COUNT' => (int) round($exit * (0.6 + 0.15 * $yi)),
            'KPI_ELDERLY_PLAN_COUNT' => $cover(7 + 24 * $r),
            'KPI_DISABLED_PLAN_COUNT' => $cover(1.5 + 14 * $r),
            'KPI_CHILD_PLAN_COUNT' => $cover(9 + 16 * $r),
            'KPI_MEDICAL_PLAN_READY_COUNT' => $cover(0.8 + 9 * $r),
            'KPI_BEDRIDDEN_PLAN_READY_COUNT' => $cover(0.4 + 6 * $r),
            'KPI_ASSISTED_EVAC_READY_COUNT' => $cover(0.8 + 12 * $r),
        ];
    }

    /**
     * About 12 events per fiscal year, in the season of each hazard. A PM2.5 episode hits several communities on
     * the same day (one EVENT_ID per community, as in the client's own file).
     *
     * @return list<array<string, mixed>>
     */
    private function events(int $year): array
    {
        $ce = $year - 543; // FY 2568 = Oct 2024 – Sep 2025
        $plan = [
            ['FLOOD', $ce - 1, 10, ['R009', 'R003']],
            ['FIRE', $ce - 1, 12, ['R004']],
            ['PM25', $ce, 1, ['R001', 'R012', 'R017', 'R022']],
            ['PM25', $ce, 3, ['R005', 'R010']],
            ['FIRE', $ce, 3, ['R027']],
            ['HEAT', $ce, 4, ['R017', 'R012']],
            ['STORM', $ce, 5, ['R022', 'R006']],
            ['FLOOD', $ce, 8, ['R017', 'R025']],
            ['FLOOD', $ce, 9, ['R022']],
        ];
        $events = [];
        $n = (array_search($year, self::YEARS, true)) * 20;
        foreach ($plan as [$hazard, $y, $month, $codes]) {
            $day = 3 + (int) floor(20 * $this->rand());
            foreach ($codes as $code) {
                $n++;
                $start = sprintf('%04d-%02d-%02d', $y, $month, $day);
                $events[] = $this->event(sprintf('EV%04d', $n), $code, $hazard, $start, $year);
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $id, string $code, string $hazard, string $start, int $year): array
    {
        $r = $this->risk[$code][$year];
        $scale = 0.4 + $r;
        [$days, $houses, $injury, $damage, $related, $text] = match ($hazard) {
            'FLOOD' => [rand(2, 6), (int) (60 * $scale), (int) (4 * $scale), 4500000 * $scale, 'STORM', 'ฝนตกหนักต่อเนื่อง น้ำระบายไม่ทัน น้ำท่วมขังบ้านเรือนและถนนในพื้นที่ลุ่มต่ำ'],
            'FIRE' => [1, (int) (6 * $scale), (int) (3 * $scale), 1800000 * $scale, null, 'เพลิงไหม้อาคารพาณิชย์และบ้านเรือนติดกัน เพลิงลุกลามจากไฟฟ้าลัดวงจร'],
            'STORM' => [1, (int) (35 * $scale), (int) (3 * $scale), 1400000 * $scale, 'FLOOD', 'พายุฤดูร้อนพัดหลังคาบ้านเรือนและต้นไม้หักโค่นทับสายไฟ'],
            'HEAT' => [rand(5, 12), 0, (int) (8 * $scale), 0, 'PM25', 'อุณหภูมิสูงต่อเนื่อง ผู้สูงอายุและผู้ป่วยติดเตียงมีอาการจากความร้อน'],
            'PM25' => [rand(7, 15), 0, (int) (30 * $scale), 0, 'HEAT', 'ค่าฝุ่น PM2.5 เกินมาตรฐานต่อเนื่อง ผู้มีโรคทางเดินหายใจเข้ารับการรักษาเพิ่มขึ้น'],
        };
        $end = date('Y-m-d', strtotime("{$start} +{$days} days"));
        $damage = round($damage * (0.8 + 0.4 * $this->rand()) / 1000) * 1000;
        $f2 = round((30000 + 250000 * $scale * $this->rand()) / 1000) * 1000;
        $f3 = $hazard === 'PM25' || $hazard === 'HEAT' ? 0 : round($damage * 0.18 / 1000) * 1000;
        $f4 = round(($hazard === 'FLOOD' ? 250000 : 80000) * $this->rand() / 1000) * 1000;
        $f5 = $houses ? round($houses * 3500 / 1000) * 1000 : 0;
        // The before–after story: R009's FY2568 flood came with a large risk-reduction budget.
        if ($code === 'R009' && $year === 2568 && $hazard === 'FLOOD') {
            $f4 = 1850000;
        }
        // Observed recovery is near the assessed readiness, but not equal: some better, some worse.
        $readiness = $code === 'R017' ? 0.15 : $this->capacity($code, $year);
        $observed = fn () => $this->score(5 * $readiness + ($this->rand() < 0.5 ? -1.2 : 0.9));

        return [
            'EVENT_ID' => $id,
            'COMMUNITY_ID' => $code,
            'COMMUNITY_NAME' => $this->names[$code] ?? null,
            'EVENT_START' => $start,
            'EVENT_END' => $end,
            'PRIMARY_HAZARD' => $hazard === 'PM25' ? 'PM2.5' : $hazard,
            'RELATED_HAZARDS' => $related === 'PM25' ? 'PM2.5' : $related,
            'MULTI_HAZARD_FLAG' => $related ? 'ใช่' : 'ไม่ใช่',
            'EVENT_DESCRIPTION' => $text.' (ข้อมูลสมมุติเพื่อทดสอบระบบ)',
            'L1_HOUSES_DAMAGED_COUNT' => $houses,
            'L2_INJURY' => $injury,
            'L2_DEATH' => $hazard === 'FLOOD' && $r > 0.9 ? 1 : 0,
            'L3_DAMAGE_VALUE_THB' => $damage,
            'L4_RECOVERY_DAYS' => match ($hazard) {
                'FLOOD' => rand(10, 35), 'FIRE' => rand(20, 60), 'STORM' => rand(5, 15), 'HEAT' => rand(1, 3), 'PM25' => rand(3, 10),
            },
            'L5_REPEAT_DAMAGE_COUNT' => $r > 0.7 ? rand(1, 3) : rand(0, 1),
            'R1_OBSERVED' => $observed(),
            'R2_OBSERVED' => $observed(),
            'R3_OBSERVED' => $observed(),
            'R4_OBSERVED' => $observed(),
            'R5_OBSERVED' => $observed(),
            'F1_EVENT_TOTAL_BUDGET_THB' => $f2 + $f3 + $f4 + $f5,
            'F2_EVENT_RESPONSE_COST_THB' => $f2,
            'F3_EVENT_RECOVERY_COST_THB' => $f3,
            'F4_EVENT_RISK_REDUCTION_BUDGET_THB' => $f4,
            'F5_EVENT_RELIEF_COMPENSATION_THB' => $f5,
        ];
    }

    /** R017 in FY2569: exposed to every hazard and very vulnerable, the demo's one สูง community. */
    private function extreme(string $code, int $year): bool
    {
        return $code === 'R017' && $year === 2569;
    }

    /** Capacity 0–1: the opposite of risk, with R009 rebuilding after its FY2568 flood. */
    private function capacity(string $code, int $year): float
    {
        $c = 1 - $this->risk[$code][$year];
        if ($code === 'R009' && $year === 2569) {
            $c += 0.2;
        }
        if ($code === 'R017' && $year === 2569) {
            $c = 0.02;
        }

        return max(0, min(1, $c));
    }

    /** Spread over Jan 15 – Feb 15 of the fiscal year's calendar year. */
    private function assessmentDate(int $year, int $index): string
    {
        return date('Y-m-d', strtotime(sprintf('%04d-01-15', $year - 543)." +{$index} days"));
    }

    private function share(int $pop, float $percent): int
    {
        return max(0, (int) round($pop * max(0, $percent + $this->noise(1.2)) / 100));
    }

    private function score(float $value): int
    {
        return max(0, min(5, (int) round($value + $this->noise(0.35))));
    }

    private function noise(float $size): float
    {
        return ($this->rand() * 2 - 1) * $size;
    }

    private function rand(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
