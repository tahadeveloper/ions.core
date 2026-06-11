<?php

declare(strict_types=1);

namespace Ions\Security;

final class UploadValidator
{
    /** Extensions that must never be accepted regardless of the allow-list. */
    private const DENY = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar', 'pht',
        'phtm', 'inc', 'php9', 'php10',
        'htaccess', 'htpasswd', 'shtml', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'so', 'dll',
        // FINDING 4: active-content types are stored-XSS vectors when served
        // from public/uploads (the browser executes script/markup inline).
        // Denied even if a host allow-lists them.
        'svg', 'svgz', 'xml', 'html', 'htm', 'xhtml', 'js', 'mhtml',
    ];

    /**
     * Default extension -> acceptable finfo MIME types map for content
     * (magic-bytes) validation. A value of 'type/*' matches any subtype.
     *
     * FINDING 5: an allow-listed extension that is ABSENT from this map (and
     * from the app.uploads.mime_map override) is REJECTED (fail-closed) by the
     * content gate — it is not passed through unchecked. Hosts that allow an
     * uncommon extension must register a content signature for it via
     * app.uploads.mime_map (merged over these defaults).
     */
    private const DEFAULT_MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        // NOTE: svg/xml/html/js are in DENY (FINDING 4) so they can never reach
        // content validation; no MIME mapping is provided for them.
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/*'],
        'csv'  => ['text/*', 'application/csv'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    /** @var string[] */
    private array $allowed;

    /** @var array<string, string[]> */
    private array $mimeMap;

    /**
     * @param string[] $allowed Extension allow-list.
     * @param array<string, string[]> $mimeMap Per-extension MIME overrides,
     *        merged over the defaults (an override replaces the whole entry
     *        for that extension).
     */
    public function __construct(array $allowed, array $mimeMap = [])
    {
        $this->allowed = array_map('strtolower', $allowed);
        $this->mimeMap = array_change_key_case($mimeMap, CASE_LOWER) + self::DEFAULT_MIME_MAP;
    }

    public function safeExtension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public function isAllowed(string $filename): bool
    {
        $ext = $this->safeExtension($filename);
        if ($ext === '' || in_array($ext, self::DENY, true)) {
            return false;
        }
        return in_array($ext, $this->allowed, true);
    }

    /**
     * Magic-bytes content check: the finfo MIME of the actual file must agree
     * with the claimed extension via the extension->MIME map.
     *
     * FINDING 5: an extension with no MIME mapping fails closed; a
     * missing/unreadable file fails defensively.
     */
    public function isContentValid(string $filePath, string $clientName): bool
    {
        $expected = $this->expectedMimes($clientName);
        if ($expected === null) {
            return false; // no content signature configured ⇒ reject
        }

        if (!is_file($filePath)) {
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($filePath);

        return $mime !== false && $this->mimeAgrees($mime, $expected);
    }

    /**
     * Buffer variant of {@see isContentValid()} for callers that hold raw
     * content rather than a file on disk (e.g. IonDisk::putFile()).
     */
    public function isContentValidBuffer(string $content, string $clientName): bool
    {
        $expected = $this->expectedMimes($clientName);
        if ($expected === null) {
            return false; // FINDING 5: no content signature configured ⇒ reject
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);

        return $mime !== false && $this->mimeAgrees($mime, $expected);
    }

    /**
     * @return string[]|null The acceptable MIME types for the claimed
     *         extension, or null when the extension is not mapped.
     */
    private function expectedMimes(string $clientName): ?array
    {
        return $this->mimeMap[$this->safeExtension($clientName)] ?? null;
    }

    /** @param string[] $expected */
    private function mimeAgrees(string $mime, array $expected): bool
    {
        $mime = strtolower($mime);

        foreach ($expected as $candidate) {
            $candidate = strtolower($candidate);
            if ($candidate === $mime) {
                return true;
            }
            if (str_ends_with($candidate, '/*')
                && str_starts_with($mime, substr($candidate, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
