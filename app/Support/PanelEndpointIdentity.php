<?php

namespace App\Support;

final class PanelEndpointIdentity
{
    public static function canonicalUrl(string $url): string
    {
        $url = trim($url);
        if (
            $url === ''
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new \InvalidArgumentException(
                'The panel URL must be an absolute HTTP(S) URL without whitespace.'
            );
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError $exception) {
            throw new \InvalidArgumentException(
                'The panel URL is invalid.',
                previous: $exception
            );
        }
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('The panel URL is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === ''
        ) {
            throw new \InvalidArgumentException(
                'The panel URL must use HTTP or HTTPS and include a host.'
            );
        }
        if (
            array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            throw new \InvalidArgumentException(
                'The panel URL cannot contain user information, a query, or a fragment.'
            );
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (
            $port !== null
            && (
                $port < 1
                || $port > 65535
            )
        ) {
            throw new \InvalidArgumentException(
                'The panel URL contains an invalid port.'
            );
        }
        if (
            ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443)
        ) {
            $port = null;
        }

        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = "[{$host}]";
        }
        $path = (string) ($parts['path'] ?? '');
        $trimmedPath = rtrim($path, '/');
        $hasDotSegment = false;
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['.', '..'], true)) {
                $hasDotSegment = true;

                break;
            }
        }
        if (
            str_contains($trimmedPath, '//')
            || str_contains($path, '\\')
            || str_contains($path, '%')
            || $hasDotSegment
        ) {
            throw new \InvalidArgumentException(
                'The panel URL path must be canonical and cannot contain dot segments, redundant separators, backslashes, or percent encoding.'
            );
        }
        $path = $trimmedPath;

        return $scheme
            . '://'
            . $host
            . ($port !== null ? ":{$port}" : '')
            . $path;
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::canonicalUrl($url));
    }
}
