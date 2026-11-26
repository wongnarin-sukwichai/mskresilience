<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;

/**
 * Concurrent-user load test (TOR 4.4-3 "รองรับผู้ใช้งานพร้อมกันไม่น้อยกว่า 20 User", คุณลักษณะ 2.1-3 "ทดสอบ login
 * พร้อมกัน"). For each level (default 20, 40, 60) that many virtual users, each with its own session, at the same
 * moment: open the home page, log in with their own ทดสอบ account (loadtest:accounts), then open the dashboard
 * pages one after another. Every request is timed. Prints a summary and saves the report under
 * storage/app/private/loadtest/ as Markdown, as HTML (resources/views/reports/loadtest.blade.php) and, when Edge or
 * Chrome is installed on the machine running the test, as a PDF printed from that HTML (owner 2026-11-26: a PDF to
 * attach for an inspector who can't run the test themselves). The PDF says ผ่าน when the --required level (default
 * 20, the TOR number) had every login succeed and no failed request.
 *
 * Run it from another machine against the real host (tell the hosting provider first), e.g.
 *   php artisan loadtest https://msk.example.go.th --password=…
 */
class LoadTest extends Command
{
    protected $signature = 'loadtest
        {url : the site to test, e.g. https://example.go.th}
        {--users=20,40,60 : the concurrency levels}
        {--password= : the password of the ทดสอบ accounts}
        {--pages=/executive,/risk-overview,/community-map,/risk-factors,/impact-recovery,/mcr2030-kpi : pages each user opens after login}
        {--pause=5 : seconds to wait between levels}
        {--timeout=60 : seconds before one request counts as failed}
        {--required=20 : the TOR concurrent-user requirement, for the ผ่าน/ไม่ผ่าน verdict}
        {--no-pdf : skip printing the PDF}
        {--browser= : path to msedge/chrome, if not found automatically}';

    protected $description = 'Simulate 20/40/60 users logging in and using the dashboard at the same time';

    /** @var list<array{level: int, step: string, ms: float, ok: bool, note: string}> */
    private array $samples = [];

    /** One curl_multi handler shared by every virtual user, so their requests really run at the same time. */
    private ?HandlerStack $stack = null;

    public function handle(): int
    {
        $base = rtrim((string) $this->argument('url'), '/');
        $password = (string) $this->option('password');
        if ($password === '') {
            $this->error('ต้องระบุ --password= (รหัสผ่านของบัญชีทดสอบ จาก loadtest:accounts)');

            return self::FAILURE;
        }
        $levels = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('users')))));
        $pages = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('pages')))));
        if ($levels === [] || max($levels) > 99) {
            $this->error('--users ต้องอยู่ระหว่าง 1–99 (เท่ากับจำนวนบัญชีทดสอบที่มี)');

            return self::FAILURE;
        }

        $results = [];
        foreach ($levels as $i => $level) {
            if ($i > 0) {
                sleep((int) $this->option('pause'));
            }
            $this->info("▶ {$level} ผู้ใช้พร้อมกัน …");
            $started = microtime(true);
            $chains = [];
            for ($u = 1; $u <= $level; $u++) {
                $chains[] = $this->user($base, LoadTestAccounts::email($u), $password, $pages, $level);
            }
            Utils::settle($chains)->wait();
            $results[] = $this->summarise($level, microtime(true) - $started);
        }

        $headers = ['ผู้ใช้พร้อมกัน', 'คำขอ', 'ล้มเหลว', 'login สำเร็จ', 'เฉลี่ย (วินาที)', 'p95 (วินาที)', 'สูงสุด (วินาที)', 'ใช้เวลารวม (วินาที)', 'คำขอ/วินาที'];
        $this->table($headers, array_map('array_values', $results));
        $path = $this->report($base, $headers, $results, $pages);
        $this->info("บันทึกรายงานที่ {$path}");
        $html = $this->htmlReport($base, $results, $pages, preg_replace('/\.md$/', '.html', $path));
        $this->info("รายงาน HTML: {$html}");
        if (! $this->option('no-pdf')) {
            $pdf = $this->printPdf($html);
            $pdf ? $this->info("รายงาน PDF: {$pdf}") : $this->warn('ไม่พบ Edge/Chrome สำหรับสร้าง PDF: เปิดไฟล์ HTML แล้วสั่งพิมพ์เป็น PDF เอง หรือระบุ --browser=');
        }

        return collect($results)->every(fn ($r) => $r['failed'] === 0) ? self::SUCCESS : self::FAILURE;
    }

    /** One virtual user: home → login → each page in turn, all async so every user runs at the same time. */
    private function user(string $base, string $email, string $password, array $pages, int $level): PromiseInterface
    {
        $jar = new CookieJar;
        $client = new Client([
            'handler' => $this->stack ??= HandlerStack::create(new CurlMultiHandler),
            'base_uri' => $base,
            'cookies' => $jar,
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => (int) $this->option('timeout'),
            'headers' => ['Accept' => 'text/html', 'User-Agent' => 'msk-loadtest'],
        ]);

        $promise = $this->timed($level, 'หน้าแรก', fn () => $client->getAsync('/'), fn ($r) => $r->getStatusCode() === 200)
            ->then(fn () => $this->timed($level, 'login', fn () => $client->postAsync('/login', [
                'form_params' => ['email' => $email, 'password' => $password],
                'headers' => ['X-XSRF-TOKEN' => urldecode((string) $jar->getCookieByName('XSRF-TOKEN')?->getValue())],
            ]), fn ($r) => $r->getStatusCode() === 302 && str_contains($r->getHeaderLine('Location'), '/dashboard')));

        foreach ($pages as $page) {
            $promise = $promise->then(fn () => $this->timed($level, $page, fn () => $client->getAsync($page), fn ($r) => $r->getStatusCode() === 200));
        }

        return $promise;
    }

    /** Times one request; a failure is recorded (not thrown) so the user carries on like a real one would retry. */
    private function timed(int $level, string $step, callable $send, callable $isOk): PromiseInterface
    {
        $t = microtime(true);

        return $send()->then(
            function (ResponseInterface $r) use ($level, $step, $t, $isOk) {
                $ok = $isOk($r);
                $this->samples[] = ['level' => $level, 'step' => $step, 'ms' => (microtime(true) - $t) * 1000, 'ok' => $ok, 'note' => $ok ? '' : 'HTTP '.$r->getStatusCode()];

                return $r;
            },
            function ($e) use ($level, $step, $t) {
                $this->samples[] = ['level' => $level, 'step' => $step, 'ms' => (microtime(true) - $t) * 1000, 'ok' => false, 'note' => $e instanceof TransferException ? class_basename($e) : 'error'];

                return null;
            },
        );
    }

    private function summarise(int $level, float $seconds): array
    {
        $s = collect($this->samples)->where('level', $level);
        $ms = $s->pluck('ms')->sort()->values();
        $p95 = $ms->isEmpty() ? 0 : $ms[(int) floor(0.95 * ($ms->count() - 1))];

        return [
            'users' => $level,
            'requests' => $s->count(),
            'failed' => $s->where('ok', false)->count(),
            'logins' => $s->where('step', 'login')->where('ok', true)->count().'/'.$level,
            'avg' => round($ms->avg() / 1000, 2),
            'p95' => round($p95 / 1000, 2),
            'max' => round(($ms->max() ?? 0) / 1000, 2),
            'seconds' => round($seconds, 1),
            'rps' => round($s->count() / max($seconds, 0.001), 1),
        ];
    }

    private function report(string $base, array $headers, array $results, array $pages): string
    {
        $lines = [
            '# รายงานทดสอบผู้ใช้งานพร้อมกัน (TOR 4.4-3, คุณลักษณะ 2.1-3)',
            '',
            '- เว็บที่ทดสอบ: '.$base,
            '- วันเวลา: '.now()->format('d/m/').(now()->year + 543).now()->format(' H:i').' น.',
            '- วิธีทดสอบ: ผู้ใช้จำลองแต่ละคนมี session ของตัวเอง เริ่มพร้อมกัน เปิดหน้าแรก → เข้าสู่ระบบด้วยบัญชีทดสอบของตน → เปิดหน้า '.implode(', ', $pages).' ตามลำดับ',
            '',
            '| '.implode(' | ', $headers).' |',
            '|'.str_repeat(' --- |', count($headers)),
            ...array_map(fn ($r) => '| '.implode(' | ', $r).' |', $results),
            '',
            '## เวลาเฉลี่ยรายหน้า (วินาที)',
            '',
        ];
        $steps = collect($this->samples)->pluck('step')->unique()->values();
        $lines[] = '| หน้า | '.implode(' | ', array_map(fn ($r) => "{$r['users']} ผู้ใช้", $results)).' |';
        $lines[] = '|'.str_repeat(' --- |', count($results) + 1);
        foreach ($steps as $step) {
            $lines[] = "| {$step} | ".implode(' | ', array_map(fn ($r) => round(collect($this->samples)->where('level', $r['users'])->where('step', $step)->avg('ms') / 1000, 2), $results)).' |';
        }
        $failures = collect($this->samples)->where('ok', false)->groupBy(fn ($s) => "{$s['level']} ผู้ใช้ · {$s['step']} · {$s['note']}")->map->count();
        if ($failures->isNotEmpty()) {
            $lines = [...$lines, '', '## คำขอที่ล้มเหลว', '', ...$failures->map(fn ($n, $k) => "- {$k}: {$n} ครั้ง")->values()];
        }

        $path = 'loadtest/'.now()->format('Ymd_His').'.md';
        Storage::put($path, implode("\n", $lines)."\n");

        return Storage::path($path);
    }

    /** Thai names for the default pages; anything else is shown as its path. */
    private const PAGE_LABELS = [
        '/executive' => 'แดชบอร์ด', '/risk-overview' => 'ภาพรวมความเสี่ยง', '/community-map' => 'แผนที่รายชุมชน',
        '/risk-factors' => 'ปัจจัยความเสี่ยง', '/impact-recovery' => 'ผลกระทบและการฟื้นตัว', '/mcr2030-kpi' => 'MCR2030 & KPI',
        '/year-comparison' => 'เปรียบเทียบรายปี', '/dashboard' => 'เกี่ยวกับระบบ',
    ];

    private function htmlReport(string $base, array $results, array $pages, string $file): string
    {
        $required = (int) $this->option('required');
        $requiredRow = collect($results)->firstWhere('users', $required);
        $label = fn (string $step) => self::PAGE_LABELS[$step] ?? ($step === 'login' ? 'เข้าสู่ระบบ' : $step);
        $samples = collect($this->samples);
        $perPage = $samples->pluck('step')->unique()->values()->map(fn ($step) => [
            'label' => $label($step),
            'values' => array_map(fn ($r) => round($samples->where('level', $r['users'])->where('step', $step)->avg('ms') / 1000, 2), $results),
        ])->all();

        $html = view('reports.loadtest', [
            'base' => $base,
            'testedAt' => now()->format('j/n/').(now()->year + 543).now()->format(' H:i').' น.',
            'results' => $results,
            'required' => $required,
            'requiredRow' => $requiredRow,
            // Pass = the TOR level was tested, every user logged in, and not one request failed.
            'pass' => $requiredRow !== null && $requiredRow['failed'] === 0 && $requiredRow['logins'] === "{$required}/{$required}",
            'pageLabels' => array_map($label, $pages),
            'perPage' => $perPage,
            'maxPage' => collect($perPage)->flatMap(fn ($r) => $r['values'])->max() ?? 0,
            'failures' => $samples->where('ok', false)->groupBy(fn ($s) => "{$s['level']} ผู้ใช้ · {$label($s['step'])} · {$s['note']}")->map->count()->all(),
            'pause' => (int) $this->option('pause'),
            'timeout' => (int) $this->option('timeout'),
        ])->render();
        file_put_contents($file, $html);

        return $file;
    }

    /** Prints the HTML report to PDF with headless Edge/Chrome; null when no browser is found or printing fails. */
    private function printPdf(string $html): ?string
    {
        $browser = $this->option('browser') ?: collect([
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/usr/bin/microsoft-edge', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
        ])->first(fn ($p) => is_file($p));
        if (! $browser) {
            return null;
        }
        $pdf = preg_replace('/\.html$/', '.pdf', $html);
        $url = 'file:///'.ltrim(str_replace('\\', '/', $html), '/');
        // virtual-time-budget lets the Anuphan web font load before printing (falls back to a Thai system font offline).
        Process::timeout(120)->run([
            $browser, '--headless', '--disable-gpu', '--no-pdf-header-footer', '--virtual-time-budget=8000',
            '--print-to-pdf='.$pdf, $url,
        ]);

        return is_file($pdf) && filesize($pdf) > 0 ? $pdf : null;
    }
}
