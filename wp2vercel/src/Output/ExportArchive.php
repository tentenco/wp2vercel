<?php

declare(strict_types=1);

namespace WP2Vercel\Output;

use FilesystemIterator;
use PclZip;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

final class ExportArchive
{
    public static function available(bool $zipExtension = true): bool
    {
        return ($zipExtension && class_exists(ZipArchive::class)) || self::loadPclZip();
    }

    public static function hasFiles(string $siteFolder): bool
    {
        return is_dir($siteFolder) && (new FilesystemIterator($siteFolder))->valid();
    }

    public static function write(string $siteFolder, string $zipFile, bool $zipExtension = true): int
    {
        $root = realpath($siteFolder);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('There is no copy on this server yet.');
        }
        $files = self::files($root);
        if ($files === []) {
            throw new RuntimeException('There is no copy on this server yet.');
        }
        if ($zipExtension && class_exists(ZipArchive::class)) {
            self::withZipArchive($root, $files, $zipFile);
        } elseif (self::loadPclZip()) {
            self::withPclZip($root, $files, $zipFile);
        } else {
            throw new RuntimeException('PHP on this server has no zip support (the zip extension), so the copy cannot be packed here.');
        }
        return count($files);
    }

    public static function entryName(string $root, string $path): string
    {
        return str_replace('\\', '/', ltrim(substr($path, strlen(rtrim($root, '/\\'))), '/\\'));
    }

    private static function files(string $root): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private static function withZipArchive(string $root, array $files, string $zipFile): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The zip file could not be created in the temporary folder.');
        }
        foreach ($files as $path) {
            if (!$zip->addFile($path, self::entryName($root, $path))) {
                $zip->close();
                throw new RuntimeException('A file could not be added to the zip.');
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('The zip file could not be written. Check the free space of the temporary folder.');
        }
    }

    private static function withPclZip(string $root, array $files, string $zipFile): void
    {
        @unlink($zipFile);
        $archive = new PclZip($zipFile);
        if ($archive->create($files, PCLZIP_OPT_REMOVE_PATH, $root) === 0) {
            throw new RuntimeException('The zip file could not be written: ' . $archive->errorInfo(true));
        }
    }

    private static function loadPclZip(): bool
    {
        if (!class_exists(PclZip::class, false) && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/class-pclzip.php')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        return class_exists(PclZip::class, false);
    }
}
