<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PclZip;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP2Vercel\Admin\CopyDownload;
use WP2Vercel\Output\ExportArchive;

final class ExportArchiveTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/wp2v-zip-' . bin2hex(random_bytes(4));
        foreach (['site/index.html' => '<h1>Home</h1>', 'site/blog/hello/index.html' => '<h1>Hello</h1>', 'site/vercel.json' => '{}', 'empty/.keep' => ''] as $path => $content) {
            @mkdir(dirname($this->base . '/' . $path), 0777, true);
            file_put_contents($this->base . '/' . $path, $content);
        }
        unlink($this->base . '/empty/.keep');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->base);
    }

    public function testTheZipHoldsThePublishedFilesAtTheirAddresses(): void
    {
        self::assertTrue(ExportArchive::available(false));
        $zipFile = $this->base . '/copy.zip';

        self::assertSame(3, ExportArchive::write($this->base . '/site', $zipFile, false));

        $entries = array_map(static fn(array $entry): string => $entry['filename'], (new PclZip($zipFile))->listContent());
        sort($entries);
        self::assertSame(['blog/hello/index.html', 'index.html', 'vercel.json'], $entries);
        $extracted = (new PclZip($zipFile))->extract(PCLZIP_OPT_BY_NAME, 'blog/hello/index.html', PCLZIP_OPT_EXTRACT_AS_STRING);
        self::assertSame('<h1>Hello</h1>', $extracted[0]['content']);
    }

    public function testNoZipIsMadeWithoutACopy(): void
    {
        self::assertFalse(ExportArchive::hasFiles($this->base . '/empty'));
        self::assertFalse(ExportArchive::hasFiles($this->base . '/missing'));
        self::assertTrue(ExportArchive::hasFiles($this->base . '/site'));
        $this->expectException(RuntimeException::class);
        ExportArchive::write($this->base . '/empty', $this->base . '/none.zip', false);
    }

    public function testEntryNamesUseForwardSlashesOnEveryServer(): void
    {
        self::assertSame('blog/hello/index.html', ExportArchive::entryName('C:\\sites\\copy\\site', 'C:\\sites\\copy\\site\\blog\\hello\\index.html'));
        self::assertSame('index.html', ExportArchive::entryName('/var/copy/site/', '/var/copy/site/index.html'));
    }

    public function testTheZipIsNamedAfterTheSiteAndTheTime(): void
    {
        self::assertSame('example.com-static-2026-11-26-080000.zip', CopyDownload::fileName('https://Example.com/', 1795680000));
        self::assertSame('ms.test-shop-static-2026-11-26-080000.zip', CopyDownload::fileName('http://ms.test/shop/', 1795680000));
        self::assertSame('localhost-news-static-2026-11-26-080000.zip', CopyDownload::fileName('http://localhost:8098/news/%E5%8D%9A%E5%AE%A2/', 1795680000));
    }
}
