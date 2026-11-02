<?php

namespace App\Import;

use App\Enums\WorkGroup;
use App\Models\Community;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Builds the downloadable import template for one กลุ่มงาน (owner decisions 2026-10-29 / 2026-11-02):
 * - Sheet 1 is the data sheet: headers only (a sample row was tried and dropped 2026-11-02), each with a hover
 *   comment from ColumnDescriptions. COMMUNITY_ID has a dropdown of the codes in `communities`, so staff pick a
 *   code rather than type one.
 * - Sheet 2 "รายชื่อชุมชน" lists code → name for reference. The importer only ever reads the first sheet.
 */
class TemplateBuilder
{
    public const REFERENCE_SHEET = 'รายชื่อชุมชน';

    /** How many data rows get the dropdown/date formatting. */
    public const DATA_ROWS = 500;

    public static function build(WorkGroup $group): Spreadsheet
    {
        $book = new Spreadsheet;
        $columns = ImportColumns::for($group);
        $communities = Community::orderBy('code')->get(['code', 'name']);
        $lastDataRow = self::DATA_ROWS + 1;

        $sheet = $book->getActiveSheet()->setTitle($group->sheetName());
        $sheet->fromArray($columns, null, 'A1');
        $lastCol = Coordinate::stringFromColumnIndex(count($columns));

        // Header row: no fill, so the cell gridlines stay visible (owner's choice); bold Arial 8.
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setName('Arial')->setSize(8)->setBold(true);
        $sheet->freezePane('A2');

        foreach ($columns as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($col)->setWidth(max(12, (int) ceil(mb_strlen($header) * 0.8) + 3));

            if ($description = ColumnDescriptions::ALL[$header] ?? null) {
                $comment = $sheet->getComment("{$col}1");
                $comment->getText()->createTextRun($header)->getFont()->setBold(true);
                $comment->getText()->createText("\n".$description);
                $comment->setWidth('260pt')->setHeight('90pt');
            }

            if (in_array($header, ImportColumns::DATE_COLUMNS, true)) {
                $sheet->getStyle("{$col}2:{$col}{$lastDataRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }

            if ($header === ImportColumns::COMMUNITY_ID && $communities->isNotEmpty()) {
                $validation = $sheet->getDataValidation("{$col}2:{$col}{$lastDataRow}");
                $validation->setType(DataValidation::TYPE_LIST)
                    ->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
                    ->setShowErrorMessage(true)
                    ->setErrorTitle('รหัสชุมชนไม่ถูกต้อง')
                    ->setError('กรุณาเลือกรหัสชุมชนจากรายการ (ดูชีต "'.self::REFERENCE_SHEET.'")')
                    ->setFormula1("'".self::REFERENCE_SHEET."'!\$A\$2:\$A\$".($communities->count() + 1));
            }
        }

        $ref = $book->createSheet()->setTitle(self::REFERENCE_SHEET);
        $ref->fromArray(['COMMUNITY_ID', 'ชื่อชุมชน'], null, 'A1');
        $ref->fromArray($communities->map(fn ($c) => [$c->code, $c->name])->all(), null, 'A2');
        $ref->getStyle('A1:B1')->getFont()->setBold(true);
        $ref->getColumnDimension('A')->setWidth(16);
        $ref->getColumnDimension('B')->setWidth(28);

        $book->setActiveSheetIndex(0);

        return $book;
    }
}
