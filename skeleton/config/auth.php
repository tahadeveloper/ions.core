<?php

declare(strict_types=1);

return [
    // User provider backing the JWT auth endpoints (login/refresh/…):
    //   'sentinel' — Cartalyst Sentinel users table (default)
    //   'eloquent' — plain users table via EloquentUserProvider
    //   FQCN      — your own Ions\Auth\Contracts\UserProvider implementation
    'provider' => 'sentinel',

    // EloquentUserProvider column mapping (used when provider = 'eloquent'):
    // 'table'      => 'users',
    // 'identifier' => 'email',
    // 'password'   => 'password',
    // 'id'         => 'id',
];
