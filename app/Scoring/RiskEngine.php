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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The calc engine. It computes live on every call, with no stored results (owner 2026-11-10: only 32 communities,
 * and it keeps the executive what-if simple). Rules decided by the owner:
 * - Data round: each community's latest record per sheet, dated on or before $asOf (2026-11-06).
 * - A community missing a record in any of the 6 assessment sheets is "missing": no HEVC, and the map shows it gray
 *   as ข้อมูลไม่ครบ. An empty cell inside a record is N/A and gets re-normalized (2026-11-10).
 * - Criteria set: the one CriteriaSets::resolve() gives for the fiscal year of the community's newest record used
 *   (2026-11-10). $override (a what-if) uses one set for everyone.
 * - City level: population-weighted average over the communities that have a value, with how many were included
 *   (2026-11-10).
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

    /** Columns that are keys/bookkeeping, not inputs. */
    private const NON_INPUTS = ['id', 'community_id', 'assessment_date', 'import_batch_id', 'created_at', 'updated_at'];

    /**
     * Every community's result plus the city-level summary.
     *
     * @return array{communities: list<array<string, mixed>>, city: array<string, mixed>, asOf: string|null}
     */
    public function run(?string $asOf = null, ?ScoringCriteriaSet $override = null): array
    {
        $sets = ScoringCriteriaSet::query()->orderBy('start_fiscal_year')->get();
        $latest = $this->latestRecords($asOf);

        $communities = Community::query()->orderBy('code')->get()
            ->map(fn (Community $community) => $this->community($community, $latest, $sets, $override))
            ->values();

        return [
            'communities' => $communities->all(),
            'city' => $this->city($communities),
            'asOf' => $asOf,
        ];
    }

    /**
     * @param  array<string, Collection<int, Model>>  $latest  sheet → community_id → record
     * @param  Collection<int, ScoringCriteriaSet>  $sets
     * @return array<string, mixed>
     */
    private function community(Community $community, array $latest, Collection $sets, ?ScoringCriteriaSet $override): array
    {
        $base = ['id' => $community->id, 'code' => $community->code, 'name' => $community->name];

        $inputs = [];
        $sources = [];
        $missing = [];
        foreach (self::SHEETS as $key => [, $label]) {
            $record = $latest[$key]->get($community->id);
            if (! $record) {
                $missing[] = $label;

                continue;
            }
            $sources[$key] = $record->assessment_date->toDateString();
            $inputs += array_diff_key($record->getAttributes(), array_flip(self::NON_INPUTS));
        }

        $dataDate = $sources ? max($sources) : null;
        $set = $override ?? ($dataDate ? CriteriaSets::resolve(CriteriaSets::fiscalYear($dataDate), $sets) : null);

        $result = [
            ...$base,
            'status' => $missing ? 'missing' : 'ok',
            'missingSheets' => $missing,
            'sources' => $sources,
            'dataDate' => $dataDate,
            'population' => isset($inputs['population']) ? (int) $inputs['population'] : null,
            'criteriaSet' => $set ? ['id' => $set->id, 'name' => $set->name, 'startFiscalYear' => $set->start_fiscal_year] : null,
        ];

        if ($missing || ! $set) {
            return $result + ['hazards' => null, 'multi' => null, 'groups' => null, 'recovery' => null, 'trace' => null];
        }

        return $result + (new HevcCalculator($inputs, $set->weights))->calculate();
    }

    /**
     * Population-weighted averages over the communities with a value.
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
            foreach ($ok as $c) {
                $v = $value($c);
                if ($v === null || ! $c['population']) {
                    continue;
                }
                $sum += $v * $c['population'];
                $weight += $c['population'];
                $count++;
            }
            $hevc = $weight > 0 ? $sum / $weight : null;

            return ['hevc' => $hevc, 'level' => $hevc === null ? null : RiskLevel::of($hevc)->value, 'communities' => $count];
        };

        $hazards = [];
        foreach (HevcCalculator::HAZARDS as $h) {
            $hazards[$h] = $average(fn ($c) => $c['hazards'][$h]['hevc']);
        }
        $available = array_filter($hazards, fn ($r) => $r['hevc'] !== null);
        $dominant = null;
        foreach ($available as $h => $r) {
            if ($dominant === null || $r['hevc'] > $available[$dominant]['hevc']) {
                $dominant = $h;
            }
        }

        return [
            'multi' => $average(fn ($c) => $c['multi']['hevc']) + ['dominant' => $dominant],
            'hazards' => $hazards,
            'totalCommunities' => $communities->count(),
            'missingCommunities' => $communities->where('status', 'missing')->count(),
            'highRiskCommunities' => $ok->filter(fn ($c) => $c['multi']['level'] === RiskLevel::VeryHigh->value)->count(),
            'population' => $ok->sum('population'),
            'dataDate' => $communities->pluck('dataDate')->filter()->max(),
        ];
    }

    /**
     * @return array<string, Collection<int, Model>> sheet → community_id → its latest record on or before $asOf
     */
    private function latestRecords(?string $asOf): array
    {
        $latest = [];
        foreach (self::SHEETS as $key => [$model]) {
            $latest[$key] = $model::query()
                ->when($asOf, fn ($q) => $q->whereDate('assessment_date', '<=', $asOf))
                ->orderBy('assessment_date')
                ->get()
                ->keyBy('community_id'); // later dates overwrite earlier ones
        }

        return $latest;
    }
}
