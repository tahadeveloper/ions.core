<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Foundation\Doctor;

/**
 * doctor — diagnose the host application.
 *
 * Runs the {@see Doctor} checks (env keys, APP_KEY length, writable var/,
 * cache states, DB connectivity, PHP extensions, security posture) and
 * renders one row per check plus a summary line. Exits non-zero when any
 * check reports a critical failure (FAIL); warnings never fail the run.
 *
 * --json emits the structured results as JSON for CI pipelines.
 */
class DoctorCommand extends Command
{
    protected $signature = 'doctor {--json : Emit the results as JSON (CI-friendly)}';

    protected $description = 'Diagnose the host app: env, APP_KEY, writable var/, caches, DB, PHP extensions, security posture';

    public function handle(): int
    {
        $results = Doctor::run();
        $summary = Doctor::summary($results);
        $failed = Doctor::hasFailures($results);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checks' => $results,
                'summary' => $summary,
                'ok' => !$failed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->line('Ions Doctor — host application diagnostics');
        $this->newLine();

        $width = max(array_map(static fn (array $r): int => strlen($r['id']), $results));

        foreach ($results as $result) {
            $row = sprintf(
                ' [%s]  %s  %s',
                str_pad(strtoupper($result['status']), 4),
                str_pad($result['id'], $width),
                $result['message']
            );

            match ($result['status']) {
                Doctor::OK => $this->info($row),
                Doctor::WARN => $this->warn($row),
                Doctor::FAIL => $this->error($row),
                default => $this->line($row), // info / skip
            };
        }

        $this->newLine();
        $this->line(sprintf(
            '%d ok, %d warnings, %d failures (%d info, %d skipped)',
            $summary['ok'],
            $summary['warn'],
            $summary['fail'],
            $summary['info'],
            $summary['skip']
        ));

        if ($failed) {
            $this->error('Critical misconfiguration detected — fix the [FAIL] rows above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
