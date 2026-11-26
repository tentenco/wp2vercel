<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP2Vercel\Build\Environment;
use WP2Vercel\Url\ContentKind;
use WP2Vercel\Url\PathMapper;

final class PathMapperTest extends TestCase
{
    public static function paths(): array
    {
        return [
            'home' => ['/', ContentKind::Html, 'index.html', null],
            'page with slash' => ['/about/', ContentKind::Html, 'about/index.html', null],
            'page without slash' => ['/about', ContentKind::Html, 'about/index.html', null],
            'nested' => ['/2026/11/hello/', ContentKind::Html, '2026/11/hello/index.html', null],
            'asset keeps name' => ['/wp-content/themes/x/style.css', ContentKind::Css, 'wp-content/themes/x/style.css', null],
            'asset query ignored' => ['/a/b.js?ver=1', ContentKind::Script, 'a/b.js', null],
            'feed needs rewrite' => ['/feed/', ContentKind::Xml, 'feed/index.xml', '/feed'],
            'json endpoint needs rewrite' => ['/data', ContentKind::Json, 'data/index.json', '/data'],
            'unicode slug decoded' => ['/%E9%97%9C%E6%96%BC/', ContentKind::Html, '關於/index.html', null],
            'robots' => ['/robots.txt', ContentKind::Text, 'robots.txt', null],
            'versioned directory is not a file' => ['/docs/v1.2/', ContentKind::Html, 'docs/v1.2/index.html', null],
        ];
    }

    #[DataProvider('paths')]
    public function testMap(string $path, ContentKind $kind, string $file, ?string $rewriteSource): void
    {
        $mapped = (new PathMapper())->map($path, $kind);
        self::assertNotNull($mapped);
        self::assertSame($file, $mapped->file);
        self::assertSame($rewriteSource, $mapped->rewriteSource);
    }

    public function testRejectsEncodedTraversal(): void
    {
        $mapper = new PathMapper();
        self::assertNull($mapper->map('/a/%2e%2e/%2e%2e/secret', ContentKind::Html));
        self::assertNull($mapper->map('/a/..%5c..%5cwin', ContentKind::Html));
        self::assertNull($mapper->map('/a/%00/b', ContentKind::Html));
    }

    public function testEncodedSlashCannotEscapeAsTraversal(): void
    {
        self::assertNull((new PathMapper())->map('/a/..%2f..%2fetc/passwd', ContentKind::Html));
    }

    public function testVeryLongSegmentIsShortenedDeterministically(): void
    {
        $mapper = new PathMapper();
        $long = '/' . str_repeat('a', 400) . '.png';
        $first = $mapper->map($long, ContentKind::Binary);
        $second = $mapper->map($long, ContentKind::Binary);
        self::assertNotNull($first);
        self::assertLessThanOrEqual(200, strlen($first->file));
        self::assertStringEndsWith('.png', $first->file);
        self::assertSame($first->file, $second->file);
    }

    public function testTheHomeFolderIsWhereTheHomePageIsStored(): void
    {
        $mapper = new PathMapper(false);
        self::assertSame('', Environment::folderOf($mapper, ''));
        self::assertSame('shop', Environment::folderOf($mapper, '/shop'));
        self::assertSame('network/shop', Environment::folderOf($mapper, '/network/shop/'));
        self::assertSame('博客', Environment::folderOf($mapper, '/%E5%8D%9A%E5%AE%A2'));
        self::assertSame('blog/_con', Environment::folderOf(new PathMapper(true), '/blog/con'));
    }

    public function testWindowsDeviceNamesAreStoredUnderAnotherNameAndServedAtTheirOwnAddress(): void
    {
        $windows = new PathMapper(true);
        $page = $windows->map('/con/', ContentKind::Html);
        self::assertSame('_con/index.html', $page?->file);
        self::assertSame('/con', $page?->rewriteSource);
        $file = $windows->map('/files/AUX.pdf', ContentKind::Binary);
        self::assertSame('files/_AUX.pdf', $file?->file);
        self::assertSame('/files/AUX.pdf', $file?->rewriteSource);
        self::assertSame('console/index.html', $windows->map('/console/', ContentKind::Html)?->file);
        self::assertNull($windows->map('/console/', ContentKind::Html)?->rewriteSource);
        $elsewhere = new PathMapper(false);
        self::assertSame('con/index.html', $elsewhere->map('/con/', ContentKind::Html)?->file);
        self::assertNull($elsewhere->map('/con/', ContentKind::Html)?->rewriteSource);
    }
}
