<?php

namespace App\Backup;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * สำรองข้อมูล (TOR 4.4-5, คุณลักษณะ 2.1-5). Written for shared hosting, where `mysqldump` and long-running
 * processes are often not allowed:
 * - database: a gzip'd SQL dump written by PHP itself (CREATE TABLE + INSERTs). It can be restored with phpMyAdmin
 *   or `mysql`. Temporary tables (cache, sessions, jobs) keep their structure only.
 * - files: a zip of the kept original import files (storage/app/private/imports).
 * Every run is a row in `backups` (the log the TOR asks to show), success or not. After a success, older backups of
 * the same type beyond config('backup.keep') are deleted from disk.
 */
class BackupService
{
    public const DIR = 'backups';

    public static function run(string $type, string $trigger, ?User $user = null): Backup
    {
        @set_time_limit(600);
        $backup = Backup::create([
            'type' => $type, 'trigger' => $trigger, 'status' => 'running', 'user_id' => $user?->id, 'started_at' => now(),
        ]);
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $stamp = now()->format('Ymd-His');

        try {
            $path = match ($type) {
                'database' => self::DIR."/database-{$stamp}.sql.gz",
                'files' => self::DIR."/files-{$stamp}.zip",
                default => throw new RuntimeException("ไม่รู้จักประเภท {$type}"),
            };
            $message = $type === 'database'
                ? self::dumpDatabase($disk->path($path))
                : self::zipImports($disk->path($path));
            $backup->update([
                'status' => 'success', 'path' => $path, 'size_bytes' => $disk->size($path), 'message' => $message, 'finished_at' => now(),
            ]);
            self::prune($type);
        } catch (Throwable $e) {
            if (isset($path) && $disk->exists($path)) {
                $disk->delete($path);
            }
            $backup->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
        }

        activity('backups')->performedOn($backup)->causedBy($user)->event($backup->status === 'success' ? 'backup_created' : 'backup_failed')
            ->withProperties(['details' => ['type' => Backup::TYPES[$type] ?? $type, 'trigger' => $trigger === 'manual' ? 'กดเอง' : 'อัตโนมัติ', 'message' => $backup->message]])
            ->log(($backup->status === 'success' ? 'สำรอง' : 'สำรองไม่สำเร็จ: ').(Backup::TYPES[$type] ?? $type)." #{$backup->id}");

        return $backup->refresh();
    }

    /**
     * @return string a short summary for the log ("12 ตาราง 3,456 แถว")
     */
    private static function dumpDatabase(string $file): string
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();
        $skip = config('backup.skip_data', []);
        $out = gzopen($file, 'wb6');
        if ($out === false) {
            throw new RuntimeException('สร้างไฟล์สำรองไม่ได้');
        }

        $write = fn (string $text) => gzwrite($out, $text);
        $write('-- MSK-Resilience database backup '.now()->toDateTimeString()." ({$driver})\n");
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'));
        } else {
            $tables = array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name');
        }

        $rowCount = 0;
        foreach ($tables as $table) {
            $quoted = $driver === 'sqlite' ? "\"{$table}\"" : "`{$table}`";
            $create = $driver === 'sqlite'
                ? DB::selectOne('SELECT sql FROM sqlite_master WHERE name = ?', [$table])->sql
                : array_values((array) DB::selectOne("SHOW CREATE TABLE {$quoted}"))[1];
            $write("DROP TABLE IF EXISTS {$quoted};\n{$create};\n");
            if (in_array($table, $skip, true)) {
                $write("\n");

                continue;
            }

            $batch = [];
            foreach (DB::table($table)->cursor() as $row) {
                $values = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), (array) $row);
                $batch[] = '('.implode(',', $values).')';
                $rowCount++;
                if (count($batch) === 200) {
                    $write("INSERT INTO {$quoted} VALUES ".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $write("INSERT INTO {$quoted} VALUES ".implode(",\n", $batch).";\n");
            }
            $write("\n");
        }
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        }
        gzclose($out);

        return count($tables).' ตาราง '.number_format($rowCount).' แถว';
    }

    private static function zipImports(string $file): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('เซิร์ฟเวอร์ไม่มีส่วนขยาย zip ของ PHP');
        }
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('สร้างไฟล์ zip ไม่ได้');
        }
        $root = Storage::disk('local')->path('imports');
        $count = 0;
        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $item) {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                // Files still waiting for confirmation are temporary.
                if ($item->isFile() && ! str_starts_with($relative, 'tmp/')) {
                    $zip->addFile($item->getPathname(), 'imports/'.$relative);
                    $count++;
                }
            }
        }
        if ($count === 0) {
            $zip->addFromString('imports/README.txt', 'ยังไม่มีไฟล์นำเข้าต้นฉบับ ณ เวลาที่สำรอง');
        }
        $zip->close();

        return number_format($count).' ไฟล์';
    }

    /**
     * Keep the newest N successful backups of a type on disk; older files are deleted, their log rows stay.
     */
    private static function prune(string $type): void
    {
        $keep = max(1, (int) config("backup.keep.{$type}", 10));
        $old = Backup::where('type', $type)->where('status', 'success')->whereNull('file_deleted_at')
            ->orderByDesc('id')->skip($keep)->take(PHP_INT_MAX)->get();
        foreach ($old as $backup) {
            if ($backup->path) {
                Storage::disk('local')->delete($backup->path);
            }
            $backup->update(['file_deleted_at' => now()]);
        }
    }

    /**
     * Days since the newest successful database backup, or null if there has never been one.
     */
    public static function daysSinceLastDatabaseBackup(): ?int
    {
        $last = Backup::where('type', 'database')->where('status', 'success')->max('finished_at');

        return $last ? (int) floor(now()->diffInDays($last, true)) : null;
    }
}
