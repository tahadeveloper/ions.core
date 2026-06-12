<?php

declare(strict_types=1);

return [
    // User provider backing the JWT auth endpoints (login/refresh/…) and the
    // Gate's resolved user:
    //   'sentinel' — Cartalyst Sentinel users table (default)
    //   'eloquent' — plain users table via EloquentUserProvider
    //   FQCN      — your own Ions\Auth\Contracts\UserProvider implementation
    //
    // Taskflow uses its own provider so AuthMiddleware resolves the real
    // App\Models\User model (with relationships + the VerifiesEmail contract),
    // which the Gate/policies and the 'verified' middleware then see directly.
    'provider' => \App\Auth\UserProvider::class,
];
