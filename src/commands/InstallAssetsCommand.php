<?php

declare(strict_types=1);

use Ions\Console\InstallerCommand;

/**
 * install:assets — the no-build variant of install:vue: writes plain CSS/JS
 * starters straight into public/assets/ (committed assets, no node, no
 * bundler, no .gitignore entries). Pairs with the `asset()` Twig function
 * for app_url-based URLs with filemtime cache-busting.
 */
class InstallAssetsCommand extends InstallerCommand
{
    protected $signature = 'install:assets {--force : Overwrite existing files}';

    protected $description = 'Scaffold plain CSS/JS starters into public/assets (no build step)';

    protected function files(): array
    {
        return [
            'public/assets/css/app.css' => $this->stub('app.css.stub'),
            'public/assets/js/app.js' => $this->stub('starter.js.stub'),
        ];
    }

    protected function afterInstall(): void
    {
        $this->line('');
        $this->line('Link them from a Twig layout:');
        $this->line("  <link rel=\"stylesheet\" href=\"{{ asset('assets/css/app.css') }}\">");
        $this->line("  <script src=\"{{ asset('assets/js/app.js') }}\" defer></script>");
    }
}
