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
