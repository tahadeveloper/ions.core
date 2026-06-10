<?php

declare(strict_types=1);

namespace Ions\Database;

/**
 * Log-based N+1 query heuristic (debug-only diagnostics).
 *
 * Consumes the bounded Eloquent query log (opt-in via
 * config('database.query_log'); entries shaped
 * ['query' => sql-with-?-placeholders, 'bindings' => [...], 'time' => ms]).
 * Each SELECT is normalized into a shape-pattern — lowercased, whitespace
 * collapsed, string/numeric literals replaced with `?`, `IN (?, ?, …)` lists
 * collapsed to `in (...)` — and any WHERE-carrying pattern repeated
 * >= threshold times within one request is flagged.
 *
 * HEURISTIC + LIMITATION: this works on the query log alone, not on the ORM
 * (cf. Laravel's Model::preventLazyLoading(), which hooks relation loading
 * itself). The log cannot show *where* a query came from — only that the same
 * single-row-shaped SELECT ran many times in one request, which is exactly
 * the N+1 signature. Repeated identical-pattern SELECTs >= threshold IS the
 * signal; expect occasional false positives (intentional loops) and fix real
 * offenders with eager loading (`->with(...)`) or one `WHERE … IN (...)`.
 */
final class NPlusOneDetector
{
    /** Default repetition count that flags a pattern (config: database.nplusone.threshold). */
    public const DEFAULT_THRESHOLD = 5;

    /**
     * Group the query log by normalized SELECT pattern and return every
     * pattern repeated at least $threshold times. Malformed entries are
     * skipped silently — this is diagnostics code and must never throw.
     *
     * @param array<mixed> $queryLog  Illuminate getQueryLog() entries.
     * @param int          $threshold Minimum repetitions to flag (clamped to >= 1).
     * @return list<array{pattern: string, count: int, total_time: float}>
     */
    public static function analyze(array $queryLog, int $threshold = self::DEFAULT_THRESHOLD): array
    {
        $threshold = max(1, $threshold);

        /** @var array<string, array{count: int, total_time: float}> $patterns */
        $patterns = [];

        foreach ($queryLog as $entry) {
            if (!is_array($entry) || !isset($entry['query']) || !is_string($entry['query'])) {
                continue;
            }

            $pattern = self::normalize($entry['query']);
            if (!self::isLookupSelect($pattern)) {
                continue;
            }

            $time = isset($entry['time']) && is_numeric($entry['time']) ? (float) $entry['time'] : 0.0;

            $patterns[$pattern] ??= ['count' => 0, 'total_time' => 0.0];
            $patterns[$pattern]['count']++;
            $patterns[$pattern]['total_time'] += $time;
        }

        $flagged = [];
        foreach ($patterns as $pattern => $stats) {
            if ($stats['count'] >= $threshold) {
                $flagged[] = [
                    'pattern' => $pattern,
                    'count' => $stats['count'],
                    'total_time' => round($stats['total_time'], 2),
                ];
            }
        }

        return $flagged;
    }

    /**
     * Normalize SQL into a comparable shape-pattern: lowercase, collapse
     * whitespace, replace string/numeric literals with `?` (so inlined values
     * group with bound ones) and collapse `IN (?, ?, …)` placeholder lists to
     * `in (...)` (so list size does not split the pattern).
     */
    public static function normalize(string $sql): string
    {
        $sql = strtolower(trim($sql));
        $sql = (string) preg_replace('/\s+/', ' ', $sql);
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql);
        $sql = (string) preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);

        return (string) preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/', 'in (...)', $sql);
    }

    /**
     * A normalized pattern counts toward the N+1 signal when it is a SELECT
     * with a WHERE clause (a per-row lookup shape). Anything else — writes,
     * unconditioned aggregates, schema statements — is ignored.
     */
    private static function isLookupSelect(string $pattern): bool
    {
        return str_starts_with($pattern, 'select') && str_contains($pattern, ' where ');
    }
}
