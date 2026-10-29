<?php

namespace App\Scoring;

/**
 * The fixed structure of a เกณฑ์กลาง (central scoring criteria) set — which weights exist, where they sit in the
 * HEVC formulas, and their default values. Source: the client's ค่าถ่วงน้ำหนัก.docx + สมการการคำนวณ_hevc_v4.docx
 * (public/file/271069). Product decision (2026-10-29): admins may edit the NUMBERS only; adding or removing a
 * variable is a code change here (plus an import-column change), never something the UI can do. The ×20
 * multiplier, C_HEVC = 1 + 0.8·C_RAW, raw→0–5 bands and risk-level bands are deliberately not part of this
 * schema — they're fixed in code.
 *
 * A set's weights are stored as JSON shaped section → row → column → value, e.g.
 * weights['v_hazard']['FLOOD']['V_SOCIAL'] = 0.20. "list" sections have a single row keyed 'ALL'. A cell that
 * is null in the schema is absent from that row's formula (rendered "–", not editable, not stored).
 */
class CriteriaSchema
{
    public const HAZARDS = [
        'FLOOD' => 'อุทกภัย',
        'FIRE' => 'อัคคีภัย',
        'STORM' => 'วาตภัย',
        'HEAT' => 'คลื่นความร้อน',
        'PM25' => 'PM2.5',
    ];

    /** Row sums must equal 1 within this tolerance (weights carry at most 4 decimals). */
    private const SUM_TOLERANCE = 0.00005;

    /**
     * Tabs → sections → columns/rows. Rows carry their default cell values.
     *
     * @return list<array<string, mixed>>
     */
    public static function tabs(): array
    {
        return [
            [
                'key' => 'multi',
                'label' => 'ภัยรวม',
                'sections' => [
                    self::list('multi_hevc', 'ค่าถ่วงน้ำหนักภัยรวม (MULTI_HEVC)', 'สัดส่วนของ HEVC แต่ละภัยในค่าความเสี่ยงรวมทุกภัย', [
                        ['FLOOD_HEVC', 'อุทกภัย', 0.35],
                        ['FIRE_HEVC', 'อัคคีภัย', 0.10],
                        ['STORM_HEVC', 'วาตภัย', 0.15],
                        ['HEAT_HEVC', 'คลื่นความร้อน', 0.10],
                        ['PM25_HEVC', 'PM2.5', 0.30],
                    ]),
                ],
            ],
            [
                'key' => 'hazard',
                'label' => 'รายภัย',
                'sections' => [
                    self::list('hazard_ev', 'สัดส่วน E และ V (ใช้กับทุกภัย)', 'HEVC = 20 × (E·wE + V·wV) × (1 − c·C_HEVC)', [
                        ['E', 'E — การเปิดรับภัย', 0.3846],
                        ['V', 'V — ความเปราะบาง', 0.6154],
                    ]),
                    self::list('hazard_c', 'ตัวคูณศักยภาพ (ใช้กับทุกภัย)', 'ค่า c ในวงเล็บ (1 − c·C_HEVC) — เป็นตัวคูณ ไม่ต้องรวมกับ E/V ให้ได้ 1', [
                        ['C', 'c — ตัวคูณศักยภาพ', 0.16],
                    ], sum: false, warnAbove: 0.2),
                ],
            ],
            [
                'key' => 'v_group',
                'label' => 'V รายกลุ่ม',
                'sections' => [
                    self::list('v_social', 'V_SOCIAL — กลุ่มเปราะบางทางสังคม', null, [
                        ['S_ELDERLY', 'ผู้สูงอายุ', 0.30],
                        ['S_CHILD', 'เด็ก', 0.20],
                        ['S_DISABLED', 'ผู้พิการ', 0.20],
                        ['S_LOWINCOME', 'ผู้มีรายได้น้อย', 0.30],
                    ]),
                    self::list('v_health', 'V_HEALTH — กลุ่มเปราะบางด้านสุขภาพ', null, [
                        ['S_BEDRIDDEN', 'ผู้ป่วยติดเตียง', 0.25],
                        ['S_MEDICAL', 'ผู้พึ่งพาการรักษาทางการแพทย์', 0.30],
                        ['S_NCD', 'ผู้ป่วยโรคไม่ติดต่อเรื้อรัง (NCDs)', 0.45],
                    ]),
                    self::list('v_assist', 'V_ASSIST — กลุ่มที่ต้องได้รับความช่วยเหลือ', null, [
                        ['S_ASSIST_EVAC', 'ผู้ต้องได้รับความช่วยเหลือในการอพยพ', 0.60],
                        ['S_COMM_ASSIST', 'COMM_ASSIST', 0.40],
                    ]),
                    self::list('v_warning', 'V_WARNING — การเข้าถึงการเตือนภัย', 'มีตัวแปรเดียว จึงมีน้ำหนัก 1 เสมอ', [
                        ['V_WARNING_ACCESS_GAP_SCORE', 'V_WARNING_ACCESS_GAP_SCORE', 1],
                    ], fixed: true),
                ],
            ],
            [
                'key' => 'v_hazard',
                'label' => 'V รายภัย',
                'sections' => [
                    self::matrix('v_hazard', 'ค่าถ่วงน้ำหนักความเปราะบางรายภัย', 'แต่ละแถวคือสูตร V ของภัยนั้น', [
                        ['V_SOCIAL', 'V_SOCIAL'],
                        ['V_HEALTH', 'V_HEALTH'],
                        ['V_ASSIST', 'V_ASSIST'],
                        ['V_WARNING', 'V_WARNING'],
                        ['V_INFRA', 'V_INFRA (รายภัย)'],
                    ], [
                        'FLOOD' => [0.20, 0.15, 0.25, 0.15, 0.25],
                        'FIRE' => [0.15, 0.15, 0.30, 0.15, 0.25],
                        'STORM' => [0.20, 0.15, 0.25, 0.15, 0.25],
                        'HEAT' => [0.25, 0.40, 0.10, 0.10, 0.15],
                        'PM25' => [0.25, 0.45, 0.05, 0.15, 0.10],
                    ]),
                ],
            ],
            [
                'key' => 'c_group',
                'label' => 'C รายกลุ่ม',
                'sections' => [
                    self::list('c4', 'C4 — คนและการสื่อสาร', 'ใช้ค่าเดียวกันทุกภัย', [
                        ['C4_1', 'อาสาสมัครต่อประชากร 1,000 คน', 0.25],
                        ['C4_2', 'จำนวนครั้งการฝึกอบรม', 0.20],
                        ['C4_3', 'การสื่อสารความเสี่ยง', 0.35],
                        ['C4_4', 'การศึกษาเพื่อลดความเสี่ยงในโรงเรียน', 0.20],
                    ]),
                    self::list('c5', 'C5 — พื้นที่สีเขียว–ฟ้า / NbS', 'ใช้ค่าเดียวกันทุกภัย', [
                        ['C5_1', 'พื้นที่สีเขียว–ฟ้า', 0.35],
                        ['C5_2', 'มาตรการที่ใช้ธรรมชาติเป็นฐาน (NbS)', 0.35],
                        ['C5_3', 'การบำรุงรักษา', 0.30],
                    ]),
                    self::list('c8', 'C8 — เครือข่ายและการตอบสนอง', 'ใช้ค่าเดียวกันทุกภัย', [
                        ['C8_1', 'เครือข่ายที่เป็นทางการ', 0.20],
                        ['C8_2', 'ระยะเวลาตอบสนอง', 0.30],
                        ['C8_3', 'ความหลากหลายของเครือข่าย', 0.20],
                        ['C8_4', 'ประสบการณ์ปฏิบัติการร่วม', 0.30],
                    ]),
                ],
            ],
            [
                'key' => 'c6c7',
                'label' => 'C6/C7 รายภัย',
                'sections' => [
                    self::matrix('c6', 'C6 — อุปกรณ์และทรัพยากร (แยกตามภัย)', null, [
                        ['C6_1', 'C6_1 ยานพาหนะ'],
                        ['C6_2', 'C6_2 อุปกรณ์ช่วยชีวิต'],
                        ['C6_3', 'C6_3 ความพร้อมใช้งาน'],
                        ['C6_4', 'C6_4 อุปกรณ์เฉพาะภัย'],
                    ], [
                        'FLOOD' => [0.25, 0.25, 0.20, 0.30],
                        'FIRE' => [0.25, 0.20, 0.20, 0.35],
                        'STORM' => [0.25, 0.20, 0.25, 0.30],
                        'HEAT' => [null, 0.25, 0.25, 0.50],
                        'PM25' => [null, 0.20, 0.20, 0.60],
                    ]),
                    self::matrix('c7', 'C7 — อพยพและพื้นที่ปลอดภัย (แยกตามภัย)', 'C7_1 คือคะแนนเฉพาะภัย (C7_1_FLOOD / C7_1_FIRE / C7_1_STORM)', [
                        ['C7_1', 'C7_1 เฉพาะภัย'],
                        ['C7_2', 'C7_2 พื้นที่ปลอดภัย'],
                        ['C7_3', 'C7_3 การฝึกซ้อม'],
                    ], [
                        'FLOOD' => [0.40, 0.30, 0.30],
                        'FIRE' => [0.40, 0.30, 0.30],
                        'STORM' => [0.40, 0.30, 0.30],
                        'HEAT' => [null, 0.60, 0.40],
                        'PM25' => [null, 0.60, 0.40],
                    ]),
                ],
            ],
            [
                'key' => 'c_hazard',
                'label' => 'C รวมรายภัย',
                'sections' => [
                    self::matrix('c_hazard', 'ค่าถ่วงน้ำหนักศักยภาพรวมรายภัย', 'ผลลัพธ์คือ C_RAW ของแต่ละภัย ก่อนแปลงเป็น C_HEVC = 1 + 0.8·C_RAW', [
                        ['C4', 'C4 คน/สื่อสาร'],
                        ['C5', 'C5 NbS'],
                        ['C6', 'C6 อุปกรณ์'],
                        ['C7', 'C7 อพยพ'],
                        ['C8', 'C8 เครือข่าย'],
                    ], [
                        'FLOOD' => [0.20, 0.20, 0.20, 0.25, 0.15],
                        'FIRE' => [0.20, null, 0.30, 0.30, 0.20],
                        'STORM' => [0.20, null, 0.25, 0.35, 0.20],
                        'HEAT' => [0.25, 0.30, 0.10, 0.20, 0.15],
                        'PM25' => [0.30, null, 0.15, 0.25, 0.30],
                    ]),
                ],
            ],
            [
                'key' => 'recovery',
                'label' => 'Recovery',
                'sections' => [
                    self::list('recovery', 'ความพร้อมฟื้นฟู (RECOVERY_AVG)', null, [
                        ['R1', 'R1 แผนฟื้นฟู', 0.25],
                        ['R2', 'R2 สร้างใหม่ให้ดีกว่าเดิม', 0.25],
                        ['R3', 'R3 ฟื้นฟูเศรษฐกิจและสังคม', 0.20],
                        ['R4', 'R4 ฟื้นฟูกลุ่มเปราะบาง', 0.15],
                        ['R5', 'R5 การเรียนรู้และปรับปรุงระบบ', 0.15],
                    ]),
                ],
            ],
        ];
    }

    /**
     * Flat list of every section (across all tabs).
     *
     * @return list<array<string, mixed>>
     */
    public static function sections(): array
    {
        return array_merge(...array_map(fn ($tab) => $tab['sections'], self::tabs()));
    }

    /**
     * The client's default weights (seed values), shaped section → row → column → value.
     *
     * @return array<string, array<string, array<string, float>>>
     */
    public static function defaults(): array
    {
        $weights = [];

        foreach (self::sections() as $section) {
            foreach ($section['rows'] as $row) {
                $weights[$section['key']][$row['key']] = array_filter($row['cells'], fn ($v) => $v !== null);
            }
        }

        return $weights;
    }

    /**
     * Keep only the cells the schema defines, cast to float, and force fixed rows to their schema value. Assumes
     * the input already passed validate().
     *
     * @param  array<string, mixed>  $weights
     * @return array<string, array<string, array<string, float>>>
     */
    public static function normalize(array $weights): array
    {
        $normalized = [];

        foreach (self::sections() as $section) {
            foreach ($section['rows'] as $row) {
                foreach ($row['cells'] as $col => $default) {
                    if ($default === null) {
                        continue;
                    }
                    $normalized[$section['key']][$row['key']][$col] = $section['fixed']
                        ? (float) $default
                        : round((float) $weights[$section['key']][$row['key']][$col], 4);
                }
            }
        }

        return $normalized;
    }

    /**
     * Validate submitted weights against the schema. Returns Laravel-style errors keyed by dot path:
     * per cell (weights.section.row.col) for a bad number, per row (weights.section.row) for a bad sum.
     *
     * @param  array<string, mixed>  $weights
     * @return array<string, string>
     */
    public static function validate(array $weights): array
    {
        $errors = [];

        foreach (self::sections() as $section) {
            if ($section['fixed']) {
                continue;
            }

            foreach ($section['rows'] as $row) {
                $path = "weights.{$section['key']}.{$row['key']}";
                $sum = 0.0;
                $rowValid = true;

                foreach ($row['cells'] as $col => $default) {
                    if ($default === null) {
                        continue;
                    }

                    $value = $weights[$section['key']][$row['key']][$col] ?? null;

                    if (! is_numeric($value)) {
                        $errors["{$path}.{$col}"] = 'กรุณากรอกตัวเลข';
                        $rowValid = false;

                        continue;
                    }

                    $value = (float) $value;

                    if ($value < 0 || $value > 1) {
                        $errors["{$path}.{$col}"] = 'ค่าต้องอยู่ระหว่าง 0 ถึง 1';
                        $rowValid = false;
                    } elseif (abs($value * 10000 - round($value * 10000)) > 1e-6) {
                        $errors["{$path}.{$col}"] = 'ทศนิยมได้ไม่เกิน 4 ตำแหน่ง';
                        $rowValid = false;
                    }

                    $sum += $value;
                }

                if ($rowValid && $section['sum'] && abs($sum - 1) > self::SUM_TOLERANCE) {
                    $errors[$path] = 'ผลรวมต้องเท่ากับ 1.00 (ปัจจุบัน '.number_format($sum, 4).')';
                }
            }
        }

        return $errors;
    }

    /**
     * A single-row section rendered as a vertical list of items.
     *
     * @param  list<array{0: string, 1: string, 2: float|int}>  $items  [column key, label, default]
     * @return array<string, mixed>
     */
    private static function list(string $key, string $title, ?string $description, array $items, bool $sum = true, bool $fixed = false, ?float $warnAbove = null): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'layout' => 'list',
            'sum' => $sum,
            'fixed' => $fixed,
            'warnAbove' => $warnAbove,
            'columns' => array_map(fn ($item) => ['key' => $item[0], 'label' => $item[1]], $items),
            'rows' => [[
                'key' => 'ALL',
                'label' => $title,
                'cells' => array_combine(array_column($items, 0), array_map(fn ($item) => (float) $item[2], $items)),
            ]],
        ];
    }

    /**
     * A hazard × component section — one row per hazard; a null default means "not in this hazard's formula".
     *
     * @param  list<array{0: string, 1: string}>  $columns  [column key, label]
     * @param  array<string, list<float|null>>  $rows  hazard key → defaults in column order
     * @return array<string, mixed>
     */
    private static function matrix(string $key, string $title, ?string $description, array $columns, array $rows): array
    {
        $columnKeys = array_column($columns, 0);

        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'layout' => 'matrix',
            'sum' => true,
            'fixed' => false,
            'warnAbove' => null,
            'columns' => array_map(fn ($col) => ['key' => $col[0], 'label' => $col[1]], $columns),
            'rows' => array_map(fn ($hazard) => [
                'key' => $hazard,
                'label' => self::HAZARDS[$hazard],
                'cells' => array_combine($columnKeys, array_map(fn ($v) => $v === null ? null : (float) $v, $rows[$hazard])),
            ], array_keys($rows)),
        ];
    }
}
