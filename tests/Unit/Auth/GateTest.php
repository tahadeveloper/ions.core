<?php

declare(strict_types=1);

use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Gate;
use Ions\Container\Container;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
|--------------------------------------------------------------------------
| Ions\Auth\Gate — unit coverage (Phase 10.4)
|--------------------------------------------------------------------------
| Pure unit tests: the gate is constructed directly and the user is pinned
| with forUser() (scoped clone), so no kernel/request state is involved.
| The lazy current-user resolution from the request attributes is covered
| end-to-end in tests/Feature/Auth/GatePipelineTest.php.
*/

function gateFixtureUser(string $id = 'user-1'): Authenticatable
{
    return new class ($id) implements Authenticatable {
        public function __construct(private string $id)
        {
        }

        public function getAuthIdentifier(): string|int
        {
            return $this->id;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }
    };
}

// --- ability fixtures (policies) -------------------------------------------

class GateTestArticle
{
    public function __construct(public string $ownerId = 'user-1')
    {
    }
}

final class GateTestPromotedArticle extends GateTestArticle
{
}

final class GateTestArticlePolicy
{
    public function update(Authenticatable $user, GateTestArticle $article): bool
    {
        return (string) $user->getAuthIdentifier() === $article->ownerId;
    }

    /** Guest-friendly: nullable $user reaches the body with null. */
    public function view(?Authenticatable $user, GateTestArticle $article): bool
    {
        return true;
    }

    /** Class-string ability (Laravel 'create' convention): no instance yet. */
    public function create(Authenticatable $user): bool
    {
        return (string) $user->getAuthIdentifier() === 'user-1';
    }

    /** Extra arguments after the model are forwarded verbatim. */
    public function tag(Authenticatable $user, GateTestArticle $article, string $tag): bool
    {
        return $tag === 'php';
    }
}

final class GateTestPolicyWithDependency
{
    public function __construct(public GateTestArticlePolicy $inner)
    {
    }

    public function update(Authenticatable $user, GateTestArticle $article): bool
    {
        return $this->inner->update($user, $article);
    }
}

// --- define / allows / denies ----------------------------------------------

test('a defined ability allows and denies based on the callback result', function () {
    $gate = new Gate();
    $gate->define('edit-settings', fn (Authenticatable $user): bool => $user->getAuthIdentifier() === 'user-1');

    expect($gate->forUser(gateFixtureUser('user-1'))->allows('edit-settings'))->toBeTrue()
        ->and($gate->forUser(gateFixtureUser('user-2'))->allows('edit-settings'))->toBeFalse()
        ->and($gate->forUser(gateFixtureUser('user-2'))->denies('edit-settings'))->toBeTrue();
});

test('extra arguments are forwarded to the ability callback after the user', function () {
    $gate = new Gate();
    $gate->define('edit', fn (Authenticatable $user, string $section): bool => $section === 'profile');

    $scoped = $gate->forUser(gateFixtureUser());

    expect($scoped->allows('edit', 'profile'))->toBeTrue()
        ->and($scoped->allows('edit', 'billing'))->toBeFalse();
});

test('an unknown ability denies instead of throwing', function () {
    $gate = new Gate();

    expect($gate->forUser(gateFixtureUser())->allows('never-defined'))->toBeFalse();
});

// --- authorize ---------------------------------------------------------------

test('authorize passes silently when the ability allows', function () {
    $gate = new Gate();
    $gate->define('pass', fn (?Authenticatable $user): bool => true);

    $gate->forUser(gateFixtureUser())->authorize('pass');

    expect(true)->toBeTrue(); // no exception thrown
});

test('authorize throws a 403 HttpException when the ability denies', function () {
    $gate = new Gate();
    $gate->define('blocked', fn (?Authenticatable $user): bool => false);

    try {
        $gate->forUser(gateFixtureUser())->authorize('blocked');
        $this->fail('authorize() should have thrown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403)
            ->and($e->getMessage())->toBe('This action is unauthorized.');
    }
});

// --- guest semantics ----------------------------------------------------------

test('a guest is auto-denied when the ability $user parameter is NOT nullable', function () {
    $gate = new Gate();
    $gate->define('members-only', fn (Authenticatable $user): bool => true);

    expect($gate->forUser(null)->allows('members-only'))->toBeFalse();
});

test('a guest reaches the callback (as null) when the $user parameter is nullable', function () {
    $received = 'untouched';
    $gate = new Gate();
    $gate->define('open-door', function (?Authenticatable $user) use (&$received): bool {
        $received = $user;

        return true;
    });

    expect($gate->forUser(null)->allows('open-door'))->toBeTrue()
        ->and($received)->toBeNull();
});

test('a guest is allowed through when the $user parameter has a null default', function () {
    $gate = new Gate();
    $gate->define('default-null', fn ($user = null): bool => $user === null);

    expect($gate->forUser(null)->allows('default-null'))->toBeTrue();
});

test('an ability callback without parameters denies guests (Laravel semantics)', function () {
    $gate = new Gate();
    $gate->define('no-params', fn (): bool => true);

    expect($gate->forUser(null)->allows('no-params'))->toBeFalse()
        ->and($gate->forUser(gateFixtureUser())->allows('no-params'))->toBeTrue();
});

// --- forUser scoping ----------------------------------------------------------

test('forUser returns a scoped clone and leaves the original gate untouched', function () {
    $gate = new Gate();
    $gate->define('identify', fn (Authenticatable $user): bool => $user->getAuthIdentifier() === 'user-1');

    $one = $gate->forUser(gateFixtureUser('user-1'));
    $two = $gate->forUser(gateFixtureUser('user-2'));

    expect($one)->not->toBe($gate)
        ->and($one->allows('identify'))->toBeTrue()
        ->and($two->allows('identify'))->toBeFalse();
});

// --- policies ------------------------------------------------------------------

test('a registered policy resolves for a model instance argument', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);

    $mine = new GateTestArticle('user-1');
    $theirs = new GateTestArticle('user-2');

    $scoped = $gate->forUser(gateFixtureUser('user-1'));

    expect($scoped->allows('update', $mine))->toBeTrue()
        ->and($scoped->allows('update', $theirs))->toBeFalse();
});

test('a registered policy resolves for a class-string argument (create convention)', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);

    expect($gate->forUser(gateFixtureUser('user-1'))->allows('create', GateTestArticle::class))->toBeTrue()
        ->and($gate->forUser(gateFixtureUser('user-2'))->allows('create', GateTestArticle::class))->toBeFalse();
});

test('a subclass instance resolves the policy registered for its parent class', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);

    $promoted = new GateTestPromotedArticle('user-1');

    expect($gate->forUser(gateFixtureUser('user-1'))->allows('update', $promoted))->toBeTrue();
});

test('a missing policy method denies instead of throwing', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);

    expect($gate->forUser(gateFixtureUser())->allows('archive', new GateTestArticle()))->toBeFalse();
});

test('explicit abilities win over a registered policy with the same name', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);
    $gate->define('update', fn (?Authenticatable $user, GateTestArticle $article): bool => false);

    // The policy would allow user-1 on their own article; the explicit
    // ability (checked first) denies.
    expect($gate->forUser(gateFixtureUser('user-1'))->allows('update', new GateTestArticle('user-1')))->toBeFalse();
});

test('policy guest semantics mirror ability guest semantics (nullability of $user)', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);

    $guest = $gate->forUser(null);
    $article = new GateTestArticle('user-1');

    // view(?Authenticatable $user, ...) — guests reach the method.
    expect($guest->allows('view', $article))->toBeTrue()
        // update(Authenticatable $user, ...) — guests auto-denied.
        ->and($guest->allows('update', $article))->toBeFalse();
});

test('extra arguments after the model are forwarded to the policy method', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);

    $scoped = $gate->forUser(gateFixtureUser());

    expect($scoped->allows('tag', new GateTestArticle(), 'php'))->toBeTrue()
        ->and($scoped->allows('tag', new GateTestArticle(), 'perl'))->toBeFalse();
});

test('policies are instantiated through the container when one is provided', function () {
    $gate = new Gate(new Container());
    $gate->policy(GateTestArticle::class, GateTestPolicyWithDependency::class);

    // GateTestPolicyWithDependency requires a constructor dependency that
    // only container auto-wiring can satisfy.
    expect($gate->forUser(gateFixtureUser('user-1'))->allows('update', new GateTestArticle('user-1')))->toBeTrue();
});

test('a non-object, non-class argument resolves no policy and denies', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);

    expect($gate->forUser(gateFixtureUser())->allows('update', 'not-a-class'))->toBeFalse()
        ->and($gate->forUser(gateFixtureUser())->allows('update'))->toBeFalse();
});

test('with no request and no forUser scope the gate treats the caller as a guest', function () {
    \Ions\Foundation\Kernel::resetForTesting();

    $gate = new Gate();
    $gate->define('members-only', fn (Authenticatable $user): bool => true);
    $gate->define('open-door', fn (?Authenticatable $user): bool => true);

    expect($gate->allows('members-only'))->toBeFalse()
        ->and($gate->allows('open-door'))->toBeTrue();
});

final class GateTestProtectedMethodPolicy
{
    // Non-public: the gate must DENY, never throw (fail closed).
    protected function peek(?\Ions\Auth\Contracts\Authenticatable $user, GateTestArticle $article): bool
    {
        return true;
    }
}

test('a protected policy method denies instead of throwing', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestProtectedMethodPolicy::class);

    expect($gate->forUser(gateFixtureUser())->allows('peek', new GateTestArticle()))->toBeFalse();
});

test('a wrong-case ability never reaches a policy method (exact-name match)', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestArticlePolicy::class);

    expect($gate->forUser(gateFixtureUser())->allows('View', new GateTestArticle()))->toBeFalse()
        ->and($gate->forUser(gateFixtureUser())->allows('view', new GateTestArticle()))->toBeTrue();
});

final class GateTestUntypedUserPolicy
{
    /** Untyped $user with no default: guests must auto-deny. */
    public function ping($user, GateTestArticle $article): bool
    {
        return true;
    }
}

test('an untyped $user param without a default denies guests', function () {
    $gate = new Gate();
    $gate->policy(GateTestArticle::class, GateTestUntypedUserPolicy::class);

    expect($gate->allows('ping', new GateTestArticle()))->toBeFalse()
        ->and($gate->forUser(gateFixtureUser())->allows('ping', new GateTestArticle()))->toBeTrue();
});
