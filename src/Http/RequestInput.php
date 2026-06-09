<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Support\Request;

final class RequestInput
{
    /**
     * Parse request inputs into a plain object using the Request abstraction
     * (no superglobals). JSON bodies are decoded; form/query params are used
     * otherwise; uploaded files (if any) are exposed under the `files` key.
     */
    public static function parse(Request $request): object
    {
        $method = strtoupper($request->getMethod());
        $data = [];

        $isJson = str_contains((string) $request->headers->get('Content-Type'), 'json');
        $raw = (string) $request->getContent();

        if (in_array($method, ['GET', 'DELETE', 'HEAD'], true)) {
            $data = $request->query->all();
        } elseif ($isJson && $raw !== '') {
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];
        } else {
            // form-encoded body (POST/PUT/PATCH) merged with query
            $data = $request->request->all();
            // Strip any client-supplied 'files' key so it cannot masquerade as
            // real UploadedFile objects added below.
            unset($data['files']);
            if ($data === [] && $raw !== '') {
                parse_str($raw, $parsed);
                $data = $parsed;
            }
        }

        $files = $request->files->all();
        if ($files !== []) {
            $data['files'] = $files;
        }

        return (object) $data;
    }
}
