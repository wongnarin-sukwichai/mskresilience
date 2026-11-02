<?php

namespace App\Import;

use App\Enums\WorkGroup;

/**
 * Example rows for the upload page's "ตัวอย่างข้อมูล" viewer (owner decision 2026-11-02). It is web-only and never
 * written into the template, so it can't be imported by accident. The rows are aligned with
 * ImportColumns::for(), and dates are Y-m-d strings.
 *
 * Values are the client's first three rows (R001–R003) in data_input_mcr2030_v4.xlsx, labelled as demo data in
 * the UI, with these changes:
 * - The client's placeholder values are replaced with plausible ones. In งานป้องกันภัย, the E_NUMERATOR_COUNT_*
 *   columns (the same 20/30 for every hazard) become people counts below POPULATION, and C6_3 (20/30/100)
 *   becomes 80/70/100. In งาน KPI, the 10s become counts no larger than the matching group in the welfare/health
 *   rows.
 * - Row 2 omits COMMUNITY_NAME (it's optional), and row 3 leaves one value blank, to show that blank means
 *   ไม่มีข้อมูล rather than 0.
 */
class ImportExamples
{
    /**
     * @return list<list<string|int|float|null>>
     */
    public static function for(WorkGroup $group): array
    {
        return match ($group) {
            WorkGroup::DisasterEvent => [
                ['EV0001', 'R001', 'ศรีสวัสดิ์ 2', '2026-05-18', '2026-05-20', 'FLOOD', 'STORM', 'ใช่',
                    'ฝนตกหนักต่อเนื่องร่วมกับพายุ ทำให้ระบบระบายน้ำล้นและน้ำเข้าบ้านเรือนพื้นที่ลุ่มต่ำ',
                    42, 2, 0, 3850000, 18, 1, 4, 4, 3, 3, 4, 1100000, 180000, 350000, 420000, 150000],
                ['EV0002', 'R002', null, '2026-03-04', '2026-03-04', 'FIRE', 'HEAT', 'ไม่ใช่',
                    'เกิดเพลิงไหม้บ้านพักอาศัยจากไฟฟ้าลัดวงจรในชุมชนที่มีทางเข้าออกค่อนข้างแคบ',
                    7, 3, 0, 1260000, 12, 2, 2, 2, 1, 2, 3, 605000, 95000, 180000, 250000, 80000],
                ['EV0003', 'R003', 'ธัญญา 4', '2026-04-22', '2026-04-23', 'STORM', 'FLOOD', 'ใช่',
                    'พายุฤดูร้อนและลมกระโชกแรงทำให้หลังคาเสียหาย ต้นไม้ล้ม และเกิดน้ำท่วมขังบางจุด',
                    25, 1, 0, 2100000, 10, 3, 5, 5, 4, 4, 5, 2770000, 420000, 850000, 1200000, null],
            ],
            WorkGroup::Disaster => [
                ['2025-01-20', 'R001', 'ศรีสวัสดิ์ 2', 'ตลาด', 'เมืองมหาสารคาม', 'มหาสารคาม', 1240, 460, 0.72,
                    310, 120, 450, 1240, 1240, 10.5, 1, 4, 5, 80, 4, 4, 4, 4, 4, 4, 3, 10, 4, 4, 4, 4, 3, 3, 4, 850000],
                ['2025-01-21', 'R002', null, 'ตลาด', 'เมืองมหาสารคาม', 'มหาสารคาม', 980, 365, 0.58,
                    150, 210, 330, 980, 980, 7.2, 1, 3, 5, 70, 2, 2, 2, 2, 2, 2, 2, 2, 3, 2, 2, 2, 1, 2, 3, 520000],
                ['2025-01-22', 'R003', 'ธัญญา 4', 'ตลาด', 'เมืองมหาสารคาม', 'มหาสารคาม', 1510, 560, 0.83,
                    620, 140, 500, 1510, 1510, 13.1, 1, 4, 5, 100, 4, 4, 4, 4, 4, 4, 4, 4, 5, 5, 5, 5, 4, 4, 5, null],
            ],
            WorkGroup::Health => [
                ['2025-01-20', 'R001', 'ศรีสวัสดิ์ 2', 8, 5, 14, 6, 86],
                ['2025-01-21', 'R002', null, 5, 3, 9, 4, 61],
                ['2025-01-22', 'R003', 'ธัญญา 4', 11, 7, 18, 8, null],
            ],
            WorkGroup::Welfare => [
                ['2025-01-20', 'R001', 'ศรีสวัสดิ์ 2', 186, 142, 31, 96],
                ['2025-01-21', 'R002', null, 132, 118, 22, 74],
                ['2025-01-22', 'R003', 'ธัญญา 4', 214, 165, 38, null],
            ],
            WorkGroup::Education => [
                ['2025-01-20', 'R001', 'ศรีสวัสดิ์ 2', 0],
                ['2025-01-21', 'R002', null, 0],
                ['2025-01-22', 'R003', 'ธัญญา 4', null],
            ],
            WorkGroup::Engineering => [
                ['2025-01-20', 'R001', 'ศรีสวัสดิ์ 2', 1, 1, 2, 2, 2, 2, 2, 2],
                ['2025-01-21', 'R002', null, 2, 1, 3, 3, 3, 3, 3, 3],
                ['2025-01-22', 'R003', 'ธัญญา 4', 1, 1, 2, 2, 2, 2, 2, null],
            ],
            WorkGroup::PublicRelations => [
                ['2025-01-20', 'R001', 'ศรีสวัสดิ์ 2', 1, 1],
                ['2025-01-21', 'R002', null, 2, 1],
                ['2025-01-22', 'R003', 'ธัญญา 4', 1, null],
            ],
            WorkGroup::Kpi => [
                ['2025-01-20', 'R001', 'ศรีสวัสดิ์ 2', 6, 40, 120, 25, 90, 4, 6, 10],
                ['2025-01-21', 'R002', null, 4, 30, 95, 18, 70, 2, 4, 7],
                ['2025-01-22', 'R003', 'ธัญญา 4', 9, 55, 150, 30, 110, 6, 9, null],
            ],
        };
    }
}
