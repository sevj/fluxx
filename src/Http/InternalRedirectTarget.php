<?php

declare(strict_types=1);

namespace Fluxx\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves and validates an operator-supplied "_redirect" form target so that
 * it can only point back into the host application.
 *
 * A redirect target coming from a POST body must be a same-origin, absolute
 * path. It is rejected when it could be interpreted as an external URL, either
 * because it carries a scheme (e.g. "https://host/path") or because it is a
 * protocol-relative or backslash-prefixed URL that browsers may treat as a
 * cross-origin navigation (e.g. "//evil.tld", "/\\evil.tld").
 */
final class InternalRedirectTarget
{
    public static function extract(Request $request, string $field = '_redirect'): ?string
    {
        $raw = $request->request->get($field);

        if (!is_string($raw)) {
            return null;
        }

        $target = trim($raw);

        if ($target === '' || $target[0] !== '/') {
            return null;
        }

        if (str_starts_with($target, '//') || str_starts_with($target, '/\\')) {
            return null;
        }

        if (parse_url($target, PHP_URL_HOST) !== null) {
            return null;
        }

        return $target;
    }
}
