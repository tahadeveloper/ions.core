<?php

declare(strict_types=1);

namespace Ions\Queue;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Ions\Bundles\Logs;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Minimal {@see ExceptionHandler} for the queue worker.
 *
 * The Illuminate queue Worker reports job failures through an ExceptionHandler.
 * The framework does not ship a full HTTP-style handler in the console context,
 * so this logs failures to var/logs/queue.log and renders them to the console,
 * which is all the worker needs.
 */
final class ConsoleExceptionHandler implements ExceptionHandler
{
    public function report(Throwable $e): void
    {
        Logs::create('queue.log')->error($e->getMessage(), ['exception' => $e]);
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    /**
     * Not used in the console/worker context, but required by the contract.
     *
     * @param mixed $request
     */
    public function render($request, Throwable $e): Response
    {
        return new Response($e->getMessage(), 500);
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function renderForConsole($output, Throwable $e): void
    {
        $output->writeln('<error>' . $e->getMessage() . '</error>');
    }
}
