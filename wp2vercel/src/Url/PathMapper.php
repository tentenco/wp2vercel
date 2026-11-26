<?php

declare(strict_types=1);

namespace WP2Vercel\Url;

use WP2Vercel\Utf8;

final class PathMapper
{
    private const MAX_SEGMENT_BYTES = 200;
    private const WINDOWS_DEVICE_NAME = '~^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)~i';

    public function __construct(private readonly bool $windowsFileNames = PHP_OS_FAMILY === 'Windows')
    {
    }

    public function map(string $urlPath, ContentKind $kind): ?MappedPath
    {
        $path = strtok($urlPath, '?') ?: '/';
        $decoded = rawurldecode($path);
        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            return null;
        }

        $endsWithSlash = str_ends_with($decoded, '/');
        $segments = [];
        $stored = [];
        foreach (explode('/', trim($decoded, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                return null;
            }
            $safe = self::safeSegment($segment);
            $segments[] = $safe;
            $stored[] = $this->windowsFileNames && preg_match(self::WINDOWS_DEVICE_NAME, $safe) === 1 ? '_' . $safe : $safe;
        }
        $renamed = $stored !== $segments;

        $isFile = !$endsWithSlash && $segments !== [] && UrlResolver::hasFileExtension('/' . end($segments));
        if ($isFile) {
            return new MappedPath(implode('/', $stored), $renamed ? '/' . implode('/', $segments) : null);
        }

        $directory = implode('/', $stored);
        $file = ($directory === '' ? '' : $directory . '/') . $kind->indexFileName();
        $rewriteSource = $kind === ContentKind::Html && !$renamed ? null : '/' . implode('/', $segments);

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
        return Utf8::cut($segment, self::MAX_SEGMENT_BYTES - strlen($suffix)) . $suffix;
    }
}
