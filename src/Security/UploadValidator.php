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

    /** @var string[] */
    private array $allowed;

    /** @param string[] $allowed */
    public function __construct(array $allowed)
    {
        $this->allowed = array_map('strtolower', $allowed);
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
}
