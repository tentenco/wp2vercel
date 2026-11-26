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

    private const SERVER_RULE_FILES = ['.htaccess', 'web.config'];

    private array $roots = [];
    private string $forbiddenDirectory;
    private array $guardedFolders = [];

    public function __construct(array $urlPathToDirectory, string $forbiddenDirectory)
    {
        foreach ($urlPathToDirectory as $urlPath => $directory) {
            $real = realpath((string) $directory);
            if ($real !== false) {
                $this->roots['/' . trim((string) $urlPath, '/')] = $real;
            }
        }
        uksort($this->roots, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->forbiddenDirectory = $forbiddenDirectory === '' ? '' : (realpath($forbiddenDirectory) ?: rtrim($forbiddenDirectory, '/\\'));
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
            if ($this->forbiddenDirectory !== '' && str_starts_with($candidate, $this->forbiddenDirectory . DIRECTORY_SEPARATOR)) {
                return null;
            }
            if ($this->hasServerRulesBelow($directory, dirname($candidate))) {
                return null;
            }
            return $candidate;
        }
        return null;
    }

    private function hasServerRulesBelow(string $root, string $folder): bool
    {
        while ($folder !== $root && str_starts_with($folder, $root . DIRECTORY_SEPARATOR)) {
            $this->guardedFolders[$folder] ??= self::hasServerRules($folder);
            if ($this->guardedFolders[$folder]) {
                return true;
            }
            $folder = dirname($folder);
        }
        return false;
    }

    private static function hasServerRules(string $folder): bool
    {
        foreach (self::SERVER_RULE_FILES as $name) {
            if (file_exists($folder . DIRECTORY_SEPARATOR . $name)) {
                return true;
            }
        }
        return false;
    }
}
