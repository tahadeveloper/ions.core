<?php

/**
 * PhpStorm advanced metadata for the Ions framework.
 *
 * Teaches the IDE what the container returns for each binding id, so host
 * apps get full type inference and completion for:
 *
 *     app('queue')->push(...);                      // QueueManager
 *     Kernel::app()->get('cache')->store();         // CacheManager
 *     app(SomeService::class);                      // SomeService
 *
 * PhpStorm picks this file up automatically from vendor/ionzile/core — no
 * host-side setup required. The id => type map mirrors the bindings made by
 * src/Providers/*.php and Kernel::boot(); the PhpstormMetaTest suite guards
 * the map against drift.
 *
 * Notes on mapped types:
 *  - 'mailer' and 'csrf' are mapped to the interfaces hosts should program
 *    against (test fakes implement the same interfaces).
 *  - 'jwt' may resolve to null when APP_KEY is missing/short; the map can
 *    only express the non-null type.
 *  - 'revocation_store' and 'user_provider' are host-overridable, so they
 *    map to the framework contracts rather than the default implementations.
 *
 * @see https://www.jetbrains.com/help/phpstorm/ide-advanced-metadata.html
 */

namespace PHPSTORM_META {

    override(\Illuminate\Container\Container::get(0), map([
        '' => '@',
        'cache' => \Illuminate\Cache\CacheManager::class,
        'cache.store' => \Illuminate\Contracts\Cache\Repository::class,
        'config' => \Ions\Foundation\Config::class,
        'csrf' => \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class,
        'db' => \Illuminate\Database\Capsule\Manager::class,
        'db.connection' => \Illuminate\Database\Connection::class,
        'db.schema' => \Illuminate\Database\Schema\Builder::class,
        'events' => \Illuminate\Events\Dispatcher::class,
        'files' => \Illuminate\Filesystem\Filesystem::class,
        'filesystem' => \Illuminate\Filesystem\Filesystem::class,
        'filesystem.disk' => \League\Flysystem\Filesystem::class,
        'filesystem.manager' => \Ions\Filesystem\FilesystemManager::class,
        'jwt' => \Ions\Security\Jwt::class,
        'mailer' => \Symfony\Component\Mailer\MailerInterface::class,
        'queue' => \Illuminate\Queue\QueueManager::class,
        'queue.connection' => \Illuminate\Contracts\Queue\Queue::class,
        'request_stack' => \Symfony\Component\HttpFoundation\RequestStack::class,
        'revocation_store' => \Ions\Security\RevocationStore::class,
        'session' => \Ions\Session\SessionManager::class,
        'user_provider' => \Ions\Auth\Contracts\UserProvider::class,
        'view' => \Ions\View\ViewFactory::class,
        'view.env' => \Twig\Environment::class,
    ]));

    override(\Illuminate\Container\Container::make(0), map([
        '' => '@',
        'cache' => \Illuminate\Cache\CacheManager::class,
        'cache.store' => \Illuminate\Contracts\Cache\Repository::class,
        'config' => \Ions\Foundation\Config::class,
        'csrf' => \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class,
        'db' => \Illuminate\Database\Capsule\Manager::class,
        'db.connection' => \Illuminate\Database\Connection::class,
        'db.schema' => \Illuminate\Database\Schema\Builder::class,
        'events' => \Illuminate\Events\Dispatcher::class,
        'files' => \Illuminate\Filesystem\Filesystem::class,
        'filesystem' => \Illuminate\Filesystem\Filesystem::class,
        'filesystem.disk' => \League\Flysystem\Filesystem::class,
        'filesystem.manager' => \Ions\Filesystem\FilesystemManager::class,
        'jwt' => \Ions\Security\Jwt::class,
        'mailer' => \Symfony\Component\Mailer\MailerInterface::class,
        'queue' => \Illuminate\Queue\QueueManager::class,
        'queue.connection' => \Illuminate\Contracts\Queue\Queue::class,
        'request_stack' => \Symfony\Component\HttpFoundation\RequestStack::class,
        'revocation_store' => \Ions\Security\RevocationStore::class,
        'session' => \Ions\Session\SessionManager::class,
        'user_provider' => \Ions\Auth\Contracts\UserProvider::class,
        'view' => \Ions\View\ViewFactory::class,
        'view.env' => \Twig\Environment::class,
    ]));

    override(\app(0), map([
        '' => '@',
        'cache' => \Illuminate\Cache\CacheManager::class,
        'cache.store' => \Illuminate\Contracts\Cache\Repository::class,
        'config' => \Ions\Foundation\Config::class,
        'csrf' => \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class,
        'db' => \Illuminate\Database\Capsule\Manager::class,
        'db.connection' => \Illuminate\Database\Connection::class,
        'db.schema' => \Illuminate\Database\Schema\Builder::class,
        'events' => \Illuminate\Events\Dispatcher::class,
        'files' => \Illuminate\Filesystem\Filesystem::class,
        'filesystem' => \Illuminate\Filesystem\Filesystem::class,
        'filesystem.disk' => \League\Flysystem\Filesystem::class,
        'filesystem.manager' => \Ions\Filesystem\FilesystemManager::class,
        'jwt' => \Ions\Security\Jwt::class,
        'mailer' => \Symfony\Component\Mailer\MailerInterface::class,
        'queue' => \Illuminate\Queue\QueueManager::class,
        'queue.connection' => \Illuminate\Contracts\Queue\Queue::class,
        'request_stack' => \Symfony\Component\HttpFoundation\RequestStack::class,
        'revocation_store' => \Ions\Security\RevocationStore::class,
        'session' => \Ions\Session\SessionManager::class,
        'user_provider' => \Ions\Auth\Contracts\UserProvider::class,
        'view' => \Ions\View\ViewFactory::class,
        'view.env' => \Twig\Environment::class,
    ]));

}
