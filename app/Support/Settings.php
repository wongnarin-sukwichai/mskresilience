<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * System settings an admin changes on ตั้งค่าทั่วไป (/system-settings). The single source of truth for the keys,
 * their labels and defaults: a key with no row in app_settings uses its default. Later phases add their keys here
 * (approval mode, Policy Flag thresholds, AI mode).
 */
class Settings
{
    public const DEFAULT_MOCK_NOTICE = 'ข้อมูลที่แสดงเป็นข้อมูลสมมุติเพื่อทดสอบระบบ ไม่ใช่ข้อมูลจริงของชุมชน';

    /**
     * key → [group, type, default, label, help, (min, max, step, unit) for numbers, options for selects]. Types the
     * page can edit: bool, text, number, select. `group` is the heading the setting sits under.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $flag = fn (string $label, float $default, string $help, float $min, float $max, float $step, string $unit) => [
            'group' => 'เกณฑ์ Policy Flag (หน้าแดชบอร์ด)', 'type' => 'number', 'default' => $default, 'label' => $label,
            'help' => $help, 'min' => $min, 'max' => $max, 'step' => $step, 'unit' => $unit,
        ];

        return [
            'show_tor_refs' => [
                'group' => 'การแสดงผล',
                'type' => 'bool',
                'default' => true,
                'label' => 'แสดงเลขอ้างอิง TOR',
                'help' => 'แสดงป้าย "TOR …" ท้ายหัวข้อในหน้าต่าง ๆ ว่าตอบข้อกำหนดข้อใด เปิดไว้ช่วงนำเสนอและตรวจรับ แล้วปิดเมื่อใช้งานจริง',
            ],
            'import_requires_approval' => [
                'group' => 'การนำเข้าข้อมูล',
                'type' => 'bool',
                'default' => false,
                'label' => 'ต้องให้ผู้ดูแลระบบอนุมัติก่อนข้อมูลเข้าระบบ',
                'help' => 'เมื่อเปิด ไฟล์ที่เจ้าหน้าที่นำเข้าและการแก้ไขข้อมูลในเว็บจะรอให้ผู้ดูแลระบบอนุมัติที่เมนู "อนุมัติข้อมูล" ก่อน (TOR 4.1.1(6)-1) เมื่อปิด ข้อมูลเข้าระบบทันทีหลังเจ้าหน้าที่ยืนยัน ผู้ดูแลระบบไม่ต้องรออนุมัติ',
            ],
            'welcome_mock_notice' => [
                'group' => 'การแสดงผล',
                'type' => 'bool',
                'default' => true,
                'label' => 'แสดงประกาศข้อมูลสมมุติที่หน้าแรก',
                'help' => 'แสดงแถบแจ้งบนหน้าแรกสาธารณะว่าข้อมูลที่เห็นเป็นข้อมูลสมมุติ ปิดเมื่อนำข้อมูลจริงเข้าระบบแล้ว',
            ],
            'welcome_mock_notice_text' => [
                'group' => 'การแสดงผล',
                'type' => 'text',
                'default' => self::DEFAULT_MOCK_NOTICE,
                'label' => 'ข้อความประกาศ',
                'help' => 'ข้อความที่แสดงในแถบประกาศหน้าแรก',
            ],

            // Policy Flags (TOR 4.1.1(3)-3, 4.1.2; owner 2026-11-23 option ค: admin sets them, relative by default).
            'flag_mode' => [
                'group' => 'เกณฑ์ Policy Flag (หน้าแดชบอร์ด)',
                'type' => 'select',
                'default' => 'relative',
                'label' => 'วิธีตัดสินว่า "สูง" หรือ "ต่ำ"',
                'help' => 'เทียบกันเองในเมือง: ใช้กลุ่มบนสุด/ล่างสุดตามร้อยละที่ตั้งไว้ จึงมีชุมชนที่ต้องเฝ้าระวังเสมอ แม้ทั้งเมืองเสี่ยงต่ำ · เกณฑ์ตายตัว: ใช้ค่าคงที่ด้านล่าง',
                'options' => ['relative' => 'เทียบกันเองในเมือง (ค่าเริ่มต้น)', 'fixed' => 'เกณฑ์ตายตัว'],
            ],
            'flag_percent' => $flag('ร้อยละของชุมชนที่นับว่า "สูง/ต่ำ" (เมื่อเทียบกันเอง)', 25, 'เช่น 25 = ความเสี่ยงอยู่ใน 25% บนสุด หรืองบ/การฟื้นฟูอยู่ใน 25% ล่างสุดของเมือง', 5, 50, 5, '%'),
            'flag_hevc' => $flag('HEVC ที่นับว่า "สูง" (เกณฑ์ตายตัว)', 50, '50 = ระดับค่อนข้างสูงขึ้นไป', 0, 100, 1, 'คะแนน'),
            'flag_budget_per_person' => $flag('งบต่อประชากรที่นับว่า "ต่ำ" (เกณฑ์ตายตัว)', 500, 'งบด้านภัยเฉลี่ย 3 ปีหารด้วยประชากร ต่ำกว่านี้ = งบต่ำ', 0, 100000, 50, 'บาท/คน'),
            'flag_recovery' => $flag('ความพร้อมฟื้นฟูที่นับว่า "ต่ำ" (เกณฑ์ตายตัว)', 2.5, 'ค่าเฉลี่ย R1–R5 (0–5) ต่ำกว่านี้ = ฟื้นฟูต่ำ', 0, 5, 0.1, '/5'),
            'flag_consecutive_years' => $flag('จำนวนปีที่นับว่า "เสี่ยงสูงต่อเนื่อง"', 2, 'ระดับค่อนข้างสูงขึ้นไปติดกันอย่างน้อยกี่ปีงบ (ใช้ระดับความเสี่ยงจริง ไม่เทียบกันเอง)', 2, 10, 1, 'ปี'),
            'flag_rise' => $flag('คะแนนที่นับว่า "เสี่ยงเพิ่มขึ้นมาก"', 5, 'HEVC ความเสี่ยงรวมเพิ่มขึ้นจากปีงบก่อนหน้าตั้งแต่กี่คะแนน', 1, 50, 1, 'คะแนน'),

            // Executive Summary (TOR 4.1.1(3)-4; owner 2026-11-23): rule-based by default, AI optional.
            'summary_mode' => [
                'group' => 'สรุปภาพรวม (Executive Summary)',
                'type' => 'select',
                'default' => 'rule',
                'label' => 'วิธีสร้างข้อความสรุป',
                'help' => 'แบบกฎ: สร้างจากเงื่อนไขที่กำหนดไว้ ไม่มีค่าใช้จ่าย · AI: ให้ Claude เรียบเรียงจากตัวเลขชุดเดียวกัน (ต้องตั้งค่า ANTHROPIC_API_KEY บนเซิร์ฟเวอร์ มีค่าใช้จ่ายต่อครั้ง ดูรายละเอียดที่ปุ่ม ⓘ บนหน้าแดชบอร์ด) ถ้า AI ใช้ไม่ได้ ระบบใช้แบบกฎแทนอัตโนมัติ',
                'options' => ['rule' => 'แบบกฎ (ค่าเริ่มต้น)', 'ai' => 'AI (Claude)'],
            ],
        ];
    }

    public static function get(string $key): mixed
    {
        $definition = self::definitions()[$key] ?? throw new \InvalidArgumentException("Unknown setting {$key}");
        $loaded = self::loaded();

        return array_key_exists($key, $loaded) ? $loaded[$key] : $definition['default'];
    }

    public static function set(string $key, mixed $value): void
    {
        if (! array_key_exists($key, self::definitions())) {
            throw new \InvalidArgumentException("Unknown setting {$key}");
        }
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        app()->forgetInstance('settings.loaded');
    }

    /**
     * Every setting with its current value, for the settings page.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $definition) {
            $out[] = [
                ...array_intersect_key($definition, array_flip(['group', 'type', 'label', 'help', 'default', 'min', 'max', 'step', 'unit', 'options'])),
                'key' => $key,
                'value' => self::get($key),
            ];
        }

        return $out;
    }

    /**
     * The stored rows, read once per request. Kept on the container rather than a static, so each request (and
     * each test) starts fresh.
     *
     * @return array<string, mixed>
     */
    private static function loaded(): array
    {
        if (! app()->bound('settings.loaded')) {
            app()->instance('settings.loaded', AppSetting::query()->pluck('value', 'key')->all());
        }

        return app('settings.loaded');
    }
}
