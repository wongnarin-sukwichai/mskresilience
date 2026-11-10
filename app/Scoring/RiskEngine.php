<?php

namespace App\Scoring;

use App\Models\Community;
use App\Models\DisasterRecord;
use App\Models\EducationRecord;
use App\Models\EngineeringRecord;
use App\Models\HealthRecord;
use App\Models\PublicRelationsRecord;
use App\Models\ScoringCriteriaSet;
use App\Models\WelfareRecord;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The calc engine. It computes live on every call, with no stored results (owner 2026-11-10: only 32 communities,
 * and it keeps the executive what-if simple). Rules decided by the owner:
 * - Data round: each community's latest record per sheet, dated on or before $asOf (2026-11-06).
 * - Previous round (for Δ, 2026-11-10): the same community computed as of the day before its newest record used,
 *   so Δ shows only what the newer data changed. No such data → no previous.
 * - A community missing a record in any of the 6 assessment sheets is "missing": no HEVC, and the map shows it gray
 *   as ข้อมูลไม่ครบ. An empty cell inside a record is N/A and gets re-normalized (2026-11-10).
 * - Criteria set: the one CriteriaSets::resolve() gives for the fiscal year of the community's newest record used
 *   (2026-11-10). $override (a what-if) uses one set for everyone.
 * - City level: population-weighted average over the communities that have a value, with how many were included
 *   (2026-11-10). City Δ is averaged over the communities that have both rounds, so it compares like with like.
 */
class RiskEngine
{
    /** The 6 sheets HEVC + Recovery read: key → [model, Thai label]. Events and KPI aren't part of HEVC. */
    public const SHEETS = [
        'disaster' => [DisasterRecord::class, 'งานป้องกันภัย'],
        'welfare' => [WelfareRecord::class, 'งานสวัสดิการสังคม'],
        'health' => [HealthRecord::class, 'สาธารณสุข'],
        'education' => [EducationRecord::class, 'กองการศึกษา'],
        'engineering' => [EngineeringRecord::class, 'สำนักช่าง'],
        'public_relations' => [PublicRelationsRecord::class, 'ประชาสัมพันธ์'],
    ];

    /** Raw counts passed through for the dashboard cards (people, not scores). */
    public const COUNTS = [
        'population', 'households',
        'e_numerator_count_flood', 'e_numerator_count_fire', 'e_numerator_count_storm', 'e_numerator_count_heat', 'e_numerator_count_pm25',
        'v_elderly_count', 'v_child_count', 'v_disabled_count', 'v_low_income_count', 'v_bedridden_count',
        'v_medical_dependent_count', 'v_assisted_evacuation_count', 'v_communication_assistance_count', 'v_ncd_count',
    ];

    /** Columns that are keys/bookkeeping, not inputs. */
    private const NON_INPUTS = ['id', 'community_id', 'assessment_date', 'import_batch_id', 'created_at', 'updated_at'];

    /** @var array<string, Collection<int, Collection<int, Model>>> sheet → community_id → records, oldest first */
    private array $records = [];

    /** @var Collection<int, ScoringCriteriaSet> */
    private Collection $sets;

    /**
     * Every community's result plus the city-level summary.
     *
     * @return array{communities: list<array<string, mixed>>, city: array<string, mixed>, asOf: string|null}
     */
    public function run(?string $asOf = null, ?ScoringCriteriaSet $override = null): array
    {
        $this->sets = ScoringCriteriaSet::query()->orderBy('start_fiscal_year')->get();
        $this->loadRecords($asOf);

        $communities = Community::query()->orderBy('code')->get()->map(function (Community $community) use ($override) {
            $current = $this->community($community, null, $override);
            $previous = $current['dataDate']
                ? $this->community($community, Carbon::parse($current['dataDate'])->subDay()->toDateString(), $override)
                : null;
            $current['previous'] = $previous && $previous['status'] === 'ok' ? [
                'dataDate' => $previous['dataDate'],
                'criteriaSet' => $previous['criteriaSet'],
                'multi' => $previous['multi'],
                'hazards' => $previous['hazards'],
                'groups' => $previous['groups'],
                'recovery' => $previous['recovery'],
            ] : null;
            // Raw inputs of the previous round even when it isn't complete enough for HEVC (page 6 KPIs use single
            // columns), plus the cutoff it was taken at.
            $current['previousInputs'] = $previous && $previous['sources'] ? $previous['inputs'] : null;
            $current['previousCutoff'] = $current['dataDate'] ? Carbon::parse($current['dataDate'])->subDay()->toDateString() : null;

            return $current;
        })->values();

        return [
            'communities' => $communities->all(),
            'city' => $this->city($communities),
            'asOf' => $asOf,
        ];
    }

    /**
     * The fiscal years (พ.ศ.) that have data in the 6 assessment sheets, ascending.
     *
     * @return list<int>
     */
    public static function fiscalYears(): array
    {
        $years = [];
        foreach (self::SHEETS as [$model]) {
            foreach ($model::query()->distinct()->pluck('assessment_date') as $date) {
                $years[CriteriaSets::fiscalYear($date)] = true;
            }
        }
        $years = array_keys($years);
        sort($years);

        return $years;
    }

    /**
     * The last day of a fiscal year (พ.ศ.), e.g. 2568 → 2025-09-30.
     */
    public static function fiscalYearEnd(int $fiscalYear): string
    {
        return sprintf('%04d-09-30', $fiscalYear - 543);
    }

    /**
     * One community computed from its latest records on or before $cutoff (null = everything loaded).
     *
     * @return array<string, mixed>
     */
    private function community(Community $community, ?string $cutoff, ?ScoringCriteriaSet $override): array
    {
        $inputs = [];
        $sources = [];
        $missing = [];
        foreach (self::SHEETS as $key => [, $label]) {
            $record = $this->records[$key]->get($community->id)
                ?->filter(fn ($r) => $cutoff === null || $r->assessment_date->toDateString() <= $cutoff)
                ->last();
            if (! $record) {
                $missing[] = $label;

                continue;
            }
            $sources[$key] = $record->assessment_date->toDateString();
            $inputs += array_diff_key($record->getAttributes(), array_flip(self::NON_INPUTS));
        }

        $dataDate = $sources ? max($sources) : null;
        $set = $override ?? ($dataDate ? CriteriaSets::resolve(CriteriaSets::fiscalYear($dataDate), $this->sets) : null);

        $counts = [];
        foreach (self::COUNTS as $column) {
            $counts[$column] = isset($inputs[$column]) ? (int) $inputs[$column] : null;
        }

        $result = [
            'id' => $community->id,
            'code' => $community->code,
            'name' => $community->name,
            'status' => $missing ? 'missing' : 'ok',
            'missingSheets' => $missing,
            'sources' => $sources,
            'dataDate' => $dataDate,
            'population' => $counts['population'],
            'counts' => $counts,
            // Every raw input column (lower-case header → value) merged from the sheets found. Controllers pick what
            // they need; none of them sends this whole array to the page.
            'inputs' => $inputs,
            // Raw facts the community panel shows as-is (page 3).
            'areaKm2' => isset($inputs['area_km2']) ? (float) $inputs['area_km2'] : null,
            'responseHours' => isset($inputs['c8_2_response_hours']) ? (float) $inputs['c8_2_response_hours'] : null,
            'criteriaSet' => $set ? ['id' => $set->id, 'name' => $set->name, 'startFiscalYear' => $set->start_fiscal_year] : null,
        ];

        if ($missing || ! $set) {
            return $result + ['hazards' => null, 'multi' => null, 'groups' => null, 'recovery' => null, 'trace' => null];
        }

        return $result + (new HevcCalculator($inputs, $set->weights))->calculate();
    }

    /**
     * Population-weighted averages over the communities with a value, plus Δ over those with both rounds.
     *
     * @param  Collection<int, array<string, mixed>>  $communities
     * @return array<string, mixed>
     */
    private function city(Collection $communities): array
    {
        $ok = $communities->where('status', 'ok');

        $average = function (callable $value) use ($ok): array {
            $sum = 0.0;
            $weight = 0;
            $count = 0;
            $deltaSum = 0.0;
            $deltaWeight = 0;
            $deltaCount = 0;
            foreach ($ok as $c) {
                $v = $value($c);
                if ($v === null || ! $c['population']) {
                    continue;
                }
                $sum += $v * $c['population'];
                $weight += $c['population'];
                $count++;
                $prev = $c['previous'] ? $value($c['previous']) : null;
                if ($prev !== null) {
                    $deltaSum += ($v - $prev) * $c['population'];
                    $deltaWeight += $c['population'];
                    $deltaCount++;
                }
            }

            return [
                'value' => $weight > 0 ? $sum / $weight : null,
                'communities' => $count,
                'delta' => $deltaWeight > 0 ? $deltaSum / $deltaWeight : null,
                'deltaCommunities' => $deltaCount,
            ];
        };
        $withLevel = fn (array $avg) => $avg + ['level' => $avg['value'] === null ? null : RiskLevel::of($avg['value'])->value];

        $hazards = [];
        foreach (HevcCalculator::HAZARDS as $h) {
            $v = $average(fn ($c) => $c['hazards'][$h]['v'] ?? null);
            $cRaw = $average(fn ($c) => $c['hazards'][$h]['cRaw'] ?? null);
            $hazards[$h] = $withLevel($average(fn ($c) => $c['hazards'][$h]['hevc'] ?? null)) + [
                'v' => $v['value'],
                'vDelta' => $v['delta'],
                'cRaw' => $cRaw['value'],
                'cRawDelta' => $cRaw['delta'],
            ];
        }
        $dominant = null;
        foreach ($hazards as $h => $r) {
            if ($r['value'] !== null && ($dominant === null || $r['value'] > $hazards[$dominant]['value'])) {
                $dominant = $h;
            }
        }

        $groupKeys = $ok->flatMap(fn ($c) => array_keys($c['groups']))->unique()->values();
        $groups = [];
        $groupDeltas = [];
        foreach ($groupKeys as $key) {
            $avg = $average(fn ($c) => $c['groups'][$key] ?? null);
            $groups[$key] = $avg['value'];
            $groupDeltas[$key] = $avg['delta'];
        }

        $counts = [];
        foreach (self::COUNTS as $column) {
            $counts[$column] = $ok->sum(fn ($c) => $c['counts'][$column] ?? 0);
        }

        $sets = $ok->pluck('criteriaSet')->unique('id')->values()->all();
        $previousSets = $ok->pluck('previous.criteriaSet')->filter()->unique('id')->values()->all();

        return [
            'multi' => $withLevel($average(fn ($c) => $c['multi']['hevc'] ?? null)) + ['dominant' => $dominant],
            'hazards' => $hazards,
            'groups' => $groups,
            'groupDeltas' => $groupDeltas,
            'counts' => $counts,
            'totalCommunities' => $communities->count(),
            'scoredCommunities' => $ok->count(),
            'missing' => $communities->where('status', 'missing')->map(fn ($c) => ['code' => $c['code'], 'name' => $c['name'], 'sheets' => $c['missingSheets']])->values()->all(),
            'highRiskCommunities' => $ok->filter(fn ($c) => $c['multi']['level'] === RiskLevel::VeryHigh->value)->count(),
            'dataDate' => $communities->pluck('dataDate')->filter()->max(),
            'previousDataDate' => $ok->pluck('previous.dataDate')->filter()->max(),
            'criteriaSets' => $sets,
            'previousCriteriaSets' => $previousSets,
        ];
    }

    private function loadRecords(?string $asOf): void
    {
        foreach (self::SHEETS as $key => [$model]) {
            $this->records[$key] = $model::query()
                ->when($asOf, fn ($q) => $q->whereDate('assessment_date', '<=', $asOf))
                ->orderBy('assessment_date')
                ->get()
                ->groupBy('community_id');
        }
    }
}
