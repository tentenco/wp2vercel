<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Crawl\UrlFilter;

final class UrlFilterTest extends TestCase
{
    public function testBuiltInExclusions(): void
    {
        $filter = new UrlFilter([], false, 'author');
        foreach ([
            '/wp-admin/', '/wp-admin/admin-ajax.php', '/wp-login.php', '/xmlrpc.php', '/wp-json/wp/v2/posts',
            '/wp-comments-post.php', '/feed/', '/category/news/feed/', '/comments/feed/atom/', '/author/admin/',
            '/.env', '/.git/config', '/hello/trackback/', '/hello/embed/', '/wp-content/debug.log',
            '/wp-content/uploads/wp2vercel-abc/current/site/index.html', '/index.php/foo',
        ] as $path) {
            self::assertFalse($filter->allows($path), $path);
        }
        foreach ([
            '/', '/about/', '/wp-content/themes/t/style.css', '/wp-includes/js/a.js', '/.well-known/security.txt',
            '/feedback/', '/authority/', '/wp-admin-guide/', '/robots.txt', '/wp-sitemap.xml',
        ] as $path) {
            self::assertTrue($filter->allows($path), $path);
        }
    }

    public function testASiteUnderAPathKeepsToItsOwnPagesAndLeavesOtherSitesOut(): void
    {
        $shop = new UrlFilter([], false, 'shop/author', '/shop', ['/', '/blog2/']);
        foreach (['/shop', '/shop/', '/shop/hello/', '/shop/wp-sitemap.xml', '/wp-content/themes/t/style.css', '/wp-includes/js/a.js', '/wp-content/uploads/sites/2/a.png', '/wp-content/uploads/sites/2/anim.json', '/blog2/a.png'] as $path) {
            self::assertTrue($shop->allows($path), $path);
        }
        foreach (['/', '/blog/hello-world/', '/sample-page/', '/wp-sitemap.xml', '/robots.txt', '/blog2/', '/shop/wp-json/wp/v2/users', '/shop/wp-admin/', '/shop/author/admin/', '/shopping/', '/wp-content/uploads/sites/2/wp2vercel-abc/current/site/index.html'] as $path) {
            self::assertFalse($shop->allows($path), $path);
        }
        $main = new UrlFilter([], false, 'author', '', ['/shop/']);
        self::assertTrue($main->allows('/blog/hello-world/'));
        self::assertTrue($main->allows('/shopping/'));
        self::assertFalse($main->allows('/shop/'));
        self::assertFalse($main->allows('/shop/hello/'));
        self::assertTrue($main->allows('/shop/wp-content/uploads/sites/3/x.jpg'));
        self::assertTrue($main->allows('/shop/wp-content/uploads/sites/3/anim.json'));
    }

    public function testASiteInsideAnotherSitesPathKeepsItsOwnPages(): void
    {
        $shop = new UrlFilter([], false, null, '/network/shop', ['/network/', '/network/blog/']);
        foreach (['/network/shop/', '/network/shop/hello/', '/network/wp-content/uploads/sites/2/a.png'] as $path) {
            self::assertTrue($shop->allows($path), $path);
        }
        foreach (['/network/', '/network/about/', '/network/blog/'] as $path) {
            self::assertFalse($shop->allows($path), $path);
        }
        $main = new UrlFilter([], false, null, '/network', ['/network/shop/', '/network/blog/']);
        self::assertTrue($main->allows('/network/about/'));
        self::assertFalse($main->allows('/network/shop/hello/'));
        self::assertFalse($main->allows('/network/blog/'));
    }

    public function testASiteAtANonLatinPathKeepsItsPages(): void
    {
        $blog = new UrlFilter([], false, '博客/author', '/博客', ['/博客/商店/']);
        foreach (['/%E5%8D%9A%E5%AE%A2/', '/%E5%8D%9A%E5%AE%A2/hello/', '/%e5%8d%9a%e5%ae%a2/%E4%BD%A0%E5%A5%BD/'] as $path) {
            self::assertTrue($blog->allows($path), $path);
        }
        foreach (['/other/', '/%E5%8D%9A%E5%AE%A2/%E5%95%86%E5%BA%97/', '/%E5%8D%9A%E5%AE%A2/author/admin/', '/%E5%8D%9A%E5%AE%A2/wp-json/'] as $path) {
            self::assertFalse($blog->allows($path), $path);
        }
    }

    public function testFilesOfAContentFolderOutsideTheSitePathAreKept(): void
    {
        $blog = new UrlFilter([], false, null, '/blog', [], ['/app/uploads', '/app/plugins']);
        self::assertTrue($blog->allows('/app/uploads/2026/11/data.json'));
        self::assertTrue($blog->allows('/app/plugins/p/feed.xsl'));
        self::assertFalse($blog->allows('/about/'));
        self::assertFalse($blog->allows('/app/'));
    }

    public function testOptionalContentAndUserPatterns(): void
    {
        $filter = new UrlFilter(['/members/', '~^/tag/[a-z]+/page/~', '~broken[regex~'], true, null);
        self::assertTrue($filter->allows('/feed/'));
        self::assertTrue($filter->allows('/author/admin/'));
        self::assertFalse($filter->allows('/area/members/list/'));
        self::assertFalse($filter->allows('/tag/news/page/2/'));
        self::assertTrue($filter->allows('/tag/news/'));
        self::assertFalse($filter->allows('/x/~broken[regex~/'));
    }
}
