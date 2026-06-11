<?php

use Ions\commands\CacheClearResponsesCommand;
use Ions\Foundation\Kernel;
use Ions\Http\ResponseCache;
use Symfony\Component\HttpFoundation\Response;

beforeEach(fn () => bootFixtureKernel());

test('cache:clear-responses empties stored response-cache entries', function () {
    /** @var ResponseCache $cache */
    $cache = Kernel::app()->make(ResponseCache::class);
    $cache->put('response_cache:abc', new Response('cached', 200), 300);
    expect($cache->get('response_cache:abc'))->not->toBeNull();

    $tester = runConsoleCommand(new CacheClearResponsesCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($cache->get('response_cache:abc'))->toBeNull()
        ->and($tester->getDisplay())->toContain('Response cache cleared');
});
