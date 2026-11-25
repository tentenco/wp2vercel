<?php

declare(strict_types=1);

namespace WP2Vercel\Url;

final class UrlResolver
{
    private const SKIPPED_SCHEMES = '~^(?:mailto|tel|sms|data|javascript|blob|about|ftp|file|whatsapp|skype|callto|geo|magnet):~i';

    public function __construct(private readonly Origin $origin)
    {
    }

    public function resolve(string $reference, ?string $baseUrl = null): ?string
    {
        $reference = trim(str_replace(['\\/', "\t", "\n", "\r"], ['/', '', '', ''], $reference));
        if ($reference === '' || $reference[0] === '#' || preg_match(self::SKIPPED_SCHEMES, $reference)) {
            return null;
        }

        $reference = self::encodeNonAscii($reference);
        $absolute = $this->absolutize($reference, $baseUrl ?? $this->origin->base() . '/');
        if ($absolute === null) {
            return null;
        }

        $parts = parse_url($absolute);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return null;
        }
        if (!$this->origin->matchesAuthority($parts['scheme'], $parts['host'], $parts['port'] ?? null)) {
            return null;
        }

        $path = self::removeDotSegments($parts['path'] ?? '/');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = self::normalizeEncoding($path);

        $query = $parts['query'] ?? '';
        if ($query !== '' && !self::hasFileExtension($path)) {
            return null;
        }

        return $this->origin->base() . $path;
    }

    public function path(string $resolvedUrl): string
    {
        return substr($resolvedUrl, strlen($this->origin->base())) ?: '/';
    }

    public static function hasFileExtension(string $path): bool
    {
        $segment = substr($path, (int) strrpos($path, '/') + 1);
        return (bool) preg_match('~\.[A-Za-z0-9]{1,10}$~', $segment);
    }

    public static function extension(string $path): string
    {
        $segment = substr($path, (int) strrpos($path, '/') + 1);
        if (preg_match('~\.([A-Za-z0-9]{1,10})$~', $segment, $match)) {
            return strtolower($match[1]);
        }
        return '';
    }

    private function absolutize(string $reference, string $baseUrl): ?string
    {
        if (preg_match('~^https?://~i', $reference)) {
            return $reference;
        }
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $reference)) {
            return null;
        }
        if (str_starts_with($reference, '//')) {
            return $this->origin->scheme . ':' . $reference;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['host'])) {
            return null;
        }
        $root = ($base['scheme'] ?? $this->origin->scheme) . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if ($reference[0] === '/') {
            return $root . $reference;
        }
        if ($reference[0] === '?') {
            return $root . ($base['path'] ?? '/') . $reference;
        }

        $basePath = $base['path'] ?? '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        return $root . $directory . $reference;
    }

    private static function encodeNonAscii(string $reference): string
    {
        return preg_replace_callback(
            '~[^\x21-\x7E]~',
            static fn(array $match): string => rawurlencode($match[0]),
            $reference
        ) ?? $reference;
    }

    private static function removeDotSegments(string $path): string
    {
        $path = strtok($path, '#') ?: '/';
        $output = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);
        if (str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $result .= '/';
        }
        return preg_replace('~/{2,}~', '/', $result) ?? $result;
    }

    private static function normalizeEncoding(string $path): string
    {
        $segments = array_map(
            static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path)
        );
        return implode('/', $segments);
    }
}
