<?php

namespace App\Support;

/**
 * ตารางเทียบ TOR: every clause of the municipality's TOR (public/file/tor/*.pdf, a scanned 15-page PDF) with its
 * text, where the system answers it, and a status. It is the single source for the admin page /tor-matrix, used
 * during the acceptance presentation (owner 2026-11-23). The clause numbers are the ones the <TorRef> badges use:
 *   "4.1.1(n)-m"      = scope of work, module n, item m (TOR pp. 4–6)
 *   "คุณลักษณะ x.y-m" = the spec tables (pp. 7–9)
 *   "4.2-m", "4.3-m", "4.4-m", "วัตถุประสงค์ข้อ m", "8 งวดที่ m", "11.m"
 * The text is transcribed from the scan; keep it verbatim. When a feature moves or a status changes, edit it here.
 */
class TorMatrix
{
    /** done = in the system now; test = in the system, proven at acceptance; doc = a document/delivery, done after the system; todo = still to build */
    public const STATUSES = [
        'done' => ['label' => 'มีในระบบแล้ว', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'icon' => 'fa-solid fa-circle-check'],
        'test' => ['label' => 'มีแล้ว รอทดสอบตอนตรวจรับ', 'class' => 'bg-blue-50 text-blue-700 ring-blue-200', 'icon' => 'fa-solid fa-vial'],
        'doc' => ['label' => 'เอกสาร/การส่งมอบ', 'class' => 'bg-slate-100 text-slate-600 ring-slate-200', 'icon' => 'fa-solid fa-file-lines'],
        'todo' => ['label' => 'อยู่ระหว่างดำเนินการ', 'class' => 'bg-amber-50 text-amber-700 ring-amber-200', 'icon' => 'fa-solid fa-person-digging'],
    ];

    /**
     * @return list<array{section: string, items: list<array{ref: string, text: string, status: string, where: list<array{label: string, route?: string, params?: array<string, string>}>, note?: string}>}>
     */
    public static function sections(): array
    {
        $p = fn (string $label, ?string $route = null, array $params = []) => array_filter(['label' => $label, 'route' => $route, 'params' => $params ?: null]);

        return [
            ['section' => '2. วัตถุประสงค์', 'items' => [
                ['ref' => 'วัตถุประสงค์ข้อ 1', 'status' => 'done',
                    'text' => 'เพื่อพัฒนาระบบ MKM-Resilience Dashboard ซึ่งเป็นแพลตฟอร์มดิจิทัลแบบเว็บ (Web-based Platform) สำหรับคำนวณและแสดงผลดัชนีความเสี่ยงระดับชุมชนตามกรอบ ภัย–การเปิดรับ–ความเปราะบาง–ศักยภาพ (HEVC) ในรูปแบบคะแนนเชิงปริมาณ (Risk Index 0–100)',
                    'where' => [$p('ภาพรวมความเสี่ยง', 'risk-overview.index'), $p('แผนที่รายชุมชน', 'community-map.index')]],
                ['ref' => 'วัตถุประสงค์ข้อ 2', 'status' => 'done',
                    'text' => 'เพื่อจัดทำระบบฐานข้อมูลกลางของเทศบาลที่สามารถรวบรวมและเชื่อมโยงข้อมูลจากหน่วยงานภายในเข้าสู่โครงสร้างข้อมูลมาตรฐานเดียวกันเพื่อใช้ในการวิเคราะห์ความเสี่ยงระดับชุมชน',
                    'where' => [$p('นำเข้าข้อมูล (8 กลุ่มงาน)', 'data-import.index')]],
                ['ref' => 'วัตถุประสงค์ข้อ 3', 'status' => 'done',
                    'text' => 'เพื่อพัฒนาหน้าจอสรุปสำหรับผู้บริหารที่แสดงความสัมพันธ์ระหว่าง ระดับความเสี่ยง (Risk) การจัดสรรงบประมาณและความพร้อมเชิงโครงสร้าง เพื่อสนับสนุนการตัดสินใจเชิงนโยบาย',
                    'where' => [$p('แดชบอร์ด', 'executive.index')]],
                ['ref' => 'วัตถุประสงค์ข้อ 4', 'status' => 'done',
                    'text' => 'เพื่อออกแบบระบบให้สามารถเชื่อมโยงตัวชี้วัดกับกรอบเมืองยืดหยุ่น (Making Cities Resilient 2030: MCR2030) ในระดับข้อมูลพื้นฐานเพื่อรองรับการประเมินตนเองและการจัดทำรายงานในอนาคต',
                    'where' => [$p('MCR2030 & KPI (ตารางเชื่อมโยงตัวแปร)', 'mcr2030-kpi.index', ['tab' => 'mcr'])]],
            ]],

            ['section' => '4.1.1(1) ระบบคำนวณดัชนีความเสี่ยง HEVC (HEVC Scoring Engine)', 'items' => [
                ['ref' => '4.1.1(1)-1', 'status' => 'done',
                    'text' => 'รองรับการคำนวณองค์ประกอบหลัก ได้แก่ ภัย (Hazard), การเปิดรับ (Exposure), ความเปราะบาง (Vulnerability), ศักยภาพในการรับมือ (Capacity)',
                    'where' => [$p('ภาพรวมความเสี่ยง (รายภัย)', 'risk-overview.index'), $p('ปัจจัยความเสี่ยง (E/V/C)', 'risk-factors.index')]],
                ['ref' => '4.1.1(1)-2', 'status' => 'done',
                    'text' => 'สามารถคำนวณค่า HEVC ให้อยู่ในช่วง 0–100 (Normalized Risk Index 0–100 Scale) ตามสูตรที่กำหนดในเอกสารโครงการ',
                    'where' => [$p('ภาพรวมความเสี่ยง', 'risk-overview.index')]],
                ['ref' => '4.1.1(1)-3', 'status' => 'done',
                    'text' => 'รองรับการปรับค่าน้ำหนัก (Weight Adjustment) ของแต่ละองค์ประกอบผ่านระบบผู้ดูแล (Admin Setting) โดยไม่ต้องแก้ไขโค้ดโปรแกรม',
                    'where' => [$p('จัดการเกณฑ์คะแนน', 'scoring-criteria.index')]],
                ['ref' => '4.1.1(1)-4', 'status' => 'done',
                    'text' => 'แสดงผลคะแนนย่อย (Sub-Scores) และคะแนนรวม (Composite Score) ของแต่ละชุมชน',
                    'where' => [$p('แผนที่รายชุมชน', 'community-map.index'), $p('ปัจจัยความเสี่ยง', 'risk-factors.index')]],
                ['ref' => '4.1.1(1)-5', 'status' => 'done',
                    'text' => 'สามารถคำนวณซ้ำ (Recalculation) อัตโนมัติเมื่อมีการอัปเดตข้อมูลใหม่',
                    'where' => [$p('ทุกหน้าแดชบอร์ด (คำนวณสดจากข้อมูลล่าสุด)')],
                    'note' => 'ผลคำนวณถูกเก็บในแคชและล้างทันทีเมื่อข้อมูล เกณฑ์ หรือชุมชนเปลี่ยน'],
            ]],

            ['section' => '4.1.1(2) ระบบนำเข้าข้อมูลแบบไฟล์ (Data Upload System, CSV-based)', 'items' => [
                ['ref' => '4.1.1(2)-1', 'status' => 'done',
                    'text' => 'รองรับไฟล์อย่างน้อย 6 รายการ ได้แก่ ข้อมูลภัยพิบัติ/ความเสี่ยง, การเปิดรับความเสี่ยง, ความเปราะบาง, ศักยภาพ, การฟื้นฟู, ความเสียหาย',
                    'where' => [$p('นำเข้าข้อมูล', 'data-import.index')],
                    'note' => '8 แบบฟอร์มตามกลุ่มงาน รับไฟล์ CSV และ Excel'],
                ['ref' => '4.1.1(2)-2', 'status' => 'done',
                    'text' => 'มีระบบตรวจสอบความถูกต้องของข้อมูล (Automatic Validation) เช่น ตรวจสอบคอลัมน์บังคับ (Required Fields), ตรวจสอบช่วงคะแนน (Range Check), ตรวจสอบรูปแบบข้อมูล (Data Type Validation)',
                    'where' => [$p('นำเข้าข้อมูล (ขั้นตอนตรวจสอบ)', 'data-import.index')]],
                ['ref' => '4.1.1(2)-3', 'status' => 'done',
                    'text' => 'มีระบบจัดการเวอร์ชันข้อมูล (Data Versioning) บันทึกประวัติการอัปโหลด ระบุผู้ส่งข้อมูล วันเวลา และแหล่งที่มา',
                    'where' => [$p('ประวัตินำเข้า', 'import-history.index'), $p('จัดการประวัตินำเข้า', 'import-batches.index')]],
                ['ref' => '4.1.1(2)-4', 'status' => 'done',
                    'text' => 'มีระบบบันทึกประวัติการแก้ไข (Audit Trail) เพื่อให้สามารถตรวจสอบย้อนหลังได้',
                    'where' => [$p('บันทึกการแก้ไข', 'audit-log.index')]],
            ]],

            ['section' => '4.1.1(3) หน้าจอสำหรับผู้บริหาร (Mayor View Dashboard)', 'items' => [
                ['ref' => '4.1.1(3)-1', 'status' => 'done',
                    'text' => 'Scatter Plot แสดงความสัมพันธ์ระหว่าง แกน X = ค่า HEVC และแกน Y = งบประมาณเฉลี่ยย้อนหลัง (Budget Allocation)',
                    'where' => [$p('แดชบอร์ด', 'executive.index')]],
                ['ref' => '4.1.1(3)-2', 'status' => 'done',
                    'text' => 'MCR2030 Coverage Gauge แสดงระดับความครอบคลุม 10 หลักสำคัญ (10 Essentials) แสดงผลเป็นค่า 0–10',
                    'where' => [$p('แดชบอร์ด', 'executive.index'), $p('MCR2030 & KPI', 'mcr2030-kpi.index', ['tab' => 'mcr'])]],
                ['ref' => '4.1.1(3)-3', 'status' => 'done',
                    'text' => 'ระบบแจ้งเตือนอัตโนมัติ (Policy Flag System) เช่น HEVC สูง แต่งบประมาณต่ำ, HEVC สูง และ Recovery ต่ำ',
                    'where' => [$p('แดชบอร์ด', 'executive.index')],
                    'note' => 'ผู้ดูแลตั้งเกณฑ์ได้ที่ ตั้งค่าทั่วไป'],
                ['ref' => '4.1.1(3)-4', 'status' => 'done',
                    'text' => 'ระบบสรุปภาพรวมสำหรับผู้บริหาร (Executive Summary) แสดงข้อความวิเคราะห์สั้นอัตโนมัติ (Auto-generated Insight)',
                    'where' => [$p('แดชบอร์ด', 'executive.index')]],
            ]],

            ['section' => '4.1.1(4)–(5) การฟื้นฟูและความเสียหาย', 'items' => [
                ['ref' => '4.1.1(4)', 'status' => 'done',
                    'text' => 'ระบบแสดงศักยภาพการฟื้นฟู (Recovery Radar R1–R5): แผนฟื้นฟูหลังภัย, การบูรณาการ Build Back Better, การฟื้นฟูเศรษฐกิจและสังคม, การดูแลกลุ่มเปราะบาง, การถอดบทเรียนและปรับปรุงระบบ แสดงผลในรูปแบบ Radar Chart (Spider Chart) หรือกราฟเทียบเคียง เพื่อให้เห็นจุดแข็งและจุดอ่อนของแต่ละชุมชนอย่างชัดเจน',
                    'where' => [$p('แผนที่รายชุมชน (เลือกชุมชน)', 'community-map.index'), $p('ผลกระทบและการฟื้นตัว', 'impact-recovery.index')]],
                ['ref' => '4.1.1(5)-1', 'status' => 'done',
                    'text' => 'หน้าจอแสดงความเสียหาย (Loss & Damage Screen): แสดงแนวโน้มความเสียหายย้อนหลัง (Trend Analysis)',
                    'where' => [$p('ผลกระทบและการฟื้นตัว', 'impact-recovery.index')]],
                ['ref' => '4.1.1(5)-2', 'status' => 'done',
                    'text' => 'เปรียบเทียบความเสียหายจริงกับค่า HEVC',
                    'where' => [$p('ผลกระทบและการฟื้นตัว', 'impact-recovery.index')]],
                ['ref' => '4.1.1(5)-3', 'status' => 'done',
                    'text' => 'สนับสนุนการใช้ข้อมูลประกอบการเสนอของบประมาณหรือกองทุน (Funding Evidence Support)',
                    'where' => [$p('รายงานประกอบการของบประมาณ (เลือกชุมชนในหน้าแผนที่/ผลกระทบ)'), $p('ส่งออก Excel เหตุการณ์', 'impact-recovery.index')]],
            ]],

            ['section' => '4.1.1(6) ระบบบริหารจัดการผู้ใช้งาน (User Management System)', 'items' => [
                ['ref' => '4.1.1(6)', 'status' => 'done',
                    'text' => 'ระบบจัดการสิทธิ์ผู้ใช้งาน (Role-Based Access Control: RBAC) อย่างน้อย 3 ระดับ: ผู้ดูแลระบบ (Admin), ผู้บันทึกข้อมูล (Data Owner), ผู้บริหาร (Executive Viewer)',
                    'where' => [$p('จัดการสมาชิก', 'users.index')]],
                ['ref' => '4.1.1(6)-1', 'status' => 'done',
                    'text' => 'ผู้ดูแลระบบ: จัดการค่าน้ำหนัก, อนุมัติข้อมูล, จัดการผู้ใช้งาน',
                    'where' => [$p('จัดการเกณฑ์คะแนน', 'scoring-criteria.index'), $p('อนุมัติข้อมูล', 'import-approvals.index'), $p('จัดการสมาชิก', 'users.index')],
                    'note' => 'โหมดอนุมัติเปิด/ปิดได้ที่ ตั้งค่าทั่วไป'],
                ['ref' => '4.1.1(6)-2', 'status' => 'done',
                    'text' => 'ผู้บันทึกข้อมูล: อัปโหลดข้อมูลเฉพาะส่วนงานตนเอง, แก้ไขข้อมูลในขอบเขตที่ได้รับอนุญาต',
                    'where' => [$p('นำเข้าข้อมูล', 'data-import.index'), $p('แก้ไขข้อมูล', 'data-records.index')]],
                ['ref' => '4.1.1(6)-3', 'status' => 'done',
                    'text' => 'ผู้บริหาร: ดูข้อมูลภาพรวม, ไม่สามารถแก้ไขข้อมูลดิบ',
                    'where' => [$p('เมนูแดชบอร์ด (บทบาท ผู้บริหาร เห็นเฉพาะหน้าแสดงผล)', 'dashboard')]],
                ['ref' => '4.1.1(6)-4', 'status' => 'done',
                    'text' => 'ระบบต้องสามารถบันทึกประวัติการเข้าใช้งาน (Login Log) และการแก้ไขข้อมูลทุกครั้ง',
                    'where' => [$p('ประวัติการเข้าใช้งาน', 'login-logs.index'), $p('บันทึกการแก้ไข', 'audit-log.index')]],
            ]],

            ['section' => '4.1.2–4.1.4 โมดูล AI, UX/UI และคู่มือ', 'items' => [
                ['ref' => '4.1.2', 'status' => 'done',
                    'text' => 'การพัฒนาโมดูล AI (Basic AI Module): วิเคราะห์แนวโน้ม HEVC รายชุมชน, แจ้งเตือนชุมชนที่มีความเสี่ยงสูงต่อเนื่อง, เสนอ Policy Flag อัตโนมัติ, วิเคราะห์ก่อนและหลังการลงทุน (Before–After Investment)',
                    'where' => [$p('แดชบอร์ด', 'executive.index'), $p('แผนที่รายชุมชน (แนวโน้ม)', 'community-map.index')]],
                ['ref' => '4.1.3', 'status' => 'test',
                    'text' => 'การออกแบบ UX/UI: รองรับภาษาไทย, แสดงผลเข้าใจง่ายสำหรับผู้บริหาร, รองรับ Desktop และ Tablet',
                    'where' => [$p('ทุกหน้า')],
                    'note' => 'ตรวจทุกหน้าที่ความกว้าง 768, 820 และ 1024 px แล้ว (แตะกราฟเพื่อดูค่าได้) · ยืนยันบน Tablet จริงตอนตรวจรับ'],
                ['ref' => '4.1.4', 'status' => 'doc',
                    'text' => 'การจัดทำคู่มือ: คู่มือผู้บริหาร (Executive Guide), คู่มือเจ้าหน้าที่กรอกข้อมูล, คู่มือทางเทคนิค, เอกสารสูตรการคำนวณ HEVC และ Logic',
                    'where' => [],
                    'note' => 'จัดทำหลังระบบเสร็จ (เจ้าของโครงการ 2026-11-23)'],
            ]],

            ['section' => '4.2 ผลผลิตที่ต้องส่งมอบ', 'items' => [
                ['ref' => '4.2-1', 'status' => 'done', 'text' => 'ระบบ MSK-Resilience Dashboard', 'where' => [$p('ระบบนี้', 'dashboard')]],
                ['ref' => '4.2-2', 'status' => 'doc', 'text' => 'โครงสร้างฐานข้อมูล', 'where' => [], 'note' => 'ER Diagram ส่งพร้อมเอกสาร'],
                ['ref' => '4.2-3', 'status' => 'test', 'text' => 'ฐานข้อมูลตัวอย่าง 32 ชุมชน', 'where' => [$p('จัดการชุมชน', 'communities.index')],
                    'note' => 'ปัจจุบันเป็นข้อมูลสมมุติ 3 ปีงบประมาณ เพื่อการทดสอบ'],
                ['ref' => '4.2-4', 'status' => 'doc', 'text' => 'คู่มือการใช้งาน (PDF)', 'where' => []],
                ['ref' => '4.2-5', 'status' => 'doc', 'text' => 'รายงานสรุปผลโครงการ', 'where' => []],
                ['ref' => '4.2-6', 'status' => 'doc', 'text' => 'อบรมการใช้งาน ไม่น้อยกว่า 1 ครั้ง', 'where' => []],
            ]],

            ['section' => '4.3 การตรวจรับงาน', 'items' => [
                ['ref' => '4.3-1', 'status' => 'test', 'text' => 'ระบบทำงานครบตามขอบเขตงาน', 'where' => [], 'note' => 'ใช้ตารางนี้ประกอบการตรวจรับ'],
                ['ref' => '4.3-2', 'status' => 'done', 'text' => 'คำนวณ HEVC ถูกต้องตามสูตร',
                    'where' => [$p('ปุ่ม “การคำนวณ” ในแผนที่รายชุมชน (ผู้ดูแล)', 'community-map.index')]],
                ['ref' => '4.3-3', 'status' => 'done', 'text' => 'Upload ข้อมูลได้จริง', 'where' => [$p('นำเข้าข้อมูล', 'data-import.index')]],
                ['ref' => '4.3-4', 'status' => 'done', 'text' => 'แสดงผล ความเสี่ยง (Risk) – งบประมาณ (Budget) – การกำกับดูแล (Governance) ได้',
                    'where' => [$p('แดชบอร์ด', 'executive.index'), $p('ภาพรวมความเสี่ยง (ความพร้อมของเมือง)', 'risk-overview.index')]],
            ]],

            ['section' => '4.4 คุณลักษณะครุภัณฑ์', 'items' => [
                ['ref' => '4.4-1', 'status' => 'done', 'text' => 'Web-based Application', 'where' => [$p('ระบบนี้', 'dashboard')]],
                ['ref' => '4.4-2', 'status' => 'done', 'text' => 'รองรับการนำเข้าข้อมูลในรูปแบบ CSV', 'where' => [$p('นำเข้าข้อมูล', 'data-import.index')]],
                ['ref' => '4.4-3', 'status' => 'test', 'text' => 'รองรับผู้ใช้งานพร้อมกันไม่น้อยกว่า 20 User',
                    'where' => [$p('ประวัติการเข้าใช้งาน (ผู้ใช้ที่ออนไลน์ขณะนี้)', 'login-logs.index')],
                    'note' => 'บัญชีทดสอบ: php artisan loadtest:accounts · ทดสอบโหลด 20/40/60 ผู้ใช้: php artisan loadtest (รันบนเครื่องจริงหลังติดตั้ง)'],
                ['ref' => '4.4-4', 'status' => 'done', 'text' => 'Export PDF/Excel ได้',
                    'where' => [$p('ปุ่มส่งออกในหน้าแดชบอร์ด', 'risk-overview.index'), $p('แดชบอร์ด', 'executive.index')]],
                ['ref' => '4.4-5', 'status' => 'done', 'text' => 'มีระบบสำรองข้อมูล', 'where' => [$p('สำรองข้อมูล', 'backups.index')]],
                ['ref' => '4.4-6', 'status' => 'done', 'text' => 'มีสิทธิ์การใช้งานตามระดับ', 'where' => [$p('จัดการสมาชิก', 'users.index')]],
            ]],

            ['section' => 'คุณลักษณะ 2.1 โครงสร้างพื้นฐานระบบ', 'items' => [
                ['ref' => 'คุณลักษณะ 2.1-1', 'status' => 'done', 'text' => 'ประเภทระบบ: Web-based Application (ตรวจรับ: ทดสอบเปิดผ่าน Browser)', 'where' => [$p('ระบบนี้', 'dashboard')]],
                ['ref' => 'คุณลักษณะ 2.1-2', 'status' => 'test', 'text' => 'รองรับ Browser: Chrome / Edge / Firefox รุ่นปัจจุบัน (ตรวจรับ: ทดสอบใช้งานจริง)', 'where' => []],
                ['ref' => 'คุณลักษณะ 2.1-3', 'status' => 'test', 'text' => 'รองรับผู้ใช้: เข้าใช้งานพร้อมกันไม่น้อยกว่า 20 User (ตรวจรับ: ทดสอบ login พร้อมกัน)',
                    'where' => [$p('ประวัติการเข้าใช้งาน (ผู้ใช้ที่ออนไลน์ขณะนี้)', 'login-logs.index')],
                    'note' => 'ทดสอบกับคนจริงด้วยบัญชี ทดสอบ01–20 และสคริปต์ php artisan loadtest'],
                ['ref' => 'คุณลักษณะ 2.1-4', 'status' => 'done', 'text' => 'รองรับสิทธิ์ผู้ใช้: Admin / Data Owner / Executive (ตรวจรับ: ตรวจสอบระบบสิทธิ์)', 'where' => [$p('จัดการสมาชิก', 'users.index')]],
                ['ref' => 'คุณลักษณะ 2.1-5', 'status' => 'done', 'text' => 'มีระบบสำรองข้อมูล: สำรองข้อมูลทุก 1 เดือนเป็นอย่างน้อย (ตรวจรับ: แสดง Log Backup)', 'where' => [$p('สำรองข้อมูล', 'backups.index')]],
                ['ref' => 'คุณลักษณะ 2.1-6', 'status' => 'done', 'text' => 'มีระบบบันทึกประวัติ (Audit Trail): บันทึกว่าใครแก้ไขข้อมูล เมื่อใด (ตรวจรับ: ทดสอบแก้ไขข้อมูล)', 'where' => [$p('บันทึกการแก้ไข', 'audit-log.index')]],
            ]],

            ['section' => 'คุณลักษณะ 2.2 ระบบการคำนวณ HEVC', 'items' => [
                ['ref' => 'คุณลักษณะ 2.2-1', 'status' => 'done', 'text' => 'คำนวณ Hazard: รองรับประเภทของภัยพิบัติไม่น้อยกว่า 5 ประเภท (ตรวจรับ: ทดสอบป้อนข้อมูล)',
                    'where' => [$p('ภาพรวมความเสี่ยง (5 ภัย)', 'risk-overview.index')]],
                ['ref' => 'คุณลักษณะ 2.2-2', 'status' => 'done', 'text' => 'คำนวณ Exposure: คำนวณ % อัตโนมัติจากข้อมูลดิบ (ตรวจรับ: ตรวจสูตร)', 'where' => [$p('ปัจจัยความเสี่ยง', 'risk-factors.index')]],
                ['ref' => 'คุณลักษณะ 2.2-3', 'status' => 'done', 'text' => 'คำนวณ Vulnerability: แยก People/System ได้ (ตรวจรับ: ทดสอบคะแนน)', 'where' => [$p('ปัจจัยความเสี่ยง', 'risk-factors.index')]],
                ['ref' => 'คุณลักษณะ 2.2-4', 'status' => 'done', 'text' => 'คำนวณ Capacity: คำนวณ Mitigation Factor (ตรวจรับ: ตรวจผลลัพธ์)', 'where' => [$p('ปัจจัยความเสี่ยง', 'risk-factors.index')]],
                ['ref' => 'คุณลักษณะ 2.2-5', 'status' => 'done', 'text' => 'คำนวณ HEVC: แปลงค่าเป็น 0–100 (ตรวจรับ: เทียบกับตัวอย่าง)', 'where' => [$p('ภาพรวมความเสี่ยง', 'risk-overview.index')]],
                ['ref' => 'คุณลักษณะ 2.2-6', 'status' => 'done', 'text' => 'ปรับค่าน้ำหนักได้: Admin สามารถตั้งค่า weight (ตรวจรับ: ทดสอบปรับค่า)', 'where' => [$p('จัดการเกณฑ์คะแนน', 'scoring-criteria.index')]],
            ]],

            ['section' => 'คุณลักษณะ 2.3 ระบบอัปโหลดข้อมูล', 'items' => [
                ['ref' => 'คุณลักษณะ 2.3-1', 'status' => 'done', 'text' => 'รองรับไฟล์ CSV: ไม่น้อยกว่า 6 ไฟล์ (ตรวจรับ: ทดลองอัปโหลด)', 'where' => [$p('นำเข้าข้อมูล', 'data-import.index')]],
                ['ref' => 'คุณลักษณะ 2.3-2', 'status' => 'done', 'text' => 'ตรวจสอบความถูกต้อง (Validation): แจ้งเตือนคอลัมน์ขาด/ข้อมูลผิดช่วง (ตรวจรับ: ทดสอบไฟล์ผิด)', 'where' => [$p('นำเข้าข้อมูล', 'data-import.index')]],
                ['ref' => 'คุณลักษณะ 2.3-3', 'status' => 'done', 'text' => 'รวมข้อมูลอัตโนมัติ: เชื่อมโยงข้อมูลระหว่างตารางในฐานข้อมูล (ตรวจรับ: ตรวจผลรวม)',
                    'where' => [$p('ภาพรวมความเสี่ยง', 'risk-overview.index')], 'note' => 'ทุกแผ่นงานเชื่อมกันด้วยรหัสชุมชน COMMUNITY_ID'],
                ['ref' => 'คุณลักษณะ 2.3-4', 'status' => 'done', 'text' => 'แสดงสถานะข้อมูล: สำเร็จ/ไม่สำเร็จ (ตรวจรับ: ตรวจหน้าจอ)', 'where' => [$p('ประวัตินำเข้า', 'import-history.index')]],
                ['ref' => 'คุณลักษณะ 2.3-5', 'status' => 'done', 'text' => 'รองรับ Versioning: เก็บประวัติ upload ทุกครั้ง (ตรวจรับ: ตรวจ Log)', 'where' => [$p('ประวัตินำเข้า', 'import-history.index')]],
            ]],

            ['section' => 'คุณลักษณะ 2.4–2.6 Mayor View, Recovery & Loss, AI Module', 'items' => [
                ['ref' => 'คุณลักษณะ 2.4-1', 'status' => 'done', 'text' => 'Scatter Plot: HEVC vs Budget (ตรวจรับ: แสดงข้อมูล 32 ชุมชน)', 'where' => [$p('แดชบอร์ด', 'executive.index')]],
                ['ref' => 'คุณลักษณะ 2.4-2', 'status' => 'done', 'text' => 'MCR2030 Coverage Gauge: แสดงค่า 0–10 (ตรวจรับ: ทดสอบแสดงผล)', 'where' => [$p('MCR2030 & KPI', 'mcr2030-kpi.index', ['tab' => 'mcr'])]],
                ['ref' => 'คุณลักษณะ 2.4-3', 'status' => 'done', 'text' => 'Policy Flag: แจ้งเตือนชุมชนเสี่ยง (ตรวจรับ: ทดสอบเงื่อนไข)', 'where' => [$p('แดชบอร์ด', 'executive.index'), $p('ตั้งค่าทั่วไป (เกณฑ์)', 'system-settings.index')]],
                ['ref' => 'คุณลักษณะ 2.4-4', 'status' => 'done', 'text' => 'Executive Summary: สรุปอัตโนมัติ (ตรวจรับ: ตรวจข้อความ)', 'where' => [$p('แดชบอร์ด', 'executive.index')]],
                ['ref' => 'คุณลักษณะ 2.5-1', 'status' => 'done', 'text' => 'Radar Chart R1–R5: แสดงคะแนนฟื้นฟู (ตรวจรับ: ตรวจหน้าจอ)', 'where' => [$p('แผนที่รายชุมชน', 'community-map.index'), $p('ผลกระทบและการฟื้นตัว', 'impact-recovery.index')]],
                ['ref' => 'คุณลักษณะ 2.5-2', 'status' => 'done', 'text' => 'Loss & Damage Trend: แสดงข้อมูลย้อนหลัง (ตรวจรับ: ทดลองป้อนข้อมูล)', 'where' => [$p('ผลกระทบและการฟื้นตัว', 'impact-recovery.index')]],
                ['ref' => 'คุณลักษณะ 2.5-3', 'status' => 'done', 'text' => 'แสดง Before–After HEVC: เปรียบเทียบผลลงทุน (ตรวจรับ: ทดสอบกรณีตัวอย่าง)', 'where' => [$p('แดชบอร์ด', 'executive.index')]],
                ['ref' => 'คุณลักษณะ 2.6-1', 'status' => 'done', 'text' => 'วิเคราะห์แนวโน้ม HEVC: แสดงแนวโน้มเพิ่ม/ลด (ตรวจรับ: ทดลองข้อมูลย้อนหลัง)',
                    'where' => [$p('แผนที่รายชุมชน (เลือกชุมชน)', 'community-map.index'), $p('เปรียบเทียบรายปี', 'year-comparison.index')]],
                ['ref' => 'คุณลักษณะ 2.6-2', 'status' => 'done', 'text' => 'แจ้งเตือนชุมชนเสี่ยงสูงต่อเนื่อง: Policy Flag อัตโนมัติ (ตรวจรับ: ทดสอบ threshold)', 'where' => [$p('แดชบอร์ด', 'executive.index')]],
                ['ref' => 'คุณลักษณะ 2.6-3', 'status' => 'done', 'text' => 'วิเคราะห์ผลการลงทุน: HEVC Before/After (ตรวจรับ: ทดสอบกรณีตัวอย่าง)', 'where' => [$p('แดชบอร์ด', 'executive.index')]],
            ]],

            ['section' => 'คุณลักษณะ 3 ด้านความปลอดภัยข้อมูล', 'items' => [
                ['ref' => 'คุณลักษณะ 3-1', 'status' => 'done', 'text' => 'ระบบ Login: Username/Password (ตรวจรับ: ทดสอบ)', 'where' => [$p('หน้าแรก (ปุ่มเข้าสู่ระบบ)', 'home')]],
                ['ref' => 'คุณลักษณะ 3-2', 'status' => 'done', 'text' => 'แยกสิทธิ์ข้อมูล: Data Owner เห็นเฉพาะของตน (ตรวจรับ: ตรวจสิทธิ์)',
                    'where' => [$p('จัดการสมาชิก (กำหนดกลุ่มงาน)', 'users.index'), $p('แก้ไขข้อมูล', 'data-records.index')]],
                ['ref' => 'คุณลักษณะ 3-3', 'status' => 'done', 'text' => 'เข้ารหัสผ่าน: ไม่แสดงรหัสผ่านแบบ Plain text (ตรวจรับ: ตรวจระบบ)', 'where' => [],
                    'note' => 'รหัสผ่านเก็บแบบ bcrypt hash และไม่ถูกบันทึกลงประวัติการแก้ไข'],
                ['ref' => 'คุณลักษณะ 3-4', 'status' => 'done', 'text' => 'Log การเข้าใช้งาน: บันทึกเวลาเข้า/ออก (ตรวจรับ: ตรวจ Log)', 'where' => [$p('ประวัติการเข้าใช้งาน', 'login-logs.index')]],
            ]],

            ['section' => 'คุณลักษณะ 4 เอกสารที่ต้องส่งมอบ และ 8 งวดงาน', 'items' => [
                ['ref' => 'คุณลักษณะ 4-1', 'status' => 'doc', 'text' => 'Source Code (ตรวจรับ: ส่งไฟล์ครบ)', 'where' => []],
                ['ref' => 'คุณลักษณะ 4-2', 'status' => 'doc', 'text' => 'Database Structure (ตรวจรับ: ส่ง ER Diagram)', 'where' => []],
                ['ref' => 'คุณลักษณะ 4-3', 'status' => 'doc', 'text' => 'คู่มือผู้บริหาร (ตรวจรับ: ตรวจเอกสาร)', 'where' => []],
                ['ref' => 'คุณลักษณะ 4-4', 'status' => 'doc', 'text' => 'คู่มือเจ้าหน้าที่ (ตรวจรับ: ตรวจเอกสาร)', 'where' => []],
                ['ref' => 'คุณลักษณะ 4-5', 'status' => 'doc', 'text' => 'คู่มือทางเทคนิค (ตรวจรับ: ตรวจเอกสาร)', 'where' => []],
                ['ref' => 'คุณลักษณะ 4-6', 'status' => 'doc', 'text' => 'รายงานสรุปโครงการ (ตรวจรับ: ตรวจรายงาน)', 'where' => []],
                ['ref' => 'คุณลักษณะ 4-7', 'status' => 'doc', 'text' => 'อบรมการใช้งาน (ตรวจรับ: มีรายชื่อผู้เข้าอบรม)', 'where' => []],
                ['ref' => '8 งวดที่ 1', 'status' => 'doc', 'text' => 'ส่งมอบเอกสารออกแบบระบบ (System Design) และ UI Mockup และ Data Structure ภายใน 45 วัน', 'where' => []],
                ['ref' => '8 งวดที่ 2', 'status' => 'doc', 'text' => 'ส่งมอบระบบต้นแบบ (Prototype) และผลการทดสอบระบบ 5 ชุมชน พร้อมรายงานทดสอบระบบ ภายใน 60 วัน', 'where' => []],
                ['ref' => '8 งวดที่ 3', 'status' => 'doc', 'text' => 'ส่งมอบระบบสมบูรณ์ 32 ชุมชน พร้อมคู่มือเอกสารทั้งหมดและอบรมการใช้งาน ภายใน 45 วัน', 'where' => []],
            ]],

            ['section' => '11. เงื่อนไขอื่นๆ', 'items' => [
                ['ref' => '11.1', 'status' => 'done', 'text' => 'เทศบาลเมืองมหาสารคามเป็นเจ้าของข้อมูล', 'where' => [$p('สำรองข้อมูล (ดาวน์โหลดได้)', 'backups.index')],
                    'note' => 'ข้อมูลทั้งหมดอยู่ในฐานข้อมูลของเทศบาล ส่งออกได้ทุกเมื่อ'],
                ['ref' => '11.2', 'status' => 'doc', 'text' => 'ผู้รับจ้างต้องให้คำปรึกษาทางวิชาการ ให้เทศบาลเมืองมหาสารคาม', 'where' => []],
                ['ref' => '11.3', 'status' => 'done', 'text' => 'ระบบต้องสามารถต่อยอด MCR2030 ได้', 'where' => [$p('MCR2030 & KPI', 'mcr2030-kpi.index', ['tab' => 'mcr'])]],
                ['ref' => '11.4', 'status' => 'done', 'text' => 'มีการเก็บ Log การใช้งาน', 'where' => [$p('ประวัติการเข้าใช้งาน', 'login-logs.index'), $p('บันทึกการแก้ไข', 'audit-log.index')]],
            ]],
        ];
    }

    /**
     * The sections with each link resolved to a URL (route() needs the app booted, so it isn't done in sections()).
     */
    public static function forPage(): array
    {
        return array_map(fn ($s) => array_merge($s, ['items' => array_map(fn ($i) => array_merge($i, [
            'where' => array_map(fn ($w) => ['label' => $w['label'], 'href' => isset($w['route']) ? route($w['route'], $w['params'] ?? []) : null], $i['where']),
        ]), $s['items'])]), self::sections());
    }
}
