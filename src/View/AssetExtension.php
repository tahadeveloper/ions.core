<?php

declare(strict_types=1);

namespace Ions\View;

use Ions\Bundles\Logs;
use Ions\Bundles\Path;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig asset helpers (Phase 9.5): `vite()` and `asset()`.
 *
 * Registered on every Environment built by ViewFactory, so both functions
 * are available host-wide with zero configuration.
 *
 * - vite('resources/js/app.js') — emits the tags for a Vite entry. When a
 *   dev server is running (presence of the Laravel-style `public/hot` file,
 *   written by the inline plugin scaffolded by `install:vue`, containing an
 *   http(s) origin) it emits dev-server URLs plus the HMR client; otherwise
 *   it resolves the entry through `public/build/manifest.json`, falling back
 *   to Vite >=5's default `public/build/.vite/manifest.json` (CSS links
 *   first, then the module script). Manifests are read once per instance.
 *   Failure modes NEVER throw — a missing build must not 500 the page —
 *   they return an HTML comment and log a warning to view.log.
 * - asset('css/app.css') — app_url-based URL for a file under public/ with
 *   a `?v=filemtime` cache-buster when the file exists (no mtime probe for
 *   paths containing '..').
 */
final class AssetExtension extends AbstractExtension
{
    /**
     * Decoded manifests memoized per instance, keyed by absolute path —
     * one read+decode per request even when a layout calls vite() many times.
     *
     * @var array<string, array<mixed>>
     */
    private array $manifests = [];

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            // vite() returns markup and must bypass autoescape.
            new TwigFunction('vite', $this->vite(...), ['is_safe' => ['html']]),
            // asset() returns a plain URL string and stays auto-escaped.
            new TwigFunction('asset', $this->asset(...)),
        ];
    }

    /**
     * Render the script/link tags for a Vite entry (hot or manifest mode).
     */
    public function vite(string $entry): string
    {
        $hot = Path::public('hot');
        if (is_file($hot)) {
            $origin = rtrim(trim((string) file_get_contents($hot)), '/');

            // Defense-in-depth: only an http(s) origin may redirect script
            // URLs — anything else (garbage, javascript:, file paths) is
            // treated as no-hot and falls through to manifest mode.
            if (preg_match('#^https?://#i', $origin) === 1) {
                return $this->script($origin . '/@vite/client')
                    . $this->script($origin . '/' . ltrim($entry, '/'));
            }

            Logs::create('view.log')->warning(
                'vite(): ignoring ' . $hot . ' — contents must start with http:// or https://.'
            );
        }

        // The scaffolded config pins `manifest: 'manifest.json'` here; hand-rolled
        // host configs with `manifest: true` land at Vite >=5's .vite/ default.
        $manifestPath = Path::public('build' . DIRECTORY_SEPARATOR . 'manifest.json');
        if (!is_file($manifestPath)) {
            $fallback = Path::public(
                'build' . DIRECTORY_SEPARATOR . '.vite' . DIRECTORY_SEPARATOR . 'manifest.json'
            );
            if (is_file($fallback)) {
                $manifestPath = $fallback;
            } else {
                Logs::create('view.log')->warning(
                    'vite(): manifest not found at ' . $manifestPath . ' — run `npm run build`.'
                );

                return '<!-- vite: manifest not found; run npm run build -->';
            }
        }

        $manifest = $this->manifest($manifestPath);
        $chunk = $manifest[$entry] ?? null;

        if (!is_array($chunk) || !isset($chunk['file']) || !is_string($chunk['file'])) {
            Logs::create('view.log')->warning(
                'vite(): entry "' . $entry . '" not found in ' . $manifestPath . ' — run `npm run build`.'
            );

            return '<!-- vite: entry "' . $this->escape($entry) . '" not in manifest; run npm run build -->';
        }

        $base = $this->baseUrl();
        $html = '';

        $css = $chunk['css'] ?? [];
        foreach (is_array($css) ? $css : [] as $stylesheet) {
            if (is_string($stylesheet)) {
                $html .= '<link rel="stylesheet" href="' . $this->escape($base . '/build/' . $stylesheet) . '">' . "\n";
            }
        }

        return $html . $this->script($base . '/build/' . $chunk['file']);
    }

    /**
     * app_url-based URL for a file under public/, cache-busted by filemtime
     * when the file exists (missing files just get no buster — never throws).
     */
    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $url = $this->baseUrl() . '/' . $path;

        // '..' segments would probe mtimes outside public/ — skip the buster.
        if (!str_contains($path, '..')) {
            $file = Path::public($path);
            if (is_file($file)) {
                $mtime = filemtime($file);
                if ($mtime !== false) {
                    $url .= '?v=' . $mtime;
                }
            }
        }

        return $url;
    }

    /**
     * Read + decode a manifest once per instance (invalid JSON decodes to []).
     *
     * @return array<mixed>
     */
    private function manifest(string $path): array
    {
        if (!array_key_exists($path, $this->manifests)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->manifests[$path] = is_array($decoded) ? $decoded : [];
        }

        return $this->manifests[$path];
    }

    private function baseUrl(): string
    {
        $base = config('app.app_url', '');

        return rtrim(is_string($base) ? $base : '', '/');
    }

    private function script(string $src): string
    {
        return '<script type="module" src="' . $this->escape($src) . '"></script>' . "\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
