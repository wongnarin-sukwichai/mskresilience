<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DisasterEvent;
use App\Models\DisasterRecord;
use App\Models\ScoringCriteriaSet;
use App\Scoring\CriteriaSets;
use App\Scoring\HevcCalculator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้า 5 ผลกระทบและการฟื้นตัว (spec v5 §5.1–5.3). Owner decisions (2026-11-10):
 * - The page shows a summary of the filtered events (fiscal year / hazard / community), plus a table where clicking a
 *   row opens one event. One EVENT_ID is one community's record of an event.
 * - "Readiness before the event" is that community's RECOVERY_AVG from its latest disaster_records row on or before
 *   EVENT_START. Readiness and RECOVERY_OBSERVED_AVG use the same set: the one resolved for the event's fiscal year.
 * - Items in the layout picture with no input column (people affected, schools, temples, clinics, farmland) are
 *   left out, and the KPI table moves to page 6.
 * - L&D and finance are shown as real values, never combined into a score. F1 = F2+F3+F4+F5 (spec §5.3), so a
 *   different imported F1 is flagged.
 * Events are few (one per community per event), so all are sent and filtered in the page.
 */
class ImpactRecoveryController extends Controller
{
    public function index(): Response
    {
        $sets = ScoringCriteriaSet::query()->orderBy('start_fiscal_year')->get();
        $records = DisasterRecord::query()->with('community')->orderBy('assessment_date')->get()->groupBy('community_id');

        $events = DisasterEvent::query()->with('community')->orderByDesc('event_start')->orderBy('event_id')->get()
            ->map(function (DisasterEvent $e) use ($sets, $records) {
                $start = $e->event_start->toDateString();
                $fiscalYear = CriteriaSets::fiscalYear($start);
                $set = CriteriaSets::resolve($fiscalYear, $sets);
                $a = $e->getAttributes();

                $readinessRecord = $records->get($e->community_id)?->filter(fn ($r) => $r->assessment_date->toDateString() <= $start)->last();
                $readiness = $readinessRecord && $set ? [
                    'date' => $readinessRecord->assessment_date->toDateString(),
                    'items' => [
                        'R1' => $readinessRecord->r1_recovery_plan_score,
                        'R2' => $readinessRecord->r2_build_back_better_score,
                        'R3' => $readinessRecord->r3_socioeconomic_recov_score,
                        'R4' => $readinessRecord->r4_vulner_group_recov_score,
                        'R5' => $readinessRecord->r5_learning_sys_improv_score,
                    ],
                    'avg' => HevcCalculator::recoveryReadiness($readinessRecord->getAttributes(), $set->weights),
                ] : null;
                $observedAvg = $set ? HevcCalculator::recoveryObserved($a, $set->weights) : null;

                $parts = array_map(fn ($k) => $a[$k] === null ? null : (float) $a[$k], [
                    'F2' => 'f2_event_response_cost_thb',
                    'F3' => 'f3_event_recovery_cost_thb',
                    'F4' => 'f4_event_risk_reduction_budget_thb',
                    'F5' => 'f5_event_relief_compensation_thb',
                ]);
                $hasParts = count(array_filter($parts, fn ($v) => $v !== null)) > 0;
                $importedF1 = $a['f1_event_total_budget_thb'] === null ? null : (float) $a['f1_event_total_budget_thb'];
                $f1 = $hasParts ? array_sum(array_map(fn ($v) => $v ?? 0, $parts)) : $importedF1;

                return [
                    'id' => $e->event_id,
                    'code' => $e->community->code,
                    'name' => $e->community->name,
                    'start' => $start,
                    'end' => $e->event_end?->toDateString(),
                    'fiscalYear' => $fiscalYear,
                    'hazard' => $e->primary_hazard,
                    'related' => array_values(array_filter(explode(',', (string) $e->related_hazards))),
                    'multi' => (bool) $e->multi_hazard_flag,
                    'description' => $e->event_description,
                    'loss' => [
                        'houses' => $a['l1_houses_damaged_count'],
                        'injury' => $a['l2_injury'],
                        'death' => $a['l2_death'],
                        'damageThb' => $a['l3_damage_value_thb'] === null ? null : (float) $a['l3_damage_value_thb'],
                        'recoveryDays' => $a['l4_recovery_days'],
                        'repeat' => $a['l5_repeat_damage_count'],
                    ],
                    'finance' => [
                        'F1' => $f1,
                        ...$parts,
                        'importedF1Mismatch' => $hasParts && $importedF1 !== null && abs($importedF1 - $f1) > 0.01,
                    ],
                    'observed' => [
                        'items' => ['R1' => $a['r1_observed'], 'R2' => $a['r2_observed'], 'R3' => $a['r3_observed'], 'R4' => $a['r4_observed'], 'R5' => $a['r5_observed']],
                        'avg' => $observedAvg,
                    ],
                    'readiness' => $readiness,
                    'criteriaSet' => $set?->name,
                ];
            });

        // งบด้านการจัดการภัยเฉลี่ย 3 ปี: each community's latest value, shown next to the event spending.
        $budgets = $records->map(fn ($rows) => $rows->last())
            ->filter(fn ($r) => $r->m_budget_avg_3y !== null)
            ->mapWithKeys(fn ($r) => [$r->community->code => (float) $r->m_budget_avg_3y]);

        return Inertia::render('admin/ImpactRecovery', [
            'events' => $events->values(),
            'budgets' => $budgets,
        ]);
    }
}
