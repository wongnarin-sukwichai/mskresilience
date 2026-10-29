<?php

// Thai validation messages. Partial on purpose: any rule not listed here falls back to the framework's
// English message (APP_FALLBACK_LOCALE=en), so add a key here when a new rule starts showing up in the UI.
// Per-form wording that needs more context (e.g. "รหัสชุมชนต้องอยู่ในรูปแบบ R…") belongs in that
// FormRequest's messages() instead.

return [
    'array' => ':attribute ต้องเป็นรายการ',
    'boolean' => ':attribute ต้องเป็นจริงหรือเท็จ',
    'confirmed' => ':attribute ยืนยันไม่ตรงกัน',
    'current_password' => 'รหัสผ่านไม่ถูกต้อง',
    'date' => ':attribute ต้องเป็นวันที่ที่ถูกต้อง',
    'email' => ':attribute ต้องเป็นอีเมลที่ถูกต้อง',
    'enum' => ':attribute ที่เลือกไม่ถูกต้อง',
    'exists' => ':attribute ที่เลือกไม่มีในระบบ',
    'in' => ':attribute ที่เลือกไม่ถูกต้อง',
    'integer' => ':attribute ต้องเป็นจำนวนเต็ม',
    'lowercase' => ':attribute ต้องเป็นตัวพิมพ์เล็กทั้งหมด',
    'max' => [
        'array' => ':attribute ต้องมีไม่เกิน :max รายการ',
        'numeric' => ':attribute ต้องไม่เกิน :max',
        'string' => ':attribute ต้องยาวไม่เกิน :max ตัวอักษร',
    ],
    'min' => [
        'array' => ':attribute ต้องมีอย่างน้อย :min รายการ',
        'numeric' => ':attribute ต้องไม่น้อยกว่า :min',
        'string' => ':attribute ต้องยาวอย่างน้อย :min ตัวอักษร',
    ],
    'numeric' => ':attribute ต้องเป็นตัวเลข',
    'password' => [
        'letters' => ':attribute ต้องมีตัวอักษรอย่างน้อย 1 ตัว',
        'mixed' => ':attribute ต้องมีทั้งตัวพิมพ์ใหญ่และตัวพิมพ์เล็ก',
        'numbers' => ':attribute ต้องมีตัวเลขอย่างน้อย 1 ตัว',
        'symbols' => ':attribute ต้องมีสัญลักษณ์อย่างน้อย 1 ตัว',
        'uncompromised' => ':attribute นี้เคยรั่วไหลในเหตุข้อมูลรั่ว กรุณาใช้รหัสอื่น',
    ],
    'regex' => 'รูปแบบของ :attribute ไม่ถูกต้อง',
    'required' => 'กรุณากรอก:attribute',
    'string' => ':attribute ต้องเป็นข้อความ',
    'unique' => ':attributeนี้มีอยู่ในระบบแล้ว',

    'attributes' => [
        'name' => 'ชื่อ',
        'email' => 'อีเมล',
        'password' => 'รหัสผ่าน',
        'password_confirmation' => 'การยืนยันรหัสผ่าน',
        'current_password' => 'รหัสผ่านปัจจุบัน',
        'role' => 'สิทธิ์การใช้งาน',
        'work_groups' => 'กลุ่มงาน',
        'code' => 'รหัส',
    ],
];
