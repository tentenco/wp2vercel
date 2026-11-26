<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Crawl\LocalAssets;

final class LocalAssetsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/wp2v-assets-' . bin2hex(random_bytes(4));
        $files = [
            'themes/t/style.css' => 'body{}',
            'uploads/a.png' => 'png',
            'uploads/shop/protected/.htaccess' => 'deny from all',
            'uploads/shop/protected/deep/b.pdf' => 'secret',
            'uploads/wp2vercel-x/current/c.png' => 'export',
            'uploads/wp2vercel-xy/d.png' => 'sibling',
            'backups/site.zip' => 'backup',
        ];
        foreach ($files as $path => $content) {
            @mkdir(dirname($this->base . '/' . $path), 0777, true);
            file_put_contents($this->base . '/' . $path, $content);
        }
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->base);
    }

    private function assets(): LocalAssets
    {
        return new LocalAssets([
            '/wp-content/themes' => $this->base . '/themes',
            '/wp-content/uploads' => $this->base . '/uploads',
        ], $this->base . '/uploads/wp2vercel-x');
    }

    public function testPublicFilesAreReadFromDisk(): void
    {
        $assets = $this->assets();
        self::assertSame(realpath($this->base . '/themes/t/style.css'), $assets->resolve('/wp-content/themes/t/style.css'));
        self::assertSame(realpath($this->base . '/uploads/a.png'), $assets->resolve('/wp-content/uploads/a.png'));
        self::assertSame(realpath($this->base . '/uploads/wp2vercel-xy/d.png'), $assets->resolve('/wp-content/uploads/wp2vercel-xy/d.png'));
    }

    public function testFilesTheServerGuardsOrThatLieOutsideThePublicFoldersAreLeftToTheWebServer(): void
    {
        $assets = $this->assets();
        self::assertNull($assets->resolve('/wp-content/uploads/shop/protected/deep/b.pdf'));
        self::assertNull($assets->resolve('/wp-content/uploads/wp2vercel-x/current/c.png'));
        self::assertNull($assets->resolve('/wp-content/backups/site.zip'));
        self::assertNull($assets->resolve('/wp-content/uploads/../backups/site.zip'));
        self::assertNull($assets->resolve('/wp-content/uploads/missing.png'));
    }
}
