<?php

declare(strict_types=1);

namespace Ions\Http\Ui;

/**
 * Builds an "open in editor" URL for a file:line from `config('app.debug.editor')`
 * (Phase 14.1).
 *
 * Consumed by the debug page (per-frame "open in editor" links). Maps the known
 * editor keys to their URL schemes; a config value that itself contains the
 * `{file}`/`{line}` placeholders is treated as a custom format string. Returns
 * null when the editor is unset / false / empty — links are opt-in, never
 * fabricated.
 *
 * The file path is rawurlencoded per segment (preserving the leading-slash
 * structure) so spaces and other reserved characters produce a valid URI.
 */
final class EditorLink
{
    /**
     * Known editor key → URL template using {file} / {line} placeholders.
     *
     * Note: with an absolute unix path the vscode template yields a deliberate
     * double slash (`vscode://file//Users/...`) — `{file}` keeps its leading
     * `/` after `vscode://file/`. VS Code accepts this and it is the form its
     * own "Copy as path"/handlers expect, so it is intentional.
     */
    private const EDITORS = [
        'vscode' => 'vscode://file/{file}:{line}',
        'phpstorm' => 'phpstorm://open?file={file}&line={line}',
        'sublime' => 'subl://open?url=file://{file}&line={line}',
        'textmate' => 'txmt://open?url=file://{file}&line={line}',
        'idea' => 'idea://open?file={file}&line={line}',
    ];

    public static function for(string $file, int $line): ?string
    {
        $editor = config('app.debug.editor');

        if (!is_string($editor) || $editor === '') {
            return null;
        }

        if (str_contains($editor, '{file}') || str_contains($editor, '{line}')) {
            $template = $editor;
        } elseif (isset(self::EDITORS[$editor])) {
            $template = self::EDITORS[$editor];
        } else {
            return null;
        }

        return strtr($template, [
            '{file}' => self::encodePath($file),
            '{line}' => (string) $line,
        ]);
    }

    /**
     * rawurlencode each path segment so spaces become %20 while the slash
     * separators (and any leading slash) survive — yielding a valid file URI.
     */
    private static function encodePath(string $file): string
    {
        $segments = explode('/', $file);

        return implode('/', array_map(rawurlencode(...), $segments));
    }
}
