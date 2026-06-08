<?php

use Ions\Bundles\Route;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

Route::get('/ping', function (Request $request) {
    return new Response('pong');
});
