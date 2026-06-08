<?php

use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Http\Middleware\Pipeline;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

test('runs middleware in order around the terminal and back out', function () {
    $order = [];
    $a = new class ($order) implements MiddlewareInterface {
        public function __construct(public array &$order)
        {
        }
        public function handle(Request $r, callable $next): Response
        {
            $this->order[] = 'a-in';
            $res = $next($r);
            $this->order[] = 'a-out';
            return $res;
        }
    };
    $b = new class ($order) implements MiddlewareInterface {
        public function __construct(public array &$order)
        {
        }
        public function handle(Request $r, callable $next): Response
        {
            $this->order[] = 'b-in';
            $res = $next($r);
            $this->order[] = 'b-out';
            return $res;
        }
    };
    $terminal = function (Request $r) use (&$order) {
        $order[] = 'terminal';
        return new Response('ok');
    };

    $response = (new Pipeline([$a, $b], $terminal))->handle(Request::create('/'));
    expect($response->getContent())->toBe('ok')
        ->and($order)->toBe(['a-in', 'b-in', 'terminal', 'b-out', 'a-out']);
});

test('a middleware can short-circuit without calling next', function () {
    $blocker = new class () implements MiddlewareInterface {
        public function handle(Request $r, callable $next): Response
        {
            return new Response('blocked', 403);
        }
    };
    $reached = false;
    $terminal = function (Request $r) use (&$reached) {
        $reached = true;
        return new Response('should not run');
    };
    $response = (new Pipeline([$blocker], $terminal))->handle(Request::create('/'));
    expect($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->toBe('blocked')
        ->and($reached)->toBeFalse(); // terminal never ran
});

test('an empty middleware list runs just the terminal', function () {
    $terminal = fn (Request $r) => new Response('only-terminal');
    $response = (new Pipeline([], $terminal))->handle(Request::create('/'));
    expect($response->getContent())->toBe('only-terminal');
});

test('a middleware can modify the request seen by the terminal', function () {
    $tagger = new class () implements MiddlewareInterface {
        public function handle(Request $r, callable $next): Response
        {
            $r->attributes->set('tagged', true);
            return $next($r);
        }
    };
    $terminal = fn (Request $r) => new Response($r->attributes->get('tagged') ? 'yes' : 'no');
    $response = (new Pipeline([$tagger], $terminal))->handle(Request::create('/'));
    expect($response->getContent())->toBe('yes');
});
