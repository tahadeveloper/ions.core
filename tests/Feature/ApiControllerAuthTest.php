<?php

use Ions\Foundation\Kernel;
use Ions\Support\Request;

// minimal concrete ApiController subclass for testing construction
class TestApiController extends \Ions\Foundation\ApiController
{
    public function whoami(Request $r): void
    {
        Kernel::response()->setContent((string) $this->exposeUser());
    }
    public function exposeUser(): ?string
    {
        return $this->authUserId();
    }
}

beforeEach(fn () => bootFixtureKernel());

test('ApiController can be constructed WITHOUT an Authorization header (auth is now middleware, not constructor)', function () {
    // no Authorization header on the request — must NOT throw/exit
    $controller = new TestApiController();
    expect($controller)->toBeInstanceOf(\Ions\Foundation\ApiController::class);
});

test('ApiController exposes the auth_user_id set on the request by middleware', function () {
    Kernel::request()->attributes->set('auth_user_id', '123');
    $controller = new TestApiController();
    expect($controller->exposeUser())->toBe('123');
});
