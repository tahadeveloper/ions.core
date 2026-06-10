<?php

declare(strict_types=1);

test('TestCase::setUp throws a clear exception when $basePath is left unset', function () {
    $case = new class('basePathGuard') extends \Ions\Testing\TestCase {
        public function exerciseSetUp(): void
        {
            $this->setUp();
        }
    };

    expect(fn () => $case->exerciseSetUp())
        ->toThrow(RuntimeException::class, 'basePath');
});

test('TestCase::setUp throws when $basePath points at a missing directory', function () {
    $case = new class('basePathGuard') extends \Ions\Testing\TestCase {
        protected string $basePath = '/definitely/not/a/real/app/root';

        public function exerciseSetUp(): void
        {
            $this->setUp();
        }
    };

    expect(fn () => $case->exerciseSetUp())
        ->toThrow(RuntimeException::class, '/definitely/not/a/real/app/root');
});
