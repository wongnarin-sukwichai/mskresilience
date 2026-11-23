<?php

namespace App\Console\Commands;

use App\Demo\MockData;
use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Import\FileImporter;
use App\Import\ImportSheets;
use App\Import\Sheets\TableSheet;
use App\Models\Community;
use App\Models\ImportBatch;
use App\Models\ImportReplacedRow;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Scoring\EngineCache;
use App\Scoring\RiskEngine;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Replaces ALL imported data with the 3-year MOCK data set (App\Demo\MockData) for the TOR acceptance demo
 * (owner 2026-11-23). Every file goes in through FileImporter, the same path as a staff upload, dated as if it was
 * imported during its fiscal year. Also sets up the criteria-change demo: the existing set starts at 2567 and a
 * second set with slightly different weights starts at 2569.
 *
 * Not for production data: it deletes every record of the 8 import sheets and the import history first.
 */
class DemoData extends Command
{
    protected $signature = 'demo:data {--force : Skip the confirmation}';

    protected $description = 'Replace all imported data with the 3-year mock demo data (FY2567–2569)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('ลบข้อมูลนำเข้าทั้งหมดและประวัตินำเข้า แล้วใส่ข้อมูลสมมุติ 3 ปีงบ ใช่ไหม?')) {
            return self::FAILURE;
        }
        $admin = User::where('role', UserRole::Admin)->orderBy('id')->first();
        if (! $admin || Community::count() === 0) {
            $this->error('ต้องมีบัญชีผู้ดูแลระบบและรายชื่อชุมชนก่อน');

            return self::FAILURE;
        }

        $this->clear();
        $this->criteria();
        Settings::set('welcome_mock_notice', true);

        $mock = new MockData(Community::pluck('name', 'code')->all());
        $disk = Storage::disk('local');
        $groups = [WorkGroup::Disaster, WorkGroup::Welfare, WorkGroup::Health, WorkGroup::Education, WorkGroup::Engineering,
            WorkGroup::PublicRelations, WorkGroup::Kpi, WorkGroup::DisasterEvent];

        foreach (MockData::YEARS as $year) {
            foreach ($groups as $i => $group) {
                $book = new Spreadsheet;
                $book->getActiveSheet()->fromArray($mock->grid($group, $year), null, 'A1', true);
                $tmp = 'imports/tmp/demo-'.$group->value.'-'.$year.'.xlsx';
                $disk->makeDirectory('imports/tmp');
                (new Xlsx($book))->save($disk->path($tmp));

                // Dated as if imported during that fiscal year: assessments in February, events at the year's end.
                $ce = $year - 543;
                Carbon::setTestNow($group === WorkGroup::DisasterEvent
                    ? Carbon::create($ce, 9, 28, 10)
                    : Carbon::create($ce, 2, 20 + $i, 9, 30));
                ['batch' => $batch, 'error' => $error] = FileImporter::confirm($group, $admin, $tmp, "ข้อมูลสมมุติ_{$group->label()}_ปีงบ{$year}.xlsx");
                Carbon::setTestNow();
                if ($error) {
                    $this->error("{$year} {$group->label()}: {$error}");

                    return self::FAILURE;
                }
                $this->line("ปีงบ {$year} {$group->label()}: {$batch->row_count} แถว (#{$batch->id})");
            }
        }

        $this->newLine();
        foreach (MockData::YEARS as $year) {
            $last = $year === max(MockData::YEARS);
            $result = (new RiskEngine)->run($last ? null : RiskEngine::fiscalYearEnd($year));
            $levels = collect($result['communities'])->countBy(fn ($c) => $c['multi']['level'] ?? 'missing')->all();
            ksort($levels);
            $this->line(sprintf('ปีงบ %d: เมือง %.1f (%s) · %s', $year, $result['city']['multi']['value'], $result['city']['multi']['level'], json_encode($levels, JSON_UNESCAPED_UNICODE)));
        }

        return self::SUCCESS;
    }

    /**
     * Delete every imported record, the import history and the kept files.
     */
    private function clear(): void
    {
        $disk = Storage::disk('local');
        DB::transaction(function () use ($disk) {
            foreach (ImportSheets::all() as $sheet) {
                if ($sheet instanceof TableSheet) {
                    $sheet->recordModel()::query()->delete();
                }
            }
            ImportReplacedRow::query()->delete();
            foreach (ImportBatch::whereNotNull('stored_path')->pluck('stored_path') as $path) {
                $disk->delete($path);
            }
            ImportBatch::query()->delete();
        });
        // Mass deletes skip model events, so drop the cached engine results here.
        EngineCache::flush();
        $this->info('ลบข้อมูลนำเข้าเดิมและประวัตินำเข้าแล้ว');
    }

    /**
     * The criteria-change demo: the existing set covers 2567 onward, a second set from 2569 weighs flood a little
     * lower and PM2.5 a little higher, and the elderly a little higher within the social group.
     */
    private function criteria(): void
    {
        $base = ScoringCriteriaSet::orderBy('start_fiscal_year')->firstOrFail();
        ScoringCriteriaSet::where('id', '!=', $base->id)->delete();
        $base->update(['start_fiscal_year' => 2567, 'is_locked' => false]);

        $weights = $base->weights;
        $weights['multi_hevc']['ALL']['FLOOD_HEVC'] = 0.30;
        $weights['multi_hevc']['ALL']['PM25_HEVC'] = 0.35;
        $weights['v_social']['ALL']['S_ELDERLY'] = 0.35;
        $weights['v_social']['ALL']['S_LOWINCOME'] = 0.25;
        ScoringCriteriaSet::create([
            'name' => 'เกณฑ์ปรับปรุง 2569 (สาธิต)',
            'start_fiscal_year' => 2569,
            'is_locked' => false,
            'weights' => $weights,
        ]);
        $this->info("เกณฑ์: \"{$base->name}\" เริ่มปีงบ 2567 · \"เกณฑ์ปรับปรุง 2569 (สาธิต)\" เริ่มปีงบ 2569");
    }
}
