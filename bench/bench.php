<?php

declare(strict_types=1);

/**
 * Request-handling micro-benchmark for the Ions kernel.
 *
 * NOT part of the test suite (lives outside tests/ on purpose).
 *
 * Usage:
 *   php83 bench/bench.php [fixture-path] [request-path] [iterations]
 *
 * Examples:
 *   php83 bench/bench.php                                   # fixture app, /ping, 200 iterations
 *   php83 bench/bench.php tests/fixtures/app-cache /cached  # cache fixture, controller route
 *
 * It reports:
 *   - boot:    wall time for N cold Kernel::boot() cycles (config capture cost)
 *   - handle:  wall time for N Kernel::handle() iterations after a warmup
 *   - twig:    wall time for N ViewFactory::make() calls (Twig Environment cost)
 */

require __DIR__ . '/../vendor/autoload.php';

use Ions\Http\Middleware\CacheResponseMiddleware;
use Ions\Http\ResponseCache;
use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

$fixture = $argv[1] ?? __DIR__ . '/../tests/fixtures/app';
$path = $argv[2] ?? '/ping';
$n = (int) ($argv[3] ?? 200);
$bootN = 50;

$fixture = realpath($fixture);
if ($fixture === false) {
    fwrite(STDERR, "fixture path not found\n");
    exit(1);
}

$ms = static fn (int $ns): float => $ns / 1e6;

// ---------------------------------------------------------------- boot cycles
Kernel::resetForTesting();
Kernel::boot($fixture); // warm autoload / opcache before timing

$start = hrtime(true);
for ($i = 0; $i < $bootN; $i++) {
    Kernel::resetForTesting();
    Kernel::boot($fixture);
}
$bootTotal = $ms(hrtime(true) - $start);

// ------------------------------------------------- cold first handle (FPM-like)
// Every classic PHP-FPM request pays the boot + first-match cost; this is the
// number the route/config caches target.
$start = hrtime(true);
for ($i = 0; $i < $bootN; $i++) {
    Kernel::resetForTesting();
    Kernel::boot($fixture);
    Kernel::handle(Request::create($path));
}
$coldTotal = $ms(hrtime(true) - $start);

// ---------------------------------------------------------------- handle()
Kernel::resetForTesting();
Kernel::boot($fixture);

for ($i = 0; $i < 20; $i++) { // warmup
    Kernel::handle(Request::create($path));
}

$probe = Kernel::handle(Request::create($path));
printf("probe: GET %s -> %d\n", $path, $probe->getStatusCode());

$start = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    Kernel::handle(Request::create($path));
}
$handleTotal = $ms(hrtime(true) - $start);

// ---------------------------------------------------------------- twig env
$twigTotal = null;
try {
    /** @var \Ions\View\ViewFactory $factory */
    $factory = Kernel::app()->get('view');
    $factory->make(sys_get_temp_dir()); // warm

    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        // No-override call: after the view.env singleton this reuses one Environment.
        $factory->make();
    }
    $twigTotal = $ms(hrtime(true) - $start);
} catch (Throwable $e) {
    printf("twig bench skipped: %s\n", $e->getMessage());
}

// ------------------------------------------------- response cache (12.5)
// Measures the per-request cost of a "render" that is moderately expensive
// (a body built from a loop, simulating a templated page) when served live vs.
// served from the response cache via CacheResponseMiddleware. The render cost
// is deliberately non-trivial so the cache win is visible; the numbers are the
// REAL wall time for N iterations of each.
$cacheUncached = null;
$cacheCached = null;
try {
    Kernel::resetForTesting();
    Kernel::boot($fixture);

    /** @var ResponseCache $rc */
    $rc = Kernel::app()->make(ResponseCache::class);

    // A render that costs something: build a ~50 KB body from a loop.
    $render = static function (): Response {
        $buf = '';
        for ($j = 0; $j < 2000; $j++) {
            $buf .= '<li>row ' . $j . ' ' . sha1((string) $j) . '</li>';
        }
        return new Response('<ul>' . $buf . '</ul>', 200, ['Content-Type' => 'text/html']);
    };

    // Uncached: run the render every iteration (no middleware).
    $render(); // warm
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $render();
    }
    $cacheUncached = $ms(hrtime(true) - $start);

    // Cached: same render behind CacheResponseMiddleware. First call is a MISS
    // (renders + stores), every subsequent call is a HIT (no render).
    $mw = new CacheResponseMiddleware($rc);
    $next = static fn (Request $r): Response => $render();
    $req = Request::create('/bench-cached');
    $probe = $mw->handle($req, $next); // MISS warm
    printf("response-cache probe: X-Ions-Cache=%s\n", (string) $probe->headers->get('X-Ions-Cache'));

    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $mw->handle(Request::create('/bench-cached'), $next);
    }
    $cacheCached = $ms(hrtime(true) - $start);
} catch (Throwable $e) {
    printf("response-cache bench skipped: %s\n", $e->getMessage());
}

printf("fixture: %s  path: %s\n", $fixture, $path);
printf("boot:    N=%-4d total=%9.2f ms  avg=%7.3f ms/boot\n", $bootN, $bootTotal, $bootTotal / $bootN);
printf("cold:    N=%-4d total=%9.2f ms  avg=%7.3f ms/boot+first-request\n", $bootN, $coldTotal, $coldTotal / $bootN);
printf("handle:  N=%-4d total=%9.2f ms  avg=%7.3f ms/request\n", $n, $handleTotal, $handleTotal / $n);
if ($twigTotal !== null) {
    printf("twig:    N=%-4d total=%9.2f ms  avg=%7.3f ms/env\n", $n, $twigTotal, $twigTotal / $n);
}
if ($cacheUncached !== null && $cacheCached !== null) {
    printf("cache-off: N=%-4d total=%9.2f ms  avg=%7.3f ms/render (live)\n", $n, $cacheUncached, $cacheUncached / $n);
    printf("cache-on:  N=%-4d total=%9.2f ms  avg=%7.3f ms/request (HIT)\n", $n, $cacheCached, $cacheCached / $n);
    if ($cacheCached > 0.0) {
        printf("cache speedup: %.1fx faster per cached request\n", $cacheUncached / $cacheCached);
    }
}
