<?php

declare(strict_types=1);

namespace Ions\commands;

use Illuminate\Console\Command;
use Ions\Bundles\Path;

/**
 * serve — run the app on PHP's built-in development server (10.8).
 *
 * Wraps `php -S {host}:{port} -t public` against the host app's public/
 * directory. Development only — never use the built-in server in production.
 *
 * serverCommand() (the argv builder) is the tested surface; handle() merely
 * streams the server process (passthru) until Ctrl-C.
 */
class ServeCommand extends Command
{
    protected $signature = 'serve
        {--host=127.0.0.1 : Host/interface to bind}
        {--port=8000 : Port to bind}';

    protected $description = "Serve the application on PHP's built-in development server";

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (string) $this->option('port');

        $command = $this->serverCommand($host, $port);

        $this->info("Ions development server started: http://{$host}:{$port}");
        $this->line('Press Ctrl-C to stop.');

        passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);

        return (int) $exitCode;
    }

    /**
     * Build the `php -S` argv. Public so tests can assert the exact command
     * line without binding a port.
     *
     * @return list<string>
     */
    public function serverCommand(string $host, string $port): array
    {
        return [PHP_BINARY, '-S', "{$host}:{$port}", '-t', Path::root('public')];
    }
}
