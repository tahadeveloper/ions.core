<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    /** @param string[] $protectedMethods */
    public function __construct(
        private CsrfTokenManagerInterface $tokens,
        private string $tokenId = 'web',
        private string $field = '_ion_token',
        private array $protectedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'],
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array(strtoupper($request->getMethod()), $this->protectedMethods, true)) {
            return $next($request);
        }

        $fromField = $request->request->get($this->field);
        $fromHeader = $request->headers->get('X-CSRF-TOKEN', '');

        $value = is_string($fromField) ? $fromField : (is_string($fromHeader) ? $fromHeader : '');

        if ($value === '' || !$this->tokens->isTokenValid(new CsrfToken($this->tokenId, $value))) {
            return new Response('CSRF token mismatch.', 419);
        }

        $this->tokens->removeToken($this->tokenId); // one-time use

        return $next($request);
    }
}
