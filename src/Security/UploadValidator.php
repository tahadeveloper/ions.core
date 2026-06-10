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
    ];

    /**
     * Default extension -> acceptable finfo MIME types map for content
     * (magic-bytes) validation. A value of 'type/*' matches any subtype.
     *
     * Extensions NOT present in the map are accepted on the extension gate
     * alone (isContentValid() returns true) so uncommon types are not bricked;
     * add them via the $mimeMap constructor argument
     * (config: app.uploads.mime_map, merged over these defaults).
     */
    private const DEFAULT_MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml', 'text/xml', 'application/xml'],
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
     * Extensions absent from the map pass (the extension gate has already
     * run); a missing/unreadable file fails defensively.
     */
    public function isContentValid(string $filePath, string $clientName): bool
    {
        $expected = $this->expectedMimes($clientName);
        if ($expected === null) {
            return true;
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
            return true;
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
