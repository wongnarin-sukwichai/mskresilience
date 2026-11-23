<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DisasterEvent;
use App\Scoring\CriteriaSets;
use App\Scoring\McrEvidence;
use App\Scoring\McrScreening;
use App\Scoring\PolicyFlags;
use App\Scoring\RiskEngine;
use App\Scoring\RiskTrend;
use Inertia\Inertia;
use Inertia\Response;

/**
 * รายงานหลักฐานประกอบการเสนอของบประมาณรายชุมชน (TOR 4.1.1(5)-3 "สนับสนุนการใช้ข้อมูลประกอบการเสนอของบประมาณหรือ
 * กองทุน", one of the 2 ready-made reports, owner 2026-11-23). One printable page per community: its risk now and
 * over the years, what has happened (losses and spending per fiscal year), its budget next to the city's, its
 * recovery readiness, the Policy Flags it triggers and its MCR evidence. Every role; the latest data.
 */
class FundingReportController extends Controller
{
    public function show(string $code): Response
    {
        $result = (new RiskEngine)->run();
        $c = collect($result['communities'])->firstWhere('code', strtoupper($code));
        abort_unless($c, 404);

        $trend = RiskTrend::series();
        $flags = PolicyFlags::evaluate($result, $trend);
        $screening = McrScreening::forRun($result, null)[$c['code']];

        $perPerson = fn ($x) => isset($x['inputs']['m_budget_avg_3y']) && $x['population'] ? (float) $x['inputs']['m_budget_avg_3y'] / $x['population'] : null;
        $cityPerPerson = collect($result['communities'])->map($perPerson)->filter()->sort()->values();

        $events = DisasterEvent::where('community_id', $c['id'])->orderByDesc('event_start')->get();
        $byYear = $events->groupBy(fn ($e) => CriteriaSets::fiscalYear($e->event_start->toDateString()))->sortKeys()
            ->map(fn ($g, $fy) => [
                'year' => $fy,
                'count' => $g->count(),
                'houses' => (int) $g->sum('l1_houses_damaged_count'),
                'injury' => (int) $g->sum('l2_injury'),
                'death' => (int) $g->sum('l2_death'),
                'damage' => (float) $g->sum(fn ($e) => (float) $e->l3_damage_value_thb),
                'f2' => (float) $g->sum(fn ($e) => (float) $e->f2_event_response_cost_thb),
                'f3' => (float) $g->sum(fn ($e) => (float) $e->f3_event_recovery_cost_thb),
                'f4' => (float) $g->sum(fn ($e) => (float) $e->f4_event_risk_reduction_budget_thb),
                'f5' => (float) $g->sum(fn ($e) => (float) $e->f5_event_relief_compensation_thb),
            ])->values();

        return Inertia::render('admin/FundingReport', [
            'community' => [
                'code' => $c['code'],
                'name' => $c['name'],
                'status' => $c['status'],
                'dataDate' => $c['dataDate'],
                'criteriaSet' => $c['criteriaSet']['name'] ?? null,
                'population' => $c['population'],
                'households' => $c['counts']['households'],
                'areaKm2' => $c['areaKm2'],
                'multi' => $c['multi'],
                'hazards' => $c['hazards'] ? array_map(fn ($h) => ['hevc' => $h['hevc'], 'level' => $h['level'], 'e' => $h['e'], 'ePercent' => $h['ePercent'], 'v' => $h['v'], 'vPeople' => $h['vPeople'], 'vSystem' => $h['vSystem'], 'cRaw' => $h['cRaw']], $c['hazards']) : null,
                'recovery' => $c['recovery'],
                'recoveryItems' => CommunityMapController::recoveryItems($c['inputs']),
                'budget' => isset($c['inputs']['m_budget_avg_3y']) ? (float) $c['inputs']['m_budget_avg_3y'] : null,
                'budgetPerPerson' => $perPerson($c),
            ],
            'city' => [
                'hevc' => $result['city']['multi']['value'],
                'budgetPerPersonMedian' => $cityPerPerson->isEmpty() ? null : $cityPerPerson[intdiv($cityPerPerson->count(), 2)],
                'recoveryItems' => CommunityMapController::cityRecoveryItems($result['communities']),
                'rank' => collect($result['communities'])->filter(fn ($x) => ($x['multi']['hevc'] ?? null) !== null)
                    ->sortByDesc(fn ($x) => $x['multi']['hevc'])->values()->search(fn ($x) => $x['code'] === $c['code']),
                'scored' => $result['city']['scoredCommunities'],
            ],
            'trend' => array_values(array_map(fn ($year, $p) => ['year' => $year, 'multi' => $p['multi'], 'stale' => $p['stale']],
                array_keys($trend['communities'][$c['code']] ?? []), $trend['communities'][$c['code']] ?? [])),
            'flags' => array_values(array_filter($flags['flags'], fn ($f) => $f['code'] === $c['code'])),
            'lossByYear' => $byYear,
            'events' => $events->map(fn ($e) => [
                'id' => $e->event_id,
                'start' => $e->event_start->toDateString(),
                'hazard' => $e->primary_hazard,
                'houses' => $e->l1_houses_damaged_count,
                'injury' => $e->l2_injury,
                'death' => $e->l2_death,
                'damage' => $e->l3_damage_value_thb === null ? null : (float) $e->l3_damage_value_thb,
                'description' => $e->event_description,
            ])->values(),
            'mcr' => ['coverage' => McrEvidence::coverage($screening), 'essentials' => array_map(fn ($s) => ['status' => $s['status'], 'sentence' => $s['sentence']], $screening)],
            'essentialLabels' => array_map(fn ($e) => $e['label'], McrEvidence::essentials()),
        ]);
    }
}
