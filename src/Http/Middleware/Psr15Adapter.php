<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as Psr15MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs any PSR-15 middleware inside the Ions middleware pipeline (10.8).
 *
 * Conversion flow per request:
 *
 *   Symfony Request --(PsrHttpFactory)--> PSR-7 ServerRequest
 *     -> vendor PSR-15 middleware ->process($psrRequest, $handler)
 *       -> $handler converts the (possibly mutated) PSR-7 request BACK to a
 *          Symfony request and calls $next — so withAttribute()/withHeader()
 *          changes made by the PSR-15 middleware reach the controller —
 *          then converts $next's Symfony Response to PSR-7 for the
 *          middleware's return-path decoration.
 *   PSR-7 Response --(HttpFoundationFactory)--> Symfony Response
 *
 * The wrapped middleware may be given as an INSTANCE or as a CLASS-STRING;
 * class-strings are resolved through the container at handle time (and fail
 * closed with an InvalidArgumentException when the result is not PSR-15).
 *
 * middleware_aliases ergonomics: aliases are container-made with NO
 * constructor arguments, so hosts wire a vendor PSR-15 middleware by
 * subclassing and pinning it in the constructor:
 *
 *   final class CorsMw extends Psr15Adapter
 *   {
 *       public function __construct() { parent::__construct(VendorCors::class); }
 *   }
 *   // config: 'middleware_aliases' => ['cors' => CorsMw::class]
 *
 * Cost note: every adapted middleware performs a full double conversion
 * (Symfony->PSR-7 on the way in, PSR-7->Symfony on the way out, and the same
 * pair again around $next). That is cheap in absolute terms but not free —
 * prefer native Ions middleware for hot paths and reserve the adapter for
 * reusing existing PSR-15 packages.
 *
 * Fidelity note: the Symfony request handed to $next is REBUILT from the
 * PSR-7 request; the session and locale of the original request are carried
 * over explicitly, but other live Symfony-side state attached to the original
 * request object is not.
 */
class Psr15Adapter implements MiddlewareInterface
{
    /** @param Psr15MiddlewareInterface|class-string $middleware */
    public function __construct(private readonly Psr15MiddlewareInterface|string $middleware)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $middleware = $this->resolve();

        $psr17 = new Psr17Factory();
        $toPsr = new PsrHttpFactory($psr17, $psr17, $psr17, $psr17);
        $toSymfony = new HttpFoundationFactory();

        $handler = new class ($toPsr, $toSymfony, $request, $next) implements RequestHandlerInterface {
            /** @param callable(Request):Response $next */
            public function __construct(
                private readonly PsrHttpFactory $toPsr,
                private readonly HttpFoundationFactory $toSymfony,
                private readonly Request $original,
                private $next,
            ) {
            }

            public function handle(ServerRequestInterface $psrRequest): ResponseInterface
            {
                // Rebuild the Symfony request from the (possibly mutated)
                // PSR-7 one so attribute/header/query changes made by the
                // PSR-15 middleware propagate to the controller. Attributes
                // round-trip through both bridge factories.
                $bridged = Request::createFromBase($this->toSymfony->createRequest($psrRequest));

                // PSR-7 has no session/locale concept — carry them over from
                // the original request so downstream middleware keep working.
                if ($this->original->hasSession()) {
                    $bridged->setSession($this->original->getSession());
                }
                $bridged->setLocale($this->original->getLocale());

                return $this->toPsr->createResponse(($this->next)($bridged));
            }
        };

        return $toSymfony->createResponse(
            $middleware->process($toPsr->createRequest($request), $handler)
        );
    }

    /**
     * Resolve the wrapped middleware: instances pass through; class-strings
     * are container-made at handle time. Fails closed — a non-PSR-15 result
     * throws instead of being silently skipped (resolveMiddleware precedent).
     */
    private function resolve(): Psr15MiddlewareInterface
    {
        $middleware = $this->middleware;

        if (is_string($middleware)) {
            $middleware = Kernel::app()->make($middleware);
        }

        if (!$middleware instanceof Psr15MiddlewareInterface) {
            throw new \InvalidArgumentException(sprintf(
                "Psr15Adapter expects an instance of %s, got '%s'.",
                Psr15MiddlewareInterface::class,
                get_debug_type($middleware),
            ));
        }

        return $middleware;
    }
}
