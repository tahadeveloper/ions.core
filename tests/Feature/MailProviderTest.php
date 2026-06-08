<?php

use Ions\Foundation\Kernel;
use Ions\Providers\MailProvider;
use Symfony\Component\Mailer\Mailer;

beforeEach(function () {
    bootFixtureKernel();
    // valid-format SMTP env so Transport::fromDsn builds (no connection attempted)
    putenv('MAIL_USERNAME=user');
    putenv('MAIL_PASSWORD=pass');
    putenv('MAIL_HOST=localhost');
    putenv('MAIL_PORT=1025');
});

afterEach(function () {
    putenv('MAIL_USERNAME');
    putenv('MAIL_PASSWORD');
    putenv('MAIL_HOST');
    putenv('MAIL_PORT');
});

test('MailProvider binds a Symfony Mailer as the container "mailer" service', function () {
    $app = Kernel::app();
    (new MailProvider($app))->register();
    expect($app->has('mailer'))->toBeTrue()
        ->and($app->get('mailer'))->toBeInstanceOf(Mailer::class);
});

test('the mailer binding is a singleton (same instance)', function () {
    $app = Kernel::app();
    (new MailProvider($app))->register();
    expect($app->get('mailer'))->toBe($app->get('mailer'));
});
