<?php

namespace App\Scoring;

use App\Import\ImportSheets;
use App\Models\ScoringCriteriaSet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Which criteria set applies to which data (owner decision 2026-11-06, replacing "exactly one active set"):
 * - Each set has a required start ปีงบประมาณ (พ.ศ., Oct–Sep).
 * - A record uses the set with the latest start year ≤ the record's fiscal year, so newer data with no newer set
 *   uses the latest set. Data older than the earliest set uses the earliest set.
 * - A set that some imported record resolves to is "in use" and read-only. This is computed live from the data
 *   tables, so cancelling the only import of a year frees its set again.
 * - Adding a set or changing a start year that moves years with data to another set needs the admin's explicit
 *   confirmation (moves(); owner 2026-11-11, for a criteria change set up only after that year's data was
 *   imported). Scores are computed live, so the moved years just recompute; nothing is re-imported.
 */
class CriteriaSets
{
    /**
     * The fiscal year (พ.ศ.) of a date: October starts the next year, e.g. 2024-10-01 → 2568.
     */
    public static function fiscalYear(string|\DateTimeInterface $date): int
    {
        $d = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);

        return $d->year + 543 + ($d->month >= 10 ? 1 : 0);
    }

    /**
     * The set a fiscal year resolves to, or null if there are no sets.
     *
     * @param  Collection<int, ScoringCriteriaSet>  $sets
     */
    public static function resolve(int $fiscalYear, Collection $sets): ?ScoringCriteriaSet
    {
        $sorted = $sets->sortBy('start_fiscal_year')->values();

        return $sorted->last(fn ($set) => $set->start_fiscal_year <= $fiscalYear) ?? $sorted->first();
    }

    /**
     * Every fiscal year that has imported data, in any sheet, ascending.
     *
     * @return list<int>
     */
    public static function dataFiscalYears(): array
    {
        $years = [];
        foreach (ImportSheets::all() as $sheet) {
            foreach ($sheet->recordDates() as $date) {
                $years[self::fiscalYear($date)] = true;
            }
        }
        $years = array_keys($years);
        sort($years);

        return $years;
    }

    /**
     * Set id → the fiscal years with data that resolve to it.
     *
     * @param  Collection<int, ScoringCriteriaSet>  $sets
     * @param  list<int>|null  $dataYears  defaults to dataFiscalYears()
     * @return array<int, list<int>>
     */
    public static function usage(Collection $sets, ?array $dataYears = null): array
    {
        $usage = [];
        foreach ($dataYears ?? self::dataFiscalYears() as $year) {
            $set = self::resolve($year, $sets);
            if ($set) {
                $usage[$set->id][] = $year;
            }
        }

        return $usage;
    }

    public static function inUse(ScoringCriteriaSet $set): bool
    {
        return array_key_exists($set->id, self::usage(ScoringCriteriaSet::all()));
    }

    /**
     * The fiscal years with data that would resolve to a different set if $set got this start year (a new set
     * when it has no id). Each item: year, from (the set it uses now), to (the set it would use).
     *
     * @return list<array{year: int, from: string, to: string}>
     */
    public static function moves(ScoringCriteriaSet $set, int $startYear, ?string $name = null): array
    {
        $current = ScoringCriteriaSet::all();
        $proposed = $current->reject(fn ($s) => $set->exists && $s->id === $set->id)->values();
        $candidate = clone $set;
        $candidate->start_fiscal_year = $startYear;
        $candidate->name = $name ?? $set->name;
        $proposed->push($candidate);

        $moves = [];
        foreach (self::dataFiscalYears() as $year) {
            $before = self::resolve($year, $current);
            $after = self::resolve($year, $proposed);
            if ($before === null) {
                continue; // the very first set: nothing to move away from
            }
            $same = $after === $candidate ? ($set->exists && $before->id === $set->id) : $after->id === $before->id;
            if (! $same) {
                $moves[] = ['year' => $year, 'from' => $before->name, 'to' => $after->name];
            }
        }

        return $moves;
    }

    /**
     * The confirmation text for moves(): which years switch set, and that their numbers will change.
     *
     * @param  list<array{year: int, from: string, to: string}>  $moves
     */
    public static function movesMessage(array $moves): string
    {
        $lines = collect($moves)
            ->groupBy(fn ($m) => $m['from']."\0".$m['to'])
            ->map(fn ($group) => 'ปีงบ '.$group->pluck('year')->implode(', ')
                .' จะเปลี่ยนจากชุด "'.$group[0]['from'].'" ไปใช้ชุด "'.$group[0]['to'].'"')
            ->implode(' · ');

        return $lines.' ข้อมูลปีนั้นนำเข้าไว้แล้ว คะแนนความเสี่ยงที่เคยแสดงหรือรายงานไปจะเปลี่ยนตามเกณฑ์ใหม่ทุกหน้า';
    }
}
