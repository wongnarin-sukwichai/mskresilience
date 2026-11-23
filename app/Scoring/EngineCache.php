<?php

namespace App\Scoring;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps RiskEngine results between requests (TOR 4.4-3, คุณลักษณะ 2.1-3: 20 users at once on shared hosting).
 * One run takes ~0.1–0.2 s and a page runs it 1–3 times, so 20 people opening pages at once would otherwise queue.
 *
 * - The numbers are the same: a cached value is exactly what run() returned, just not computed twice.
 * - Every write to data the engine (or a later dashboard feature) reads calls flush() through model events
 *   (AppServiceProvider), so nothing is ever shown from before a change. flush() runs again after the surrounding
 *   transaction commits, so a run during an import can't leave a result built from uncommitted rows.
 * - Values are gzip + base64, because one result is ~0.5 MB of serialized PHP and the cache table is a text column
 *   (MySQL max_allowed_packet on shared hosting).
 * - The keys written are kept in an index, so flush() deletes exactly those (the database cache store can't
 *   delete by prefix).
 */
class EngineCache
{
    private const INDEX = 'engine-cache:index';

    public static function enabled(): bool
    {
        return (bool) config('scoring.cache');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $name, Closure $compute): mixed
    {
        if (! self::enabled()) {
            return $compute();
        }

        $key = 'engine-cache:'.md5(self::codeVersion().':'.$name);
        $stored = Cache::get($key);
        if (is_string($stored)) {
            $value = @unserialize(gzuncompress(base64_decode($stored)) ?: '');
            if ($value !== false) {
                return $value;
            }
        }

        $value = $compute();
        Cache::put($key, base64_encode(gzcompress(serialize($value), 6)), config('scoring.cache_ttl'));
        $index = Cache::get(self::INDEX, []);
        if (! in_array($key, $index, true)) {
            $index[] = $key;
            Cache::put(self::INDEX, $index, config('scoring.cache_ttl'));
        }

        return $value;
    }

    /**
     * Changes whenever a file of the engine (app/Scoring) changes, so a deploy with new calculation code never serves
     * results cached by the old code (real bug 2026-11-23: new fields were missing until the data changed).
     */
    private static function codeVersion(): string
    {
        static $version = null;
        if ($version === null) {
            $files = glob(__DIR__.'/*.php') ?: [];
            $version = md5(implode('|', array_map(fn ($f) => basename($f).@filemtime($f).@filesize($f), $files)));
        }

        return $version;
    }

    /**
     * Forget every cached result. Called on any change to the data behind them.
     */
    public static function flush(): void
    {
        self::forgetAll();
        // Inside a transaction (an import, a cancel), flush once more after it commits. DB::afterCommit runs the
        // callback at once when there is no transaction.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => self::forgetAll());
        }
    }

    private static function forgetAll(): void
    {
        $index = Cache::get(self::INDEX);
        if (! $index) {
            return;
        }
        foreach ($index as $key) {
            Cache::forget($key);
        }
        Cache::forget(self::INDEX);
    }
}
