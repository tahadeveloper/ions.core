<?php

declare(strict_types=1);

namespace Ions\Support;

use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class Request extends \Illuminate\Http\Request
{
    /**
     * Whether the request has a Symfony session attached.
     *
     * Illuminate's Request narrows hasSession() to its own SymfonySessionDecorator.
     * The Ions framework attaches a plain Symfony SessionInterface (via the
     * StartSessionMiddleware), so we restore Symfony's broader contract here:
     * any non-null SessionInterface (or uninitialised factory) counts.
     *
     * @param bool $skipIfUninitialized
     */
    public function hasSession(bool $skipIfUninitialized = false): bool
    {
        return $this->session !== null
            && (!$skipIfUninitialized || $this->session instanceof SessionInterface);
    }

    /**
     * Return the attached Symfony session, resolving a factory if one was set.
     *
     * @throws SessionNotFoundException when no session is attached.
     */
    public function getSession(): SessionInterface
    {
        $session = $this->session;
        if (!$session instanceof SessionInterface && $session !== null) {
            $this->setSession($session = $session());
        }

        if ($session === null) {
            throw new SessionNotFoundException('Session has not been set.');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputs(): array
    {
        $input = collect($this->attributes->all())->filter(function ($single, $key) {
            if ($key === '_controller' || $key === '_route' || $key === '_controller_name' || $key === '_method_name') {
                return null;
            }
            return $single;
        })->toArray();

        return $input ?? [];
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function validate(array $rules = [], array $params = []): array
    {
        $params = $this->toArray() + $params;
        return validate($params, $rules);
    }

}
