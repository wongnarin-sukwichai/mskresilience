<?php

namespace App\Scoring;

use App\Models\DisasterEvent;

/**
 * The executive summary (TOR 4.1.1(3)-4 "แสดงข้อความวิเคราะห์สั้นอัตโนมัติ", คุณลักษณะ 2.4-4). facts() collects the
 * numbers once; rules() turns them into Thai sentences with fixed rules (the default, free, fully traceable). The AI
 * mode (App\Ai\SummaryWriter) gets the same facts and may only reword them.
 */
class ExecutiveSummary
{
    private const LEVEL_TH = ['low' => 'ต่ำ', 'moderate' => 'ปานกลาง', 'high' => 'ค่อนข้างสูง', 'very_high' => 'สูง'];

    private const HAZARD_TH = ['FLOOD' => 'น้ำท่วม', 'FIRE' => 'อัคคีภัย', 'STORM' => 'วาตภัย', 'HEAT' => 'ความร้อน', 'PM25' => 'ฝุ่น PM2.5'];

    /**
     * Only aggregated, community-level numbers (no people, no raw inputs): what the page already shows.
     *
     * @param  array<string, mixed>  $result  latest RiskEngine run
     * @param  array<string, mixed>  $trend  RiskTrend::series()
     * @param  array<string, mixed>  $flags  PolicyFlags::evaluate()
     * @param  list<array<string, mixed>>  $investments  InvestmentEffect::points()
     * @return array<string, mixed>
     */
    public static function facts(array $result, array $trend, array $flags, ?float $mcrCoverage, array $investments): array
    {
        $years = $trend['years'];
        $year = $years ? max($years) : null;
        $previousYear = count($years) > 1 ? $years[count($years) - 2] : null;
        $city = $result['city']['multi'];
        $previous = $previousYear ? ($trend['city'][$previousYear]['multi'] ?? null) : null;

        $scored = array_filter($result['communities'], fn ($c) => $c['status'] === 'ok' && ($c['multi']['hevc'] ?? null) !== null);
        usort($scored, fn ($a, $b) => $b['multi']['hevc'] <=> $a['multi']['hevc']);
        $levels = array_fill_keys(array_values(self::LEVEL_TH), 0);
        foreach ($scored as $c) {
            $levels[self::LEVEL_TH[$c['multi']['level']]]++;
        }

        $eventsIn = function (?int $fy) {
            if ($fy === null) {
                return null;
            }
            $events = DisasterEvent::query()
                ->whereDate('event_start', '>=', sprintf('%04d-10-01', $fy - 544))
                ->whereDate('event_start', '<=', RiskEngine::fiscalYearEnd($fy))->get();

            return [
                'count' => $events->count(),
                'damage_million_baht' => round($events->sum(fn ($e) => (float) $e->l3_damage_value_thb) / 1_000_000, 2),
                'houses_damaged' => (int) $events->sum('l1_houses_damaged_count'),
            ];
        };

        $flagFacts = [];
        foreach (PolicyFlags::TYPES as $type => $meta) {
            $names = array_values(array_unique(array_column(array_filter($flags['flags'], fn ($f) => $f['type'] === $type), 'name')));
            $flagFacts[] = ['flag' => $meta['label'], 'communities_count' => count($names), 'communities' => array_slice($names, 0, 5)];
        }
        $best = collect($investments)->filter(fn ($p) => $p['delta'] !== null)->sortBy('delta')->first();

        return [
            'fiscal_year' => $year,
            'data_date' => $result['city']['dataDate'],
            'communities_scored' => count($scored),
            'communities_total' => $result['city']['totalCommunities'],
            'city_hevc' => $city['value'] !== null ? round($city['value'], 1) : null,
            'city_level' => $city['level'] ? self::LEVEL_TH[$city['level']] : null,
            'previous_fiscal_year' => $previousYear,
            'previous_city_hevc' => $previous !== null ? round($previous, 1) : null,
            'city_change' => $previous !== null && $city['value'] !== null ? round($city['value'] - $previous, 1) : null,
            'dominant_hazard' => $city['dominant'] ? self::HAZARD_TH[$city['dominant']] : null,
            'communities_by_level' => $levels,
            'top_risk_communities' => array_map(fn ($c) => ['name' => $c['name'], 'hevc' => round($c['multi']['hevc'], 1), 'level' => self::LEVEL_TH[$c['multi']['level']]], array_slice($scored, 0, 3)),
            'policy_flags' => $flagFacts,
            'mcr_evidence_coverage' => $mcrCoverage,
            'mcr_coverage_max' => 10,
            'events_this_year' => $eventsIn($year),
            'events_previous_year' => $eventsIn($previousYear),
            'investment_example' => $best ? [
                'community' => $best['name'],
                'what' => $best['label'],
                'hevc_before' => round($best['before']['multi'], 1),
                'fiscal_year_before' => $best['before']['year'],
                'hevc_latest' => round(end($best['after'])['multi'], 1),
                'fiscal_year_latest' => end($best['after'])['year'],
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $f  facts()
     * @return list<string>
     */
    public static function rules(array $f): array
    {
        if ($f['city_hevc'] === null) {
            return ['ยังไม่มีข้อมูลเพียงพอสำหรับสรุปภาพรวม'];
        }
        $out = [];
        $sentence = "ปีงบประมาณ {$f['fiscal_year']} ความเสี่ยงรวมของเมืองอยู่ที่ {$f['city_hevc']} คะแนน ระดับ{$f['city_level']}";
        if ($f['city_change'] !== null) {
            $sentence .= match (true) {
                $f['city_change'] <= -0.5 => ' ลดลง '.abs($f['city_change'])." คะแนนจากปีงบ {$f['previous_fiscal_year']}",
                $f['city_change'] >= 0.5 => " เพิ่มขึ้น {$f['city_change']} คะแนนจากปีงบ {$f['previous_fiscal_year']}",
                default => " ทรงตัวเมื่อเทียบกับปีงบ {$f['previous_fiscal_year']}",
            };
        }
        $out[] = $sentence;

        $l = $f['communities_by_level'];
        $out[] = "ภัยที่ควรเฝ้าระวังมากที่สุดคือ{$f['dominant_hazard']} · จาก {$f['communities_scored']} ชุมชน มีระดับสูง {$l['สูง']} ชุมชน ค่อนข้างสูง {$l['ค่อนข้างสูง']} ชุมชน ปานกลาง {$l['ปานกลาง']} ชุมชน และต่ำ {$l['ต่ำ']} ชุมชน";

        if ($f['top_risk_communities']) {
            $out[] = 'ชุมชนที่เสี่ยงสูงสุด ได้แก่ '.implode(', ', array_map(fn ($c) => "{$c['name']} ({$c['hevc']})", $f['top_risk_communities']));
        }

        $active = array_filter($f['policy_flags'], fn ($x) => $x['communities_count'] > 0);
        $out[] = $active
            ? 'สัญญาณที่ควรติดตาม: '.implode(' · ', array_map(fn ($x) => "{$x['flag']} {$x['communities_count']} ชุมชน (".implode(', ', $x['communities']).')', $active))
            : 'ไม่พบชุมชนที่เข้าเงื่อนไขสัญญาณเตือนตามเกณฑ์ที่ตั้งไว้';

        if ($e = $f['events_this_year']) {
            $text = "ปีงบ {$f['fiscal_year']} เกิดเหตุ {$e['count']} ครั้ง ความเสียหายรวม {$e['damage_million_baht']} ล้านบาท บ้านเรือนเสียหาย {$e['houses_damaged']} หลัง";
            if ($p = $f['events_previous_year']) {
                $text .= " (ปีงบ {$f['previous_fiscal_year']}: {$p['damage_million_baht']} ล้านบาท)";
            }
            $out[] = $text;
        }

        if ($i = $f['investment_example']) {
            $out[] = "ตัวอย่างหลังการลงทุน: {$i['community']} ({$i['what']}) HEVC จาก {$i['hevc_before']} ในปีงบ {$i['fiscal_year_before']} เป็น {$i['hevc_latest']} ในปีงบ {$i['fiscal_year_latest']}";
        }

        if ($f['mcr_evidence_coverage'] !== null) {
            $out[] = "หลักฐานตามกรอบ MCR2030 ครอบคลุม {$f['mcr_evidence_coverage']} จาก {$f['mcr_coverage_max']} หลักสำคัญ";
        }

        return $out;
    }
}
