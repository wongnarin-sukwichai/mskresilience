<?php

namespace App\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A plain, readable .xlsx for the "ส่งออก Excel" buttons (TOR 4.4-4): a title line, a "where it came from" line,
 * a bold header row, frozen panes and auto widths. Numbers stay numbers so they can be summed in Excel.
 */
class XlsxExport
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    public static function download(string $filename, string $title, string $note, array $headers, array $rows): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('#[\\\\/?*\[\]:]#u', ' ', $title), 0, 31));
        $book->getDefaultStyle()->getFont()->setName('Tahoma')->setSize(10);

        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->setCellValue('A2', $note.' · ส่งออกเมื่อ '.now()->format('d/m/').(now()->year + 543).now()->format(' H:i').' น.');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');

        $sheet->fromArray([$headers, ...$rows], null, 'A4', true);
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $headerStyle = $sheet->getStyle("A4:{$last}4");
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
        $sheet->freezePane('A5');
        for ($i = 1; $i <= count($headers); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return response()->streamDownload(
            fn () => (new Xlsx($book))->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
