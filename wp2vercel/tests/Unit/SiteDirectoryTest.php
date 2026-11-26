<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Output\SiteDirectory;

final class SiteDirectoryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/wp2v-directory-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/staging-7/site/blog', 0777, true);
        mkdir($this->base . '/current/site', 0777, true);
        file_put_contents($this->base . '/staging-7/site/index.html', 'half a publish');
        file_put_contents($this->base . '/staging-7/site/blog/index.html', 'half a publish');
        file_put_contents($this->base . '/current/site/index.html', 'the published site');
    }

    protected function tearDown(): void
    {
        (new SiteDirectory($this->base))->destroy();
    }

    public function testDeletingThePluginRemovesTheWholeFolderWithItsHiddenFiles(): void
    {
        file_put_contents($this->base . '/.htaccess', 'Require all denied');
        file_put_contents($this->base . '/index.html', '');

        (new SiteDirectory($this->base))->destroy();

        self::assertDirectoryDoesNotExist($this->base);
    }

    public function testDeletingThePluginWithoutAFolderDoesNothing(): void
    {
        (new SiteDirectory($this->base . '/never-created'))->destroy();

        self::assertDirectoryExists($this->base);
    }

    public function testNoCodeDependsOnGlobBraceWhichAlpineLinuxDoesNotHave(): void
    {
        $plugin = dirname(__DIR__, 2);
        $files = [$plugin . '/uninstall.php', $plugin . '/wp2vercel.php'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($plugin . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }
        $using = array_values(array_filter($files, static fn(string $file): bool => str_contains((string) file_get_contents($file), 'GLOB_BRACE')));

        self::assertSame([], $using);
    }

    public function testTheFilesOfAPublishThatDidNotFinishAreRemoved(): void
    {
        (new SiteDirectory($this->base))->discardStaging(7);

        self::assertDirectoryDoesNotExist($this->base . '/staging-7');
    }

    public function testThePublishedSiteStaysWhenUnfinishedFilesAreRemoved(): void
    {
        (new SiteDirectory($this->base))->discardStaging(7);

        self::assertSame('the published site', file_get_contents($this->base . '/current/site/index.html'));
    }

    public function testRemovingTheFilesOfAPublishThatSavedNothingDoesNothing(): void
    {
        (new SiteDirectory($this->base))->discardStaging(8);

        self::assertDirectoryExists($this->base . '/staging-7');
    }
}
