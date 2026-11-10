<?php

namespace App\Scoring;

/**
 * One community's HEVC from its inputs and a criteria set's weights. It is pure, with no DB access, so it's easy to
 * test against hand-worked numbers. Sources: สมการการคำนวณ_hevc_v4.docx and spec v5 §2.3–2.4, §4.1–4.6, §5.1 and
 * ภาคผนวก B.
 *
 * - Inputs are table columns (lower-case headers) merged from the 6 assessment sheets. Null = N/A (owner 2026-11-10:
 *   an empty cell in an existing row is N/A).
 * - Every weighted sum drops N/A terms and re-normalizes the rest (spec §4.6 "re-normalization"); all N/A → N/A.
 * - A hazard whose E, V or C is N/A is N/A, and MULTI_HEVC re-normalizes over the hazards left (spec §2.4).
 * - Each step is kept in `trace` (key → value + terms) so a later "ดูวิธีคิด" view can show the real calculation.
 */
class HevcCalculator
{
    public const HAZARDS = ['FLOOD', 'FIRE', 'STORM', 'HEAT', 'PM25'];

    /** V_* count columns → the S_* key used in the v_social / v_health / v_assist weights. */
    private const V_GROUPS = [
        'v_social' => ['S_ELDERLY' => 'v_elderly_count', 'S_CHILD' => 'v_child_count', 'S_DISABLED' => 'v_disabled_count', 'S_LOWINCOME' => 'v_low_income_count'],
        'v_health' => ['S_BEDRIDDEN' => 'v_bedridden_count', 'S_MEDICAL' => 'v_medical_dependent_count', 'S_NCD' => 'v_ncd_count'],
        'v_assist' => ['S_ASSIST_EVAC' => 'v_assisted_evacuation_count', 'S_COMM_ASSIST' => 'v_communication_assistance_count'],
    ];

    /** @var array<string, array{value: float|int|null, terms?: list<array{key: string, weight: float, value: float|int|null}>, raw?: mixed, note?: string}> */
    private array $trace = [];

    /**
     * @param  array<string, mixed>  $inputs  column → value
     * @param  array<string, array<string, array<string, float>>>  $weights  a ScoringCriteriaSet's weights
     */
    public function __construct(private array $inputs, private array $weights) {}

    /**
     * @return array{
     *     hazards: array<string, array{hevc: float|null, level: string|null, e: int|null, ePercent: float|null, v: float|null, cRaw: float|null, cHevc: float|null}>,
     *     multi: array{hevc: float|null, level: string|null, dominant: string|null},
     *     groups: array<string, float|int|null>,
     *     recovery: float|null,
     *     trace: array<string, mixed>
     * }
     */
    public function calculate(): array
    {
        $population = $this->number('population');

        // Scores 0–5 converted from raw values (ภาคผนวก B).
        $s = [];
        foreach (self::V_GROUPS as $keys) {
            foreach ($keys as $key => $column) {
                $pct = $this->percentOf($this->number($column), $population);
                $s[$key] = $this->record($key, Bands::vulnerablePercent($pct), raw: $this->number($column), note: $pct === null ? null : 'ร้อยละ '.round($pct, 2));
            }
        }

        $groups = [];
        foreach (array_keys(self::V_GROUPS) as $section) {
            $groups[strtoupper($section)] = $this->weighted(strtoupper($section), $this->weights[$section]['ALL'], $s);
        }
        $groups['V_WARNING'] = $this->record('V_WARNING', $this->number('v_warning_access_gap_score'));

        $c = [
            'C4_1' => $this->record('C4_1', Bands::volunteersPer1000($this->number('c4_1_volunteer_per_1000')), raw: $this->number('c4_1_volunteer_per_1000')),
            'C4_2' => $this->record('C4_2', Bands::trainingCount($this->integer('c4_2_training_count')), raw: $this->number('c4_2_training_count')),
            'C4_3' => $this->number('c4_3_risk_comm_score'),
            'C4_4' => $this->number('c4_4_school_drr_education_score'),
            'C5_1' => $this->number('c5_1_greenblue_score'),
            'C5_2' => $this->number('c5_2_nbs_score'),
            'C5_3' => $this->number('c5_3_maintenance_score'),
            'C6_1' => $this->number('c6_1_response_vehicle_adequacy_score'),
            'C6_2' => $this->number('c6_2_lifesaving_score'),
            'C6_3' => $this->record('C6_3', Bands::percent($this->number('c6_3_readiness_score')), raw: $this->number('c6_3_readiness_score')),
            'C6_4' => $this->number('c6_4_hazard_equipment_adequacy_score'),
            'C7_2' => $this->number('c7_2_safe_place_score'),
            'C7_3' => $this->number('c7_3_drill_score'),
            'C8_1' => $this->record('C8_1', Bands::networkCount($this->integer('c8_1_formal_network_count')), raw: $this->number('c8_1_formal_network_count')),
            'C8_2' => $this->record('C8_2', Bands::responseHours($this->number('c8_2_response_hours')), raw: $this->number('c8_2_response_hours')),
            'C8_3' => $this->number('c8_3_network_types_score'),
            'C8_4' => $this->number('c8_4_joint_experience_score'),
        ];
        foreach (['C4', 'C5', 'C8'] as $group) {
            $groups[$group] = $this->weighted($group, $this->weights[strtolower($group)]['ALL'], $c);
        }

        $hazards = [];
        foreach (self::HAZARDS as $h) {
            $hazards[$h] = $this->hazard($h, $population, $groups, $c);
            $groups["C6_$h"] = $this->trace["C6_$h"]['value'] ?? null;
            $groups["C7_$h"] = $this->trace["C7_$h"]['value'] ?? null;
            $groups["V_INFRA_$h"] = $this->trace["V_INFRA_$h"]['value'] ?? null;
        }

        $multiTerms = [];
        foreach (self::HAZARDS as $h) {
            $multiTerms["{$h}_HEVC"] = $hazards[$h]['hevc'];
        }
        $multi = $this->weighted('MULTI_HEVC', $this->weights['multi_hevc']['ALL'], $multiTerms);
        $available = array_filter($hazards, fn ($r) => $r['hevc'] !== null);
        $dominant = $available ? array_search(max(array_column($available, 'hevc')), array_map(fn ($r) => $r['hevc'], $available), true) : null;

        $recovery = $this->weighted('RECOVERY_AVG', $this->weights['recovery']['ALL'], [
            'R1' => $this->number('r1_recovery_plan_score'),
            'R2' => $this->number('r2_build_back_better_score'),
            'R3' => $this->number('r3_socioeconomic_recov_score'),
            'R4' => $this->number('r4_vulner_group_recov_score'),
            'R5' => $this->number('r5_learning_sys_improv_score'),
        ]);

        return [
            'hazards' => $hazards,
            'multi' => ['hevc' => $multi, 'level' => $multi === null ? null : RiskLevel::of($multi)->value, 'dominant' => $dominant ?: null],
            'groups' => $groups,
            'recovery' => $recovery,
            'trace' => $this->trace,
        ];
    }

    /**
     * RECOVERY_OBSERVED_AVG of one event (R*_OBSERVED, same Recovery weights as R1–R5, owner 2026-11-06).
     *
     * @param  array<string, mixed>  $event  disaster_events columns
     * @param  array<string, array<string, array<string, float>>>  $weights
     */
    public static function recoveryObserved(array $event, array $weights): ?float
    {
        $calc = new self($event, $weights);

        return $calc->weighted('RECOVERY_OBSERVED_AVG', $weights['recovery']['ALL'], [
            'R1' => $calc->number('r1_observed'),
            'R2' => $calc->number('r2_observed'),
            'R3' => $calc->number('r3_observed'),
            'R4' => $calc->number('r4_observed'),
            'R5' => $calc->number('r5_observed'),
        ]);
    }

    /**
     * @param  array<string, float|int|null>  $groups
     * @param  array<string, float|int|null>  $c
     * @return array{hevc: float|null, level: string|null, e: int|null, ePercent: float|null, v: float|null, cRaw: float|null, cHevc: float|null}
     */
    private function hazard(string $h, ?float $population, array $groups, array $c): array
    {
        $lower = strtolower($h);

        // E: exposed people as % of POPULATION, banded 0–5.
        $ePercent = $this->percentOf($this->number("e_numerator_count_$lower"), $population);
        $e = $this->record("E_$h", Bands::percent($ePercent), raw: $this->number("e_numerator_count_$lower"), note: $ePercent === null ? null : 'ร้อยละ '.round($ePercent, 2));

        // V_h from the 4 shared groups + this hazard's V_INFRA (a 0–5 score entered by staff).
        $vInfra = $this->record("V_INFRA_$h", $this->number("v_infra_gap_count_$lower"));
        $v = $this->weighted("V_$h", $this->weights['v_hazard'][$h], [
            'V_SOCIAL' => $groups['V_SOCIAL'], 'V_HEALTH' => $groups['V_HEALTH'], 'V_ASSIST' => $groups['V_ASSIST'],
            'V_WARNING' => $groups['V_WARNING'], 'V_INFRA' => $vInfra,
        ]);

        // C6/C7 for this hazard (a component absent from the hazard's formula has no weight, so it's skipped).
        $c6 = $this->weighted("C6_$h", $this->weights['c6'][$h], $c);
        $c7 = $this->weighted("C7_$h", $this->weights['c7'][$h], [
            'C7_1' => in_array($h, ['FLOOD', 'FIRE', 'STORM'], true) ? $this->number("c7_1_{$lower}_score") : null,
            'C7_2' => $c['C7_2'], 'C7_3' => $c['C7_3'],
        ]);
        $cRaw = $this->weighted("C_RAW_$h", $this->weights['c_hazard'][$h], [
            'C4' => $groups['C4'], 'C5' => $groups['C5'], 'C6' => $c6, 'C7' => $c7, 'C8' => $groups['C8'],
        ]);
        $cHevc = $cRaw === null ? null : $this->record("C_HEVC_$h", 1 + 0.8 * $cRaw, note: '1 + 0.8 × C_RAW');

        $hevc = null;
        if ($e !== null && $v !== null && $cHevc !== null) {
            $ev = $this->weights['hazard_ev']['ALL'];
            $factor = $this->weights['hazard_c']['ALL']['C'];
            $hevc = 20 * ($ev['E'] * $e + $ev['V'] * $v) * (1 - $factor * $cHevc);
        }
        $this->record("{$h}_HEVC", $hevc, note: '20 × (wE·E + wV·V) × (1 − c·C_HEVC)');

        return [
            'hevc' => $hevc,
            'level' => $hevc === null ? null : RiskLevel::of($hevc)->value,
            'e' => $e,
            'ePercent' => $ePercent,
            'v' => $v,
            'cRaw' => $cRaw,
            'cHevc' => $cHevc,
        ];
    }

    /**
     * Σ(w·x) / Σ(w) over the terms that have both a weight and a value; null when none do.
     *
     * @param  array<string, float>  $weights
     * @param  array<string, float|int|null>  $values
     */
    private function weighted(string $key, array $weights, array $values): ?float
    {
        $terms = [];
        $sum = 0.0;
        $weightSum = 0.0;
        foreach ($weights as $term => $weight) {
            $value = $values[$term] ?? null;
            $terms[] = ['key' => $term, 'weight' => (float) $weight, 'value' => $value];
            if ($value !== null) {
                $sum += $weight * $value;
                $weightSum += $weight;
            }
        }
        $result = $weightSum > 0 ? $sum / $weightSum : null;
        $this->trace[$key] = ['value' => $result, 'terms' => $terms];

        return $result;
    }

    private function record(string $key, float|int|null $value, mixed $raw = null, ?string $note = null): float|int|null
    {
        $this->trace[$key] = array_filter(['value' => $value, 'raw' => $raw, 'note' => $note], fn ($v, $k) => $k === 'value' || $v !== null, ARRAY_FILTER_USE_BOTH);

        return $value;
    }

    private function percentOf(?float $count, ?float $population): ?float
    {
        return $count === null || ! $population ? null : $count / $population * 100;
    }

    private function number(string $column): ?float
    {
        $value = $this->inputs[$column] ?? null;

        return $value === null || $value === '' ? null : (float) $value;
    }

    private function integer(string $column): ?int
    {
        $value = $this->number($column);

        return $value === null ? null : (int) round($value);
    }
}
