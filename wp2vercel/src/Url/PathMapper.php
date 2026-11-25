<?php

declare(strict_types=1);

namespace WP2Vercel\Url;

final class PathMapper
{
    private const MAX_SEGMENT_BYTES = 200;

    public function map(string $urlPath, ContentKind $kind): ?MappedPath
    {
        $path = strtok($urlPath, '?') ?: '/';
        $decoded = rawurldecode($path);
        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            return null;
        }

        $endsWithSlash = str_ends_with($decoded, '/');
        $segments = [];
        foreach (explode('/', trim($decoded, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                return null;
            }
            $segments[] = self::safeSegment($segment);
        }

        $isFile = !$endsWithSlash && $segments !== [] && UrlResolver::hasFileExtension('/' . end($segments));
        if ($isFile) {
            return new MappedPath(implode('/', $segments), null);
        }

        $directory = implode('/', $segments);
        $file = ($directory === '' ? '' : $directory . '/') . $kind->indexFileName();
        $rewriteSource = $kind === ContentKind::Html ? null : '/' . ($directory === '' ? '' : $directory);

        return new MappedPath($file, $rewriteSource);
    }

    private static function safeSegment(string $segment): string
    {
        $segment = preg_replace('~[\x00-\x1F\x7F<>:"|?*]~', '-', $segment) ?? $segment;
        if (strlen($segment) <= self::MAX_SEGMENT_BYTES) {
            return $segment;
        }
        $extension = UrlResolver::extension($segment);
        $suffix = '-' . substr(sha1($segment), 0, 12) . ($extension === '' ? '' : '.' . $extension);
        return mb_strcut($segment, 0, self::MAX_SEGMENT_BYTES - strlen($suffix), 'UTF-8') . $suffix;
    }
}
