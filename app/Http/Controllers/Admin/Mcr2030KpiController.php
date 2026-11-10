<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DisasterEvent;
use App\Models\KpiRecord;
use App\Scoring\KpiCalculator;
use App\Scoring\McrEvidence;
use App\Scoring\RiskEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้า 6 MCR2030 & KPI. Owner decisions (2026-11-10):
 * - KPI รายหน่วยงาน (spec v5 §5.4–5.10, the table moved here from page 5): scope is the city or one community.
 *   City score KPIs are population-weighted; percent KPIs pool Σ/Σ. The previous round uses the same rule as the
 *   engine (each community as of the day before its newest data), and city Δ compares like with like.
 * - MCR2030 (MCR2030_Alignment_Evidence_Community_v3.docx): evidence per community, never a score. For the city, it
 *   shows how many communities are at each status per Essential.
 */
class Mcr2030KpiController extends Controller
{
    /** Which sheet a KPI's "อัปเดตล่าสุด" date comes from. */
    private const KPI_SOURCE = [
        'KPI_C6' => 'disaster', 'KPI_C7' => 'disaster', 'KPI_C7_3' => 'disaster', 'KPI_C8_2' => 'disaster',
        'KPI_C4_3' => 'public_relations', 'KPI_WARNING' => 'public_relations',
        'KPI_INFRA_FLOOD' => 'engineering', 'KPI_INFRA_FIRE' => 'engineering', 'KPI_C5' => 'engineering',
        'KPI_C4_4' => 'education',
        'KPI_LOW_INCOME' => 'kpi', 'KPI_VULNERABLE' => 'kpi', 'KPI_MEDICAL' => 'kpi', 'KPI_ASSIST' => 'kpi',
    ];

    public function index(Request $request): Response
    {
        $years = RiskEngine::fiscalYears();
        $latest = $years ? max($years) : null;
        $year = in_array((int) $request->query('year'), $years, true) ? (int) $request->query('year') : $latest;
        $asOf = $year !== null && $year !== $latest ? RiskEngine::fiscalYearEnd($year) : null;

        $definitions = KpiCalculator::definitions();
        if ($year === null) {
            return Inertia::render('admin/Mcr2030Kpi', [
                'fiscalYears' => [], 'year' => null, 'initialCommunity' => null,
                'definitions' => $this->definitionsForPage($definitions), 'essentials' => $this->essentialsForPage(),
                'city' => null, 'communities' => [],
            ]);
        }

        $result = (new RiskEngine)->run($asOf);
        $kpiRecords = KpiRecord::query()
            ->when($asOf, fn ($q) => $q->whereDate('assessment_date', '<=', $asOf))
            ->orderBy('assessment_date')->get()->groupBy('community_id');
        $events = DisasterEvent::query()
            ->when($asOf, fn ($q) => $q->whereDate('event_start', '<=', $asOf))
            ->get()->groupBy('community_id');

        $rows = collect($result['communities'])->map(function (array $c) use ($kpiRecords, $events) {
            $kpiNow = $kpiRecords->get($c['id'])?->last();
            $kpiBefore = $c['previousCutoff']
                ? $kpiRecords->get($c['id'])?->filter(fn ($r) => $r->assessment_date->toDateString() <= $c['previousCutoff'])->last()
                : null;
            $strip = fn ($r) => $r ? array_diff_key($r->getAttributes(), array_flip(['id', 'community_id', 'assessment_date', 'import_batch_id', 'created_at', 'updated_at'])) : [];

            $now = $c['inputs'] + $strip($kpiNow);
            $before = $c['previousInputs'] !== null || $kpiBefore ? ($c['previousInputs'] ?? []) + $strip($kpiBefore) : null;
            $dates = $c['sources'] + ($kpiNow ? ['kpi' => $kpiNow->assessment_date->toDateString()] : []);

            return [
                'c' => $c,
                'now' => $now,
                'before' => $before,
                'dates' => $dates,
                'kpis' => KpiCalculator::forCommunity($now),
                'previousKpis' => $before !== null ? KpiCalculator::forCommunity($before) : null,
                'mcr' => McrEvidence::forCommunity($now + $this->eventFlags($events->get($c['id']) ?? collect()) + [
                    McrEvidence::HEVC => $c['status'] === 'ok' && ($c['multi']['hevc'] ?? null) !== null ? true : null,
                ]),
            ];
        });

        return Inertia::render('admin/Mcr2030Kpi', [
            'fiscalYears' => $years,
            'year' => $year,
            'initialCommunity' => $request->query('community'),
            'definitions' => $this->definitionsForPage($definitions),
            'essentials' => $this->essentialsForPage(),
            'city' => [
                'kpis' => collect($definitions)->map(fn ($def, $key) => $this->cityKpi($key, $def, $rows))->all(),
                'mcr' => collect(McrEvidence::essentials())->map(fn ($e, $key) => [
                    'full' => $rows->where("mcr.$key.status", 'full')->count(),
                    'partial' => $rows->where("mcr.$key.status", 'partial')->count(),
                    'none' => $rows->where("mcr.$key.status", 'none')->count(),
                    'found' => $rows->flatMap(fn ($r) => $r['mcr'][$key]['found'])->unique()->values()->all(),
                    'missing' => $rows->flatMap(fn ($r) => $r['mcr'][$key]['missing'])->unique()->values()->all(),
                ])->all(),
                'totalCommunities' => $rows->count(),
            ],
            'communities' => $rows->map(fn ($r) => [
                'code' => $r['c']['code'],
                'name' => $r['c']['name'],
                'kpis' => collect($r['kpis'])->map(fn ($k, $key) => [
                    'score' => $k['score'],
                    'raw' => $k['raw'],
                    'previous' => $r['previousKpis'][$key]['score'] ?? null,
                    'updated' => $r['dates'][self::KPI_SOURCE[$key]] ?? null,
                    'hasData' => $definitions[$key]['kind'] !== KpiCalculator::PERCENT || KpiCalculator::hasKpiData($key, $r['now']),
                ])->all(),
                'mcr' => $r['mcr'],
            ])->values()->all(),
        ]);
    }

    /**
     * City value of one KPI. Score KPIs: population-weighted mean. Percent KPIs: Σnumerator / Σdenominator per group.
     * Δ is computed over the communities that have both rounds.
     *
     * @param  array<string, mixed>  $def
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function cityKpi(string $key, array $def, Collection $rows): array
    {
        $updated = $rows->map(fn ($r) => $r['dates'][self::KPI_SOURCE[$key]] ?? null)->filter()->max();

        if ($def['kind'] === KpiCalculator::PENDING) {
            return ['score' => null, 'raw' => null, 'previous' => null, 'delta' => null, 'communities' => 0, 'updated' => $updated, 'hasData' => false];
        }

        if ($def['kind'] === KpiCalculator::PERCENT) {
            $pooled = function (Collection $subset, string $side) use ($def): array {
                $sums = [];
                foreach ($def['pairs'] as [$num, $den]) {
                    $both = $subset->filter(fn ($r) => ($r[$side][$num] ?? null) !== null && ($r[$side][$den] ?? null) !== null);
                    $sums[$num] = $both->isEmpty() ? null : $both->sum(fn ($r) => (float) $r[$side][$num]);
                    $sums[$den] = $both->isEmpty() ? null : $both->sum(fn ($r) => (float) $r[$side][$den]);
                }

                return KpiCalculator::percentKpi($def['pairs'], fn ($col) => $sums[$col] ?? null);
            };
            $now = $pooled($rows, 'now');
            $paired = $rows->filter(fn ($r) => $r['before'] !== null && $r['kpis'][$key]['score'] !== null && $r['previousKpis'][$key]['score'] !== null);
            $prev = $paired->isEmpty() ? null : $pooled($paired, 'before');
            $nowPaired = $paired->isEmpty() ? null : $pooled($paired, 'now');

            return [
                'score' => $now['score'],
                'raw' => $now['raw'],
                'previous' => $prev['score'] ?? null,
                'delta' => $prev && $nowPaired && $prev['score'] !== null && $nowPaired['score'] !== null ? $nowPaired['score'] - $prev['score'] : null,
                'communities' => $rows->filter(fn ($r) => $r['kpis'][$key]['score'] !== null)->count(),
                'updated' => $updated,
                'hasData' => $rows->contains(fn ($r) => KpiCalculator::hasKpiData($key, $r['now'])),
            ];
        }

        $sum = 0.0;
        $weight = 0;
        $count = 0;
        $dSum = 0.0;
        $pSum = 0.0;
        $dWeight = 0;
        foreach ($rows as $r) {
            $pop = (int) ($r['c']['population'] ?? 0);
            $v = $r['kpis'][$key]['score'];
            if ($v === null || $pop <= 0) {
                continue;
            }
            $sum += $v * $pop;
            $weight += $pop;
            $count++;
            $p = $r['previousKpis'][$key]['score'] ?? null;
            if ($p !== null) {
                $dSum += ($v - $p) * $pop;
                $pSum += $p * $pop;
                $dWeight += $pop;
            }
        }

        return [
            'score' => $weight ? $sum / $weight : null,
            'raw' => null,
            'previous' => $dWeight ? $pSum / $dWeight : null,
            'delta' => $dWeight ? $dSum / $dWeight : null,
            'communities' => $count,
            'updated' => $updated,
            'hasData' => true,
        ];
    }

    /**
     * Pseudo-columns for McrEvidence from a community's events: true when any event has the value.
     *
     * @param  Collection<int, DisasterEvent>  $events
     * @return array<string, true|null>
     */
    private function eventFlags(Collection $events): array
    {
        $any = fn (array $cols) => $events->contains(fn ($e) => collect($cols)->contains(fn ($c) => $e->getAttribute($c) !== null)) ? true : null;

        return [
            McrEvidence::EVENT_LOSS => $any(['l1_houses_damaged_count', 'l2_injury', 'l2_death', 'l3_damage_value_thb', 'l4_recovery_days', 'l5_repeat_damage_count']),
            McrEvidence::EVENT_DAMAGE_VALUE => $any(['l3_damage_value_thb']),
            McrEvidence::EVENT_FINANCE_RESPONSE => $any(['f2_event_response_cost_thb']),
            McrEvidence::EVENT_FINANCE_RECOVERY => $any(['f3_event_recovery_cost_thb']),
            McrEvidence::EVENT_FINANCE_REDUCTION => $any(['f4_event_risk_reduction_budget_thb']),
            McrEvidence::EVENT_FINANCE_RELIEF => $any(['f5_event_relief_compensation_thb']),
            McrEvidence::EVENT_OBSERVED => $any(['r1_observed', 'r2_observed', 'r3_observed', 'r4_observed', 'r5_observed', 'l4_recovery_days']),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, array{agency: string, label: string, kind: string, formula: string}>
     */
    private function definitionsForPage(array $definitions): array
    {
        return array_map(fn ($d) => ['agency' => $d['agency'], 'label' => $d['label'], 'kind' => $d['kind'], 'formula' => $d['formula']], $definitions);
    }

    /**
     * @return array<string, array{label: string, topics: list<string>}>
     */
    private function essentialsForPage(): array
    {
        return array_map(fn ($e) => ['label' => $e['label'], 'topics' => array_column($e['topics'], 'label')], McrEvidence::essentials());
    }
}
