<?php

declare(strict_types=1);

namespace WP2Vercel\Output;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class SiteDirectory
{
    private const RENAME_ATTEMPTS = 5;
    private const RENAME_PAUSE_MICROSECONDS = 200000;

    public const SUFFIX_OPTION = 'wp2vercel_directory_suffix';

    private const SITE = 'site';
    private const META = 'meta.json';
    private const MANIFEST = 'manifest.jsonl';

    public function __construct(private readonly string $base)
    {
    }

    public static function fromUploads(): self
    {
        $suffix = get_option(self::SUFFIX_OPTION);
        if (!is_string($suffix) || !preg_match('~^[a-f0-9]{24}$~', $suffix)) {
            $suffix = bin2hex(random_bytes(12));
            update_option(self::SUFFIX_OPTION, $suffix, false);
        }
        return new self(wp_upload_dir(null, false)['basedir'] . '/wp2vercel-' . $suffix);
    }

    public function base(): string
    {
        return $this->base;
    }

    public function protect(): void
    {
        $this->ensureDirectory($this->base);
        $guards = [
            '.htaccess' => "Require all denied\nDeny from all\n",
            'index.html' => '',
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        ];
        foreach ($guards as $name => $content) {
            if (!is_file($this->base . '/' . $name)) {
                file_put_contents($this->base . '/' . $name, $content);
            }
        }
    }

    public function root(int $buildId, bool $staging): string
    {
        return $this->base . '/' . ($staging ? 'staging-' . $buildId : 'current');
    }

    public function site(string $root): string
    {
        return $root . '/' . self::SITE;
    }

    public function metaFile(string $root): string
    {
        return $root . '/' . self::META;
    }

    public function manifestFile(string $root): string
    {
        return $root . '/' . self::MANIFEST;
    }

    public function hasCurrent(): bool
    {
        return is_dir($this->site($this->root(0, false)));
    }

    public function prepareStaging(int $buildId): string
    {
        $this->protect();
        $this->discardAllStaging();
        $root = $this->root($buildId, true);
        $this->ensureDirectory($this->site($root));
        return $root;
    }

    public function discardStaging(int $buildId): void
    {
        $this->remove($this->root($buildId, true));
    }

    public function discardAllStaging(): void
    {
        foreach (glob($this->base . '/staging-*', GLOB_ONLYDIR) ?: [] as $stale) {
            $this->remove($stale);
        }
    }

    public function unwritableFolder(): string
    {
        $folder = is_dir($this->base) ? $this->base : dirname($this->base);
        return wp_is_writable($folder) ? '' : $folder;
    }

    public function promote(int $buildId): void
    {
        $staging = $this->root($buildId, true);
        $current = $this->root(0, false);
        $previous = $this->base . '/previous';
        $this->remove($previous);
        if (is_dir($current) && !self::renameSoon($current, $previous)) {
            throw StorageFailed::because('the current version could not be moved aside');
        }
        if (!self::renameSoon($staging, $current)) {
            if (is_dir($previous)) {
                self::renameSoon($previous, $current);
            }
            throw StorageFailed::because('the new version could not be put in place');
        }
        $this->remove($previous);
    }

    private static function renameSoon(string $from, string $to): bool
    {
        for ($attempt = 1; $attempt <= self::RENAME_ATTEMPTS; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }
            if ($attempt < self::RENAME_ATTEMPTS) {
                usleep(self::RENAME_PAUSE_MICROSECONDS * $attempt);
            }
        }
        return false;
    }

    public function write(string $root, string $relativePath, string $content): void
    {
        $target = $this->target($root, $relativePath);
        if (file_put_contents($target, $content) === false) {
            throw StorageFailed::because('could not write ' . $relativePath);
        }
    }

    public function copyInto(string $root, string $relativePath, string $sourceFile): void
    {
        $target = $this->target($root, $relativePath);
        if (!copy($sourceFile, $target)) {
            throw StorageFailed::because('could not copy ' . $relativePath);
        }
    }

    public function exists(string $root, string $relativePath): bool
    {
        return is_file($this->site($root) . '/' . ltrim($relativePath, '/'));
    }

    public function files(string $root): array
    {
        $site = $this->site($root);
        if (!is_dir($site)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($site) + 1));
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    public function remove(string $path): void
    {
        if (!str_starts_with($path, $this->base . '/') || str_contains($path, '..')) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }

    public function copyTree(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $target = $destination . '/' . substr($entry->getPathname(), strlen($source) + 1);
            if ($entry->isDir()) {
                $this->ensureDirectory($target);
            } elseif (!copy($entry->getPathname(), $target)) {
                throw StorageFailed::because('could not copy ' . $entry->getFilename());
            }
        }
    }

    public function destroy(): void
    {
        if (!is_dir($this->base)) {
            return;
        }
        foreach (array_diff(scandir($this->base) ?: [], ['.', '..']) as $name) {
            $this->remove($this->base . '/' . $name);
        }
        rmdir($this->base);
    }

    private function target(string $root, string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, "\0") || preg_match('~(?:^|/)\.\.(?:/|$)~', $relativePath)) {
            throw new RuntimeException('Refusing to write outside the site directory: ' . $relativePath);
        }
        $target = $this->site($root) . '/' . $relativePath;
        $this->ensureDirectory(dirname($target));
        return $target;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (is_file($directory)) {
            throw new RuntimeException('A file blocks the directory ' . basename($directory));
        }
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw StorageFailed::because('could not create the folder ' . $directory);
        }
    }
}
