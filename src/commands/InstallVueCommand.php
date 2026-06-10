<?php

declare(strict_types=1);

use Ions\Console\InstallerCommand;
use Ions\Support\Str;

/**
 * install:vue — scaffold a Vue 3 + Vite frontend into the host root:
 * package.json (dev/build scripts, vue/vite/@vitejs/plugin-vue), a
 * vite.config.js that builds to public/build with a manifest AND writes the
 * Laravel-style public/hot dev-server file via a small inline plugin (no
 * laravel-vite-plugin dependency), plus resources/js/app.js + App.vue.
 *
 * Pairs with the `vite()` Twig function (Ions\View\AssetExtension):
 * hot file present → dev-server tags; otherwise manifest-resolved tags.
 */
class InstallVueCommand extends InstallerCommand
{
    protected $signature = 'install:vue {--force : Overwrite existing files}';

    protected $description = 'Scaffold a Vue 3 + Vite frontend (package.json, vite.config.js, resources/js) into the host';

    protected function files(): array
    {
        $name = Str::slug((string) config('app.name', ''));

        return [
            'package.json' => str_replace(
                '{{ name }}',
                $name !== '' ? $name : 'ions-app',
                $this->stub('package.json.stub')
            ),
            'vite.config.js' => $this->stub('vite.config.js.stub'),
            'resources/js/app.js' => $this->stub('app.js.stub'),
            'resources/js/App.vue' => $this->stub('App.vue.stub'),
        ];
    }

    protected function afterInstall(): void
    {
        $this->appendGitignore(['node_modules/', 'public/build/', 'public/hot']);

        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. npm install');
        $this->line('  2. npm run dev (or npm run build for production)');
        $this->line("  3. Add {{ vite('resources/js/app.js') }} and <div id=\"app\"></div> to your layout.");
    }
}
