<?php

namespace Database\Seeders;

use App\Models\Community;
use Illuminate\Database\Seeder;

class CommunitySeeder extends Seeder
{
    /**
     * The 32 ชุมชน of เทศบาลเมืองมหาสารคาม, using the client's own COMMUNITY_ID codes and names exactly as
     * they appear in data_input_mcr2030_v4.xlsx (no "ชุมชน" prefix — the UI adds it for display). Note the
     * client's R-numbering is not alphabetical and does not follow resources/js/data/mahasarakham.ts's id order.
     */
    private const COMMUNITIES = [
        'R001' => 'ศรีสวัสดิ์ 2',
        'R002' => 'ตักศิลา 2',
        'R003' => 'ธัญญา 4',
        'R004' => 'ศรีมหาสารคาม',
        'R005' => 'สามัคคี 2',
        'R006' => 'ธัญญา 1',
        'R007' => 'อุทัยทิศ 3',
        'R008' => 'ปัจฉิมทัศน์ 1',
        'R009' => 'นาควิชัย 1',
        'R010' => 'นาควิชัย 2',
        'R011' => 'เครือวัลย์ 1',
        'R012' => 'อุทัยทิศ 2',
        'R013' => 'โพธิ์ศรี 1',
        'R014' => 'โพธิ์ศรี 2',
        'R015' => 'อภิสิทธิ์ 2',
        'R016' => 'ปัจฉิมทัศน์ 2',
        'R017' => 'ธัญญา 2',
        'R018' => 'ส่องเหนือ',
        'R019' => 'ศรีสวัสดิ์ 1',
        'R020' => 'สามัคคี 1',
        'R021' => 'ส่องใต้',
        'R022' => 'นาควิชัย 3',
        'R023' => 'อุทัยทิศ 1',
        'R024' => 'ศรีสวัสดิ์ 3',
        'R025' => 'ธัญญา 3',
        'R026' => 'บ้านแมด',
        'R027' => 'มหาชัย',
        'R028' => 'อภิสิทธิ์ 1',
        'R029' => 'เครือวัลย์ 2',
        'R030' => 'อุทัยทิศ 4',
        'R031' => 'ตักศิลา 1',
        'R032' => 'เครือวัลย์ 3',
    ];

    /**
     * Seed the 32 communities. Idempotent — safe to re-run.
     */
    public function run(): void
    {
        foreach (self::COMMUNITIES as $code => $name) {
            Community::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
