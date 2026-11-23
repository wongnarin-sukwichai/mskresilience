<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Models\AiSummary;
use App\Models\AppSetting;
use App\Models\Backup;
use App\Models\Community;
use App\Models\ImportBatch;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns an activity_log row into what บันทึกการแก้ไข (/audit-log) shows: who, when, what kind of record, a Thai
 * description, and the changed fields with old → new values. Passwords are never in the log (User only logs
 * name/email/role/work_groups), so nothing here has to hide them.
 */
class AuditPresenter
{
    /** log_name → Thai label, also the filter options. */
    public const LOGS = [
        'users' => 'ผู้ใช้งาน',
        'communities' => 'ชุมชน',
        'criteria' => 'เกณฑ์คะแนน',
        'settings' => 'การตั้งค่า',
        'imports' => 'การนำเข้าและแก้ไขข้อมูล',
        'backups' => 'การสำรองข้อมูล',
    ];

    private const EVENTS = [
        'created' => 'เพิ่ม',
        'updated' => 'แก้ไข',
        'deleted' => 'ลบ',
        'password' => 'ตั้งรหัสผ่าน',
        'imported' => 'นำเข้า',
        'cancelled' => 'ยกเลิกการนำเข้า',
        'file_deleted' => 'ลบไฟล์',
        'web_add' => 'เพิ่มในเว็บ',
        'web_edit' => 'แก้ไขในเว็บ',
        'web_delete' => 'ลบในเว็บ',
        'submitted' => 'ส่งรออนุมัติ',
        'approved' => 'อนุมัติ',
        'rejected' => 'ไม่อนุมัติ',
        'withdrawn' => 'ถอนคำขอ',
        'backup_created' => 'สำรองข้อมูล',
        'backup_failed' => 'สำรองไม่สำเร็จ',
        'backup_downloaded' => 'ดาวน์โหลดไฟล์สำรอง',
        'ai_summary' => 'สร้างสรุปด้วย AI',
    ];

    private const FIELDS = [
        'name' => 'ชื่อ',
        'email' => 'อีเมล',
        'role' => 'สิทธิ์การใช้งาน',
        'work_groups' => 'กลุ่มงานที่นำเข้าได้',
        'code' => 'รหัสชุมชน',
        'start_fiscal_year' => 'ปีงบประมาณที่เริ่มใช้',
        'is_locked' => 'ล็อก',
        'key' => 'รายการตั้งค่า',
        'value' => 'ค่า',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function row(Activity $activity): array
    {
        $properties = $activity->properties?->toArray() ?? [];
        $new = $properties['attributes'] ?? [];
        $old = $properties['old'] ?? [];

        return [
            'id' => $activity->id,
            'at' => $activity->created_at?->toIso8601String(),
            'user' => $activity->causer?->name ?? ($activity->causer_id ? "ผู้ใช้ #{$activity->causer_id} (ถูกลบแล้ว)" : 'ระบบ'),
            'log' => $activity->log_name,
            'logLabel' => self::LOGS[$activity->log_name] ?? $activity->log_name,
            'event' => $activity->event,
            'eventLabel' => self::EVENTS[$activity->event] ?? $activity->event,
            'subject' => self::subjectLabel($activity, $new + $old),
            'description' => self::description($activity, $new + $old),
            'changes' => self::changes($activity, $old, $new),
            'details' => $properties['details'] ?? null,
        ];
    }

    /**
     * A Thai sentence. Model events get "แก้ไขผู้ใช้งาน: ชื่อ"; explicit logs (imports, password) keep their own text.
     *
     * @param  array<string, mixed>  $values
     */
    private static function description(Activity $activity, array $values): string
    {
        if (! in_array($activity->description, ['created', 'updated', 'deleted'], true)) {
            return $activity->description;
        }

        return (self::EVENTS[$activity->event] ?? $activity->event).(self::LOGS[$activity->log_name] ?? '').': '.self::subjectLabel($activity, $values);
    }

    /**
     * @param  array<string, mixed>  $values  the logged attributes (still there when the record is deleted)
     */
    private static function subjectLabel(Activity $activity, array $values): string
    {
        $subject = $activity->subject;

        return match ($activity->subject_type) {
            User::class => $values['name'] ?? $subject?->name ?? "#{$activity->subject_id}",
            Community::class => trim(($values['code'] ?? $subject?->code ?? '').' '.($values['name'] ?? $subject?->name ?? '')),
            ScoringCriteriaSet::class => $values['name'] ?? $subject?->name ?? "#{$activity->subject_id}",
            AppSetting::class => self::settingLabel($values['key'] ?? $subject?->key),
            ImportBatch::class, Backup::class, AiSummary::class => "#{$activity->subject_id}",
            default => (string) $activity->subject_id,
        };
    }

    private static function settingLabel(?string $key): string
    {
        return $key === null ? '–' : (Settings::definitions()[$key]['label'] ?? $key);
    }

    /**
     * Changed fields, old → new. Nested arrays (criteria weights) are compared leaf by leaf, so a weights edit
     * shows only the numbers that changed.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private static function changes(Activity $activity, array $old, array $new): array
    {
        $out = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            if ($field === 'key' && $activity->event === 'updated') {
                continue;
            }
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;
            if ($field === 'weights' && (is_array($before) || is_array($after))) {
                $flatBefore = is_array($before) ? self::flatten($before) : [];
                $flatAfter = is_array($after) ? self::flatten($after) : [];
                foreach (array_unique([...array_keys($flatBefore), ...array_keys($flatAfter)]) as $path) {
                    $b = $flatBefore[$path] ?? null;
                    $a = $flatAfter[$path] ?? null;
                    if ($b !== $a) {
                        $out[] = ['field' => "ค่าน้ำหนัก {$path}", 'old' => self::format($field, $b), 'new' => self::format($field, $a)];
                    }
                }

                continue;
            }
            if ($before === $after && $activity->event === 'updated') {
                continue;
            }
            $label = self::FIELDS[$field] ?? $field;
            $out[] = ['field' => $label, 'old' => self::format($field, $before), 'new' => self::format($field, $after)];
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $array
     * @return array<string, mixed>
     */
    private static function flatten(array $array, string $prefix = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    private static function format(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $field === 'role' && is_string($value) => UserRole::tryFrom($value)?->label() ?? $value,
            $field === 'work_groups' && is_array($value) => $value
                ? implode(', ', array_map(fn ($v) => WorkGroup::tryFrom((string) $v)?->label() ?? (string) $v, $value))
                : 'ไม่มี',
            is_bool($value) => $value ? 'เปิด' : 'ปิด',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
