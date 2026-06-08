<?php

use Ions\Bundles\Route;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

Route::get('/api/secret', function (Request $request) {
    // auth_user_id is set by AuthMiddleware before this runs
    return new Response('secret for ' . $request->attributes->get('auth_user_id'));
});
