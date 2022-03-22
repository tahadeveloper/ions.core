<?php

namespace Ions\Support;

class Session extends \Symfony\Component\HttpFoundation\Session\Session
{
    public function flash(string $name, mixed $value = null): ?array
    {
        if ($value) {
            $this->getFlashBag()->add($name, $value);
            return null;
        }
        return $this->getFlashBag()->get($name,[]);
    }

}