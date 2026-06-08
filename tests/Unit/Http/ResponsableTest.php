<?php

use Ions\Http\Responsable;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

test('a Responsable returns a Response from toResponse', function () {
    $obj = new class () implements Responsable {
        public function toResponse(Request $request): Response
        {
            return new Response('from-responsable', 200);
        }
    };
    expect($obj->toResponse(Request::create('/')))->toBeInstanceOf(Response::class)
        ->and($obj->toResponse(Request::create('/'))->getContent())->toBe('from-responsable');
});
