<?php

namespace App\Scoring;

use App\Enums\WorkGroup;
use App\Import\ImportColumns;
use App\Models\ImportBatch;

/**
 * Which imports a RiskEngine run actually read, for the admin-only ⓘ on calculated cards (owner 2026-11-26: show
 * the formula, the values and the file each number came from, so it can be checked). Per sheet: its columns (so a
 * card can name the sheet a column comes from) and the import batches whose rows were used, with how many
 * communities each one supplied. Only file names, dates and importers leave the server, never row values.
 */
class CalcSources
{
    /**
     * @param  array{communities: list<array<string, mixed>>}  $result  a RiskEngine::run() result
     * @return array<string, array{label: string, columns: list<string>, batches: list<array<string, mixed>>}>
     */
    public static function forRun(array $result): array
    {
        $sheets = [];
        foreach (RiskEngine::SHEETS as $key => [$model, $label]) {
            // community_id → the assessment date this run used from this sheet.
            $used = [];
            foreach ($result['communities'] as $c) {
                if (isset($c['sources'][$key])) {
                    $used[$c['id']] = $c['sources'][$key];
                }
            }

            $perBatch = [];
            if ($used) {
                $rows = $model::query()->whereIn('community_id', array_keys($used))
                    ->get(['community_id', 'assessment_date', 'import_batch_id']);
                foreach ($rows as $row) {
                    if ($row->assessment_date->toDateString() === $used[$row->community_id]) {
                        $perBatch[$row->import_batch_id ?? 0] = ($perBatch[$row->import_batch_id ?? 0] ?? 0) + 1;
                    }
                }
            }

            $batches = ImportBatch::with('user:id,name')->whereIn('id', array_keys($perBatch))->orderBy('id')->get()
                ->map(fn (ImportBatch $b) => [
                    'id' => $b->id,
                    'file' => $b->original_filename,
                    'source' => $b->source,
                    'at' => $b->created_at->toIso8601String(),
                    'user' => $b->user?->name,
                    'communities' => $perBatch[$b->id],
                ])->values()->all();

            $sheets[$key] = [
                'label' => $label,
                'columns' => array_map('strtolower', ImportColumns::for(WorkGroup::from($key))),
                'batches' => $batches,
            ];
        }

        return $sheets;
    }
}
