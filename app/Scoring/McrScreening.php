<?php

namespace App\Scoring;

use App\Enums\WorkGroup;
use App\Models\DisasterEvent;
use App\Models\KpiRecord;
use Illuminate\Support\Collection;

/**
 * The MCR2030 evidence screening of every community in an engine run (McrEvidence on each community's inputs, its
 * KPI record, its events and the กลุ่มงาน that sent its data). Shared by หน้า 6 (MCR2030 & KPI) and the executive
 * page, so the coverage gauge shows the same number on both.
 */
class McrScreening
{
    /**
     * @param  array{communities: list<array<string, mixed>>}  $result  RiskEngine::run() for $asOf
     * @return array<string, array<string, array<string, mixed>>> community code → McrEvidence::forCommunity()
     */
    public static function forRun(array $result, ?string $asOf): array
    {
        $kpiRecords = KpiRecord::query()
            ->when($asOf, fn ($q) => $q->whereDate('assessment_date', '<=', $asOf))
            ->orderBy('assessment_date')->get()->groupBy('community_id');
        $events = DisasterEvent::query()
            ->when($asOf, fn ($q) => $q->whereDate('event_start', '<=', $asOf))
            ->get()->groupBy('community_id');

        $out = [];
        foreach ($result['communities'] as $c) {
            $kpiNow = $kpiRecords->get($c['id'])?->last();
            $own = $events->get($c['id']) ?? collect();
            $kpiValues = $kpiNow ? array_diff_key($kpiNow->getAttributes(), array_flip(['id', 'community_id', 'assessment_date', 'import_batch_id', 'created_at', 'updated_at'])) : [];
            $out[$c['code']] = McrEvidence::forCommunity($c['inputs'] + $kpiValues + self::eventFlags($own) + [
                McrEvidence::HEVC => $c['status'] === 'ok' && ($c['multi']['hevc'] ?? null) !== null ? true : null,
                McrEvidence::IMPORTED_BY => self::importedBy($c, $kpiNow !== null, $own->isNotEmpty()),
            ], hasEvents: $own->isNotEmpty());
        }

        return $out;
    }

    /**
     * The city's coverage 0–10: the mean of the communities' coverage.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $screenings  forRun()'s result
     */
    public static function cityCoverage(array $screenings): ?float
    {
        return $screenings ? round(collect($screenings)->avg(fn ($s) => McrEvidence::coverage($s)), 2) : null;
    }

    /**
     * E1 "การกำหนดหน่วยงานรับผิดชอบ" (client 2026-11-23): the กลุ่มงาน whose imports supplied this community's data
     * in the round. Each sheet's table can only be written by its own กลุ่มงาน (import or web edit, both checked
     * with User::canImport), so a sheet with a record = that group sent it. The engine's `sources` keys are the
     * WorkGroup values of the 6 assessment sheets.
     *
     * @param  array<string, mixed>  $c  the engine's community result
     * @return list<string> group labels, in WorkGroup order
     */
    public static function importedBy(array $c, bool $hasKpi, bool $hasEvents): array
    {
        $groups = array_keys($c['sources']);
        if ($hasKpi) {
            $groups[] = WorkGroup::Kpi->value;
        }
        if ($hasEvents) {
            $groups[] = WorkGroup::DisasterEvent->value;
        }

        return array_values(array_map(
            fn (WorkGroup $g) => $g->label(),
            array_filter(WorkGroup::cases(), fn (WorkGroup $g) => in_array($g->value, $groups, true)),
        ));
    }

    /**
     * Pseudo-columns for McrEvidence from a community's events: true when any event has the value.
     *
     * @param  Collection<int, DisasterEvent>  $events
     * @return array<string, true|null>
     */
    public static function eventFlags(Collection $events): array
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
}
