<?php

declare(strict_types=1);

namespace Ions\Auth;

use Closure;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Container\Container;
use Ions\Foundation\Kernel;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Authorization gate (Phase 10.4) — abilities and policies, Laravel-style.
 *
 * Bound by AuthProvider as the lazy 'gate' singleton (class alias
 * Ions\Auth\Gate). Hosts define abilities/policies in any service
 * provider's boot() — conventionally app/Providers/AuthServiceProvider,
 * which provider auto-discovery picks up with zero config:
 *
 *     $gate = $this->container->get('gate');
 *     $gate->define('edit-settings', fn (?Authenticatable $user) => ...);
 *     $gate->policy(Post::class, PostPolicy::class);
 *
 * Checks: allows()/denies()/authorize() (403 on deny), the global can()
 * helper and the Twig can() function. The current user is resolved lazily
 * per check from the request's 'auth_user' attribute (set by AuthMiddleware
 * when a UserProvider is configured) — so policies only ever see a user on
 * routes that went through the auth pipeline with a UserProvider; everywhere
 * else the caller is a guest. forUser() returns a scoped clone for checking
 * a specific user (or null for an explicit guest).
 *
 * Guest semantics (Laravel parity): when no user is available, an ability
 * callback / policy method only runs if its first ($user) parameter accepts
 * null — a nullable type or a null default. Otherwise the check auto-denies.
 */
class Gate
{
    /** @var array<string, callable> ability name => callback($user, ...$args) */
    private array $abilities = [];

    /** @var array<class-string, class-string> model class => policy class */
    private array $policies = [];

    /** Scoped user set by forUser(); only honoured when $userScoped is true. */
    private ?Authenticatable $user = null;

    /** Whether this instance is a forUser() clone (its $user wins over the request). */
    private bool $userScoped = false;

    public function __construct(private readonly ?Container $container = null)
    {
    }

    /**
     * Register an ability. The callback receives the current user first
     * (null for guests when its signature allows it), then the arguments
     * passed to allows()/denies()/authorize().
     */
    public function define(string $ability, callable $check): void
    {
        $this->abilities[$ability] = $check;
    }

    /**
     * Map a model class to a policy class. Ability names resolve to policy
     * methods receiving ($user, $model, ...$extra). Subclasses of the model
     * resolve their parent's policy.
     *
     * @param class-string $modelClass
     * @param class-string $policyClass
     */
    public function policy(string $modelClass, string $policyClass): void
    {
        $this->policies[$modelClass] = $policyClass;
    }

    /**
     * Whether the current (or forUser-scoped) user is allowed the ability.
     * Resolution order: explicit ability first, then the policy registered
     * for the first argument (object instance or class-string). An unknown
     * ability or missing policy method denies — it never throws.
     */
    public function allows(string $ability, mixed ...$args): bool
    {
        $user = $this->currentUser();

        $check = $this->abilities[$ability] ?? null;
        if ($check !== null) {
            $closure = Closure::fromCallable($check);

            return $this->invokeAuthorizer(new ReflectionFunction($closure), $closure, $user, $args);
        }

        $policy = $this->resolvePolicyFor($args[0] ?? null);
        if ($policy === null || !method_exists($policy, $ability)) {
            return false;
        }

        // Deny (never throw) on non-public policy methods, and normalize the
        // case-insensitivity of method_exists: the declared method name must
        // match the ability exactly.
        $method = new ReflectionMethod($policy, $ability);
        if (!$method->isPublic() || $method->getName() !== $ability) {
            return false;
        }

        return $this->invokeAuthorizer(
            $method,
            fn (mixed ...$callArgs): mixed => $policy->{$ability}(...$callArgs),
            $user,
            $args,
        );
    }

    /** Inverse of allows(). */
    public function denies(string $ability, mixed ...$args): bool
    {
        return !$this->allows($ability, ...$args);
    }

    /**
     * Like allows(), but a denial halts the request with 403 (the same
     * HttpException abort(403, ...) throws — rendered as JSON on api
     * routes and HTML on web routes).
     */
    public function authorize(string $ability, mixed ...$args): void
    {
        if ($this->denies($ability, ...$args)) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
    }

    /**
     * A clone of this gate scoped to the given user (null = explicit guest).
     * Definitions added to the ORIGINAL gate after cloning are not seen by
     * the clone — use it immediately for a check, not as a long-lived gate.
     */
    public function forUser(?Authenticatable $user): static
    {
        $clone = clone $this;
        $clone->user = $user;
        $clone->userScoped = true;

        return $clone;
    }

    /**
     * The user the check runs against: the forUser() scope when set,
     * otherwise the request's 'auth_user' attribute (AuthMiddleware with a
     * configured UserProvider), otherwise guest. Never throws — an unbooted
     * kernel or attribute-less request is simply a guest.
     */
    private function currentUser(): ?Authenticatable
    {
        if ($this->userScoped) {
            return $this->user;
        }

        try {
            $user = Kernel::request()->attributes->get('auth_user');
        } catch (Throwable) {
            return null;
        }

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Run an authorizer (ability callback or policy method) with the guest
     * gate applied: a null user only reaches the callable when its first
     * parameter accepts null.
     *
     * @param array<array-key, mixed> $args
     */
    private function invokeAuthorizer(ReflectionFunctionAbstract $reflection, callable $invoke, ?Authenticatable $user, array $args): bool
    {
        if ($user === null && !$this->allowsGuests($reflection)) {
            return false;
        }

        return (bool) $invoke($user, ...$args);
    }

    /**
     * Laravel guest semantics: the first parameter must exist and either be
     * nullable-typed or default to null. No parameters / untyped without a
     * default ⇒ guests are denied before the callable runs.
     */
    private function allowsGuests(ReflectionFunctionAbstract $reflection): bool
    {
        $parameters = $reflection->getParameters();
        if ($parameters === []) {
            return false;
        }

        $first = $parameters[0];
        $type = $first->getType();
        if ($type !== null && $type->allowsNull()) {
            return true;
        }

        try {
            return $first->isDefaultValueAvailable() && $first->getDefaultValue() === null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The policy instance for a checked subject: an object's class (or a
     * class-string subject) looked up in the registered policies, walking up
     * parent classes. Instantiated through the container when available so
     * policy constructor dependencies resolve.
     */
    private function resolvePolicyFor(mixed $subject): ?object
    {
        if (is_object($subject)) {
            $class = $subject::class;
        } elseif (is_string($subject) && class_exists($subject)) {
            $class = $subject;
        } else {
            return null;
        }

        $policyClass = $this->policies[$class] ?? null;
        if ($policyClass === null) {
            foreach (class_parents($class) ?: [] as $parent) {
                if (isset($this->policies[$parent])) {
                    $policyClass = $this->policies[$parent];
                    break;
                }
            }
        }

        if ($policyClass === null) {
            return null;
        }

        if ($this->container !== null) {
            $policy = $this->container->make($policyClass);

            return is_object($policy) ? $policy : null;
        }

        return new $policyClass();
    }
}
