<?php

namespace App\Http\Controllers\Admin;

use App\Ai\SummaryWriter;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AiSummary;
use App\Models\Community;
use App\Scoring\ExecutiveSummary;
use App\Scoring\InvestmentEffect;
use App\Scoring\McrScreening;
use App\Scoring\PolicyFlags;
use App\Scoring\RiskEngine;
use App\Scoring\RiskTrend;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * สรุปสำหรับผู้บริหาร (Mayor View, TOR 4.1.1(3), 4.3-4; owner 2026-11-23): the first page of แดชบอร์ด, every role.
 * - HEVC × budget scatter for the 32 communities (4.1.1(3)-1)
 * - MCR2030 evidence coverage 0–10 (4.1.1(3)-2)
 * - Policy Flags with admin thresholds (4.1.1(3)-3, 4.1.2)
 * - Executive Summary, rule-based or AI (4.1.1(3)-4)
 * - Before–after investment (4.1.2, คุณลักษณะ 2.5-3, 2.6-3)
 * Always the latest data (a "now" view); the trend covers every fiscal year.
 */
class ExecutiveController extends Controller
{
    public function index(Request $request): Response
    {
        $years = RiskEngine::fiscalYears();
        if (! $years) {
            return Inertia::render('admin/Executive', ['empty' => true]);
        }

        [$result, $trend, $flags, $coverage, $investments, $facts] = $this->analysis();
        $isAdmin = $request->user()->role === UserRole::Admin;
        $mode = Settings::get('summary_mode');
        $ai = $mode === 'ai'
            ? AiSummary::where('facts_hash', SummaryWriter::hash($facts))->where('status', 'ok')->latest('id')->first()
            : null;

        return Inertia::render('admin/Executive', [
            'empty' => false,
            'year' => max($years),
            'dataDate' => $result['city']['dataDate'],
            'city' => [
                'hevc' => $result['city']['multi']['value'],
                'level' => $result['city']['multi']['level'],
                'dominant' => $result['city']['multi']['dominant'],
                'scored' => $result['city']['scoredCommunities'],
                'total' => $result['city']['totalCommunities'],
                'trend' => array_map(fn ($y) => ['year' => $y, 'hevc' => $trend['city'][$y]['multi']], $trend['years']),
            ],
            'scatter' => array_values(array_filter(array_map(fn ($c) => $c['status'] === 'ok' && $c['population'] && isset($c['inputs']['m_budget_avg_3y']) ? [
                'code' => $c['code'],
                'name' => $c['name'],
                'hevc' => $c['multi']['hevc'],
                'level' => $c['multi']['level'],
                'budgetPerPerson' => (float) $c['inputs']['m_budget_avg_3y'] / $c['population'],
            ] : null, $result['communities']))),
            'flags' => $flags,
            'flagTypes' => PolicyFlags::TYPES,
            'mcrCoverage' => $coverage,
            'investments' => array_slice($investments, 0, 8),
            'summary' => [
                'mode' => $mode,
                'rules' => ExecutiveSummary::rules($facts),
                'ai' => $ai ? ['text' => $ai->text, 'at' => $ai->created_at->toIso8601String(), 'model' => $ai->model] : null,
                'aiConfigured' => SummaryWriter::configured(),
                // Admins see what is sent and the cost log (owner: an info button about the cost).
                'facts' => $isAdmin ? $facts : null,
                'usage' => $isAdmin ? AiSummary::with('user:id,name')->latest('id')->limit(10)->get()->map(fn (AiSummary $s) => [
                    'at' => $s->created_at->toIso8601String(),
                    'status' => $s->status,
                    'error' => $s->error,
                    'model' => $s->model,
                    'inputTokens' => $s->input_tokens,
                    'outputTokens' => $s->output_tokens,
                    'costUsd' => $s->cost_usd,
                    'user' => $s->user?->name,
                ]) : null,
                'pricing' => [
                    'model' => config('ai.model'),
                    'inputPerMTok' => config('ai.price_input_per_mtok'),
                    'outputPerMTok' => config('ai.price_output_per_mtok'),
                    'usdToThb' => config('ai.usd_to_thb'),
                ],
                // Admin ⓘ: the same call priced on other vendors' models (estimate only; config/ai.php).
                'compare' => $isAdmin ? ['checkedAt' => config('ai.compare_checked_at'), 'vendors' => config('ai.compare')] : null,
            ],
        ]);
    }

    /**
     * Admin: write the summary with AI for the current data (one API call, logged with its cost).
     */
    public function generate(Request $request): RedirectResponse
    {
        if (Settings::get('summary_mode') !== 'ai') {
            return back()->with('error', 'ยังไม่ได้เปิดโหมด AI ที่ตั้งค่าทั่วไป');
        }
        if (! SummaryWriter::configured()) {
            return back()->with('error', 'ยังไม่ได้ตั้งค่า ANTHROPIC_API_KEY บนเซิร์ฟเวอร์ ระบบใช้สรุปแบบกฎแทน');
        }
        $facts = $this->analysis()[5];
        $summary = SummaryWriter::generate($facts, $request->user());
        activity('settings')->causedBy($request->user())->performedOn($summary)->event('ai_summary')
            ->withProperties(['details' => ['status' => $summary->status, 'cost_usd' => $summary->cost_usd]])
            ->log('สร้างสรุปภาพรวมด้วย AI');

        return match ($summary->status) {
            'ok' => back()->with('status', 'สร้างสรุปด้วย AI แล้ว'),
            'rejected_numbers' => back()->with('error', 'ไม่ใช้ข้อความจาก AI เพราะ'.$summary->error.' ระบบใช้สรุปแบบกฎแทน'),
            default => back()->with('error', 'สร้างสรุปด้วย AI ไม่สำเร็จ: '.$summary->error),
        };
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: float|null, 4: list<array<string, mixed>>, 5: array<string, mixed>}
     */
    private function analysis(): array
    {
        $result = (new RiskEngine)->run();
        $trend = RiskTrend::series();
        $flags = PolicyFlags::evaluate($result, $trend);
        $coverage = McrScreening::cityCoverage(McrScreening::forRun($result, null));
        $investments = InvestmentEffect::points($trend, Community::pluck('name', 'code')->all());
        $facts = ExecutiveSummary::facts($result, $trend, $flags, $coverage, $investments);

        return [$result, $trend, $flags, $coverage, $investments, $facts];
    }
}
