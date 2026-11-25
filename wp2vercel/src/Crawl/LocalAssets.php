<?php

declare(strict_types=1);

namespace WP2Vercel\Crawl;

use WP2Vercel\Url\UrlResolver;

final class LocalAssets
{
    private const STATIC_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'json', 'webmanifest',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'heic',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'm4v', 'webm', 'ogv', 'mov', 'mp3', 'm4a', 'ogg', 'wav', 'vtt',
        'pdf', 'zip', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    ];

    private array $roots = [];

    public function __construct(array $urlPathToDirectory, private readonly string $forbiddenDirectory)
    {
        foreach ($urlPathToDirectory as $urlPath => $directory) {
            $real = realpath((string) $directory);
            if ($real !== false) {
                $this->roots['/' . trim((string) $urlPath, '/')] = $real;
            }
        }
        uksort($this->roots, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public function resolve(string $urlPath): ?string
    {
        if (!in_array(UrlResolver::extension($urlPath), self::STATIC_EXTENSIONS, true)) {
            return null;
        }
        $decoded = rawurldecode($urlPath);
        if (str_contains($decoded, "\0") || preg_match('~(?:^|/)\.(?!well-known/)~', $decoded)) {
            return null;
        }
        foreach ($this->roots as $prefix => $directory) {
            $prefixWithSlash = rtrim($prefix, '/') . '/';
            if (!str_starts_with($decoded, $prefixWithSlash)) {
                continue;
            }
            $candidate = realpath($directory . '/' . substr($decoded, strlen($prefixWithSlash)));
            if ($candidate === false || !is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            if (!str_starts_with($candidate, $directory . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if ($this->forbiddenDirectory !== '' && str_starts_with($candidate, $this->forbiddenDirectory)) {
                continue;
            }
            return $candidate;
        }
        return null;
    }
}
