<!DOCTYPE html>
{{-- Load-test report for the acceptance documents (TOR 4.4-3, คุณลักษณะ 2.1-3). Rendered by `php artisan loadtest`,
     then printed to PDF with headless Edge/Chrome. Standalone HTML (no Vite): it is opened from disk, not served. --}}
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายงานทดสอบผู้ใช้งานพร้อมกัน</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 14mm 14mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Anuphan', 'Leelawadee UI', 'Tahoma', sans-serif; color: #1e293b; font-size: 12.5px; line-height: 1.55; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .head { border-bottom: 2px solid #1e293b; padding-bottom: 8px; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; }
        .head h1 { font-size: 19px; margin: 0; }
        .muted { color: #64748b; }
        .small { font-size: 11px; }
        h2 { font-size: 14.5px; margin: 22px 0 8px; padding-left: 8px; border-left: 4px solid #2563eb; }
        .verdict { margin-top: 16px; border-radius: 10px; padding: 14px 16px; display: flex; gap: 14px; align-items: center; }
        .verdict.pass { background: #ecfdf5; border: 1px solid #a7f3d0; }
        .verdict.fail { background: #fef2f2; border: 1px solid #fecaca; }
        .badge { font-weight: 700; font-size: 15px; border-radius: 8px; padding: 6px 14px; color: #fff; white-space: nowrap; }
        .pass .badge { background: #059669; }
        .fail .badge { background: #dc2626; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: right; vertical-align: top; }
        th { background: #2563eb; color: #fff; font-weight: 600; text-align: center; }
        td:first-child, th:first-child { text-align: left; }
        tr:nth-child(even) td { background: #f8fafc; }
        .ok { color: #059669; font-weight: 600; }
        .bad { color: #dc2626; font-weight: 600; }
        dl { display: grid; grid-template-columns: 150px 1fr; gap: 3px 12px; margin: 0; }
        dt { color: #64748b; }
        dd { margin: 0; }
        .bar { height: 9px; background: #2563eb; border-radius: 4px; display: inline-block; vertical-align: middle; }
        .note { background: #f1f5f9; border-radius: 8px; padding: 10px 12px; font-size: 11.5px; }
        ul { margin: 4px 0; padding-left: 18px; }
        .sign { margin-top: 36px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; text-align: center; page-break-inside: avoid; }
        .sign .line { border-bottom: 1px dotted #475569; height: 40px; margin-bottom: 6px; }
        .keep { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="head">
        <div>
            <h1>รายงานผลการทดสอบผู้ใช้งานพร้อมกัน</h1>
            <div class="muted">แพลตฟอร์มข้อมูลการจัดการภัยพิบัติ (MSK-Resilience Dashboard) · เทศบาลเมืองมหาสารคาม</div>
        </div>
        <div class="muted small" style="text-align:right">อ้างอิง TOR ข้อ 4.4-3<br>คุณลักษณะ 2.1-3</div>
    </div>

    <div class="verdict {{ $pass ? 'pass' : 'fail' }}">
        <span class="badge">{{ $pass ? 'ผ่าน' : 'ไม่ผ่าน' }}</span>
        <div>
            <strong>ข้อกำหนด:</strong> ระบบรองรับผู้ใช้งานพร้อมกันไม่น้อยกว่า {{ $required }} ผู้ใช้<br>
            @if ($requiredRow)
                ผลที่ {{ $requiredRow['users'] }} ผู้ใช้พร้อมกัน: เข้าสู่ระบบสำเร็จ {{ $requiredRow['logins'] }} ผู้ใช้,
                คำขอทั้งหมด {{ number_format($requiredRow['requests']) }} ครั้ง ล้มเหลว {{ $requiredRow['failed'] }} ครั้ง
            @else
                ไม่ได้ทดสอบที่ระดับ {{ $required }} ผู้ใช้
            @endif
        </div>
    </div>

    <h2>ข้อมูลการทดสอบ</h2>
    <dl>
        <dt>เว็บไซต์ที่ทดสอบ</dt><dd>{{ $base }}</dd>
        <dt>วันเวลาที่ทดสอบ</dt><dd>{{ $testedAt }}</dd>
        <dt>ระดับที่ทดสอบ</dt><dd>{{ collect($results)->pluck('users')->map(fn ($u) => $u.' ผู้ใช้')->implode(', ') }} (ทีละระดับ เว้นระยะ {{ $pause }} วินาที)</dd>
        <dt>หน้าที่ผู้ใช้แต่ละคนเปิด</dt><dd>หน้าแรก → เข้าสู่ระบบ → {{ implode(' → ', $pageLabels) }}</dd>
        <dt>เกณฑ์คำขอล้มเหลว</dt><dd>ได้รับสถานะที่ไม่ถูกต้อง (เช่น 4xx/5xx) หรือไม่ตอบภายใน {{ $timeout }} วินาที</dd>
    </dl>

    <h2>สรุปผลรายระดับ</h2>
    <table>
        <thead>
            <tr>
                <th>ผู้ใช้พร้อมกัน</th><th>คำขอ</th><th>ล้มเหลว</th><th>เข้าสู่ระบบสำเร็จ</th>
                <th>เวลาเฉลี่ย (วินาที)</th><th>p95 (วินาที)</th><th>สูงสุด (วินาที)</th><th>ใช้เวลารวม (วินาที)</th><th>คำขอ/วินาที</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($results as $r)
                <tr>
                    <td><strong>{{ $r['users'] }}</strong> ผู้ใช้</td>
                    <td>{{ number_format($r['requests']) }}</td>
                    <td class="{{ $r['failed'] ? 'bad' : 'ok' }}">{{ $r['failed'] }}</td>
                    <td>{{ $r['logins'] }}</td>
                    <td>{{ number_format($r['avg'], 2) }}</td>
                    <td>{{ number_format($r['p95'], 2) }}</td>
                    <td>{{ number_format($r['max'], 2) }}</td>
                    <td>{{ number_format($r['seconds'], 1) }}</td>
                    <td>{{ number_format($r['rps'], 1) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted small">p95 = 95% ของคำขอทั้งหมดได้รับคำตอบภายในเวลานี้</p>

    <div class="keep">
        <h2>เวลาตอบสนองเฉลี่ยรายหน้า (วินาที)</h2>
        <table>
            <thead>
                <tr>
                    <th>หน้า</th>
                    @foreach ($results as $r)<th>{{ $r['users'] }} ผู้ใช้</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($perPage as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        @foreach ($row['values'] as $v)
                            <td>
                                <span class="bar" style="width: {{ $maxPage > 0 ? max(2, round($v / $maxPage * 70)) : 0 }}px"></span>
                                {{ number_format($v, 2) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($failures)
        <div class="keep">
            <h2>คำขอที่ล้มเหลว</h2>
            <ul>
                @foreach ($failures as $label => $n)
                    <li>{{ $label }}: {{ $n }} ครั้ง</li>
                @endforeach
            </ul>
        </div>
    @endif

    <h2>วิธีการทดสอบ</h2>
    <div class="note">
        <ul>
            <li>ผู้ใช้จำลองแต่ละคนใช้บัญชีทดสอบของตนเอง (สิทธิ์ดูข้อมูลอย่างเดียว) และมี session แยกกัน เหมือนผู้ใช้จริงที่เปิดเบราว์เซอร์คนละเครื่อง</li>
            <li>ผู้ใช้ทุกคนในระดับเดียวกันเริ่มพร้อมกัน และส่งคำขอพร้อมกันจริง (ไม่ได้ทยอยทีละคน)</li>
            <li>ทุกคำขอถูกจับเวลาตั้งแต่ส่งจนได้รับคำตอบครบ คำขอที่ล้มเหลวถูกบันทึกไว้และผู้ใช้คนนั้นทำขั้นต่อไปต่อ</li>
            <li>ทดสอบจากเครื่องภายนอกผ่านอินเทอร์เน็ตไปยังเซิร์ฟเวอร์จริง เวลาที่วัดได้จึงรวมเวลาเดินทางของเครือข่ายด้วย</li>
            <li>บัญชีทดสอบถูกสร้างก่อนทดสอบด้วยคำสั่ง <code>loadtest:accounts</code> และลบออกหลังทดสอบ</li>
        </ul>
    </div>

    <div class="sign">
        <div><div class="line"></div>ผู้ทดสอบ<br><span class="muted small">วันที่ ........ / ........ / ........</span></div>
        <div><div class="line"></div>ผู้ตรวจรับ<br><span class="muted small">วันที่ ........ / ........ / ........</span></div>
    </div>
</body>
</html>
