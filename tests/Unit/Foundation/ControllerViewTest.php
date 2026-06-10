<?php

declare(strict_types=1);

use Ions\Foundation\ApiController;
use Ions\Foundation\BaseController;
use Ions\View\View;

/*
|--------------------------------------------------------------------------
| BaseController::view() + viewFolder() derivation (Phase 9.2)
|--------------------------------------------------------------------------
| Spec rule: the view folder is the controller's directory path under
| Http\Controllers (folders only, kebab-cased; the class name is dropped
| for nested controllers). A root-level controller maps to its own short
| name minus the 'Controller' suffix. $viewPath overrides derivation.
*/

beforeEach(fn () => bootFixtureKernel());

function exposedController(): BaseController
{
    return new class () extends BaseController {
        public function folderFor(string $class): string
        {
            return $this->viewFolder($class);
        }

        public function viewFor(string $name, array $data = []): View
        {
            return $this->view($name, $data);
        }
    };
}

test('viewFolder() derives the folder from the FQCN', function (string $class, string $expected) {
    expect(exposedController()->folderFor($class))->toBe($expected);
})->with([
    'root controller -> short name minus Controller' => ['App\Http\Controllers\UsersController', 'users'],
    'root controller is kebab-cased' => ['App\Http\Controllers\UserSettingsController', 'user-settings'],
    'nested controller -> folders only, class name dropped' => ['App\Http\Controllers\Users\HomeController', 'users'],
    'deep nesting joins kebab-cased dirs' => ['App\Http\Controllers\Admin\UserReports\HomeController', 'admin/user-reports'],
    'no Http\Controllers marker falls back to the root rule' => ['Acme\WeirdController', 'weird'],
]);

test('$viewPath overrides folder derivation', function () {
    $controller = new class () extends BaseController {
        protected string $viewPath = 'custom/place';

        public function viewFor(string $name): View
        {
            return $this->view($name);
        }
    };

    expect($controller->viewFor('index')->template)->toBe('custom/place/index.twig');
});

test('view() composes folder + name and forwards the data', function () {
    $view = exposedController()->viewFor('@admin.users.index', ['who' => 'unit']);

    // A namespaced name bypasses the controller folder entirely.
    expect($view->template)->toBe('@admin/users/index.twig')
        ->and($view->data)->toBe(['who' => 'unit']);
});

test('ApiController does NOT get view() — APIs return JSON', function () {
    expect(method_exists(ApiController::class, 'view'))->toBeFalse();
});
