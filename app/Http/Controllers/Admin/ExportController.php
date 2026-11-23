<?php

namespace App\Http\Controllers\Admin;

use App\Export\XlsxExport;
use App\Http\Controllers\Controller;
use App\Models\DisasterEvent;
use App\Models\KpiRecord;
use App\Scoring\CriteriaSets;
use App\Scoring\KpiCalculator;
use App\Scoring\PolicyFlags;
use App\Scoring\RiskEngine;
use App\Scoring\RiskTrend;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "ส่งออก Excel" (TOR 4.4-4) for the main dashboard tables, every role (the same data the pages show). Each export
 * reads the same engine/data as its page and honours the same filters from the query string.
 */
class ExportController extends Controller
{
    private const LEVEL = ['low' => 'ต่ำ', 'moderate' => 'ปานกลาง', 'high' => 'ค่อนข้างสูง', 'very_high' => 'สูง'];

    private const HAZARD = ['FLOOD' => 'น้ำท่วม', 'FIRE' => 'อัคคีภัย', 'STORM' => 'วาตภัย', 'HEAT' => 'ความร้อน', 'PM25' => 'ฝุ่น PM2.5'];

    public function risk(Request $request): StreamedResponse
    {
        [$year, $asOf] = $this->year($request);
        $result = (new RiskEngine)->run($asOf);
        $rows = collect($result['communities'])
            ->sortByDesc(fn ($c) => $c['multi']['hevc'] ?? -1)
            ->map(fn ($c) => [
                $c['code'], $c['name'],
                $c['status'] === 'ok' ? 'ใช้คำนวณแล้ว' : 'ข้อมูลไม่ครบ ('.implode(', ', $c['missingSheets']).')',
                $c['dataDate'], $c['criteriaSet']['name'] ?? null,
                self::r($c['multi']['hevc'] ?? null), self::LEVEL[$c['multi']['level'] ?? ''] ?? null,
                self::HAZARD[$c['multi']['dominant'] ?? ''] ?? null,
                ...array_map(fn ($h) => self::r($c['hazards'][$h]['hevc'] ?? null), array_keys(self::HAZARD)),
                $c['population'], self::r($c['recovery']),
                isset($c['inputs']['m_budget_avg_3y']) ? (float) $c['inputs']['m_budget_avg_3y'] : null,
            ])->values()->all();

        return XlsxExport::download("ความเสี่ยงรายชุมชน_ปีงบ{$year}.xlsx", "ความเสี่ยงรายชุมชน ปีงบประมาณ {$year}",
            'คำนวณจากระบบ HEVC (0–100) เรียงจากเสี่ยงมากไปน้อย', [
                'รหัส', 'ชุมชน', 'สถานะข้อมูล', 'วันที่ข้อมูล', 'ชุดเกณฑ์', 'HEVC รวม', 'ระดับ', 'ภัยเด่น',
                ...array_map(fn ($l) => "HEVC $l", array_values(self::HAZARD)), 'ประชากร', 'ความพร้อมฟื้นฟู (0–5)', 'งบด้านภัยเฉลี่ย 3 ปี (บาท)',
            ], $rows);
    }

    public function events(Request $request): StreamedResponse
    {
        $year = $request->integer('year') ?: null;
        $hazard = $request->query('hazard');
        $community = $request->query('community');
        $events = DisasterEvent::with('community:id,code,name')->orderByDesc('event_start')->get()
            ->filter(fn ($e) => ($year === null || CriteriaSets::fiscalYear($e->event_start->toDateString()) === $year)
                && (! $hazard || $hazard === 'ALL' || $e->primary_hazard === $hazard)
                && (! $community || $community === 'ALL' || $e->community->code === $community));
        $rows = $events->map(fn ($e) => [
            $e->event_id, $e->community->code, $e->community->name, $e->event_start->toDateString(), $e->event_end?->toDateString(),
            self::HAZARD[$e->primary_hazard] ?? $e->primary_hazard, $e->l1_houses_damaged_count, $e->l2_injury, $e->l2_death,
            $e->l3_damage_value_thb === null ? null : (float) $e->l3_damage_value_thb, $e->l4_recovery_days,
            ...array_map(fn ($c) => $e->getAttribute($c) === null ? null : (float) $e->getAttribute($c), [
                'f1_event_total_budget_thb', 'f2_event_response_cost_thb', 'f3_event_recovery_cost_thb',
                'f4_event_risk_reduction_budget_thb', 'f5_event_relief_compensation_thb',
            ]),
            $e->event_description,
        ])->values()->all();

        return XlsxExport::download('เหตุการณ์และความเสียหาย'.($year ? "_ปีงบ{$year}" : '').'.xlsx',
            'เหตุการณ์และความเสียหาย (Loss & Damage)'.($year ? " ปีงบประมาณ {$year}" : ' ทุกปี'),
            'จากแผ่นงานป้องกันภัยเหตุการณ์ · ใช้ประกอบการเสนอของบประมาณ (TOR 4.1.1(5)-3)', [
                'EVENT_ID', 'รหัสชุมชน', 'ชุมชน', 'วันเริ่ม', 'วันสิ้นสุด', 'ภัยหลัก', 'บ้านเสียหาย (หลัง)', 'บาดเจ็บ', 'เสียชีวิต',
                'มูลค่าความเสียหาย (บาท)', 'วันฟื้นตัว', 'F1 รวม', 'F2 ตอบสนอง', 'F3 ฟื้นฟู', 'F4 ลดความเสี่ยง', 'F5 เยียวยา', 'รายละเอียด',
            ], $rows);
    }

    public function kpi(Request $request): StreamedResponse
    {
        [$year, $asOf] = $this->year($request);
        $result = (new RiskEngine)->run($asOf);
        $kpiRecords = KpiRecord::query()->when($asOf, fn ($q) => $q->whereDate('assessment_date', '<=', $asOf))
            ->orderBy('assessment_date')->get()->groupBy('community_id');
        $definitions = KpiCalculator::definitions();
        $rows = [];
        foreach ($result['communities'] as $c) {
            $kpi = $kpiRecords->get($c['id'])?->last();
            $values = KpiCalculator::forCommunity($c['inputs'] + ($kpi ? $kpi->getAttributes() : []));
            $rows[] = [$c['code'], $c['name'], ...array_map(fn ($k) => self::r($values[$k]['score'] ?? null), array_keys($definitions))];
        }

        return XlsxExport::download("KPI_ปีงบ{$year}.xlsx", "KPI รายหน่วยงาน ปีงบประมาณ {$year}", 'คะแนน KPI 0–5 ต่อชุมชน (ตามสเปก §5.6)',
            ['รหัส', 'ชุมชน', ...array_map(fn ($d) => "{$d['label']} ({$d['agency']})", array_values($definitions))], $rows);
    }

    public function flags(): StreamedResponse
    {
        $result = (new RiskEngine)->run();
        $evaluated = PolicyFlags::evaluate($result, RiskTrend::series());
        $rows = array_map(fn ($f) => [$f['label'], $f['code'], $f['name'], $f['reason']], $evaluated['flags']);
        $t = $evaluated['thresholds'];

        return XlsxExport::download('PolicyFlag.xlsx', 'ระบบแจ้งเตือนอัตโนมัติ (Policy Flag)',
            ($t['mode'] === 'relative' ? "เกณฑ์เทียบกันเองในเมือง {$t['percent']}%" : 'เกณฑ์ตายตัว').' · ข้อมูลล่าสุด',
            ['ประเภท', 'รหัส', 'ชุมชน', 'เหตุผล (ตัวเลขที่ทำให้ติดธง)'], $rows);
    }

    /**
     * @return array{0: int|null, 1: string|null} the fiscal year and the engine's as-of date (null = latest)
     */
    private function year(Request $request): array
    {
        $years = RiskEngine::fiscalYears();
        $latest = $years ? max($years) : null;
        $year = in_array($request->integer('year'), $years, true) ? $request->integer('year') : $latest;

        return [$year, $year !== null && $year !== $latest ? RiskEngine::fiscalYearEnd($year) : null];
    }

    private static function r(?float $v): ?float
    {
        return $v === null ? null : round($v, 2);
    }
}
