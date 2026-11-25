<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Redirects\RedirectCollector;
use WP2Vercel\Redirects\RedirectRule;
use WP2Vercel\Redirects\VercelConfig;

final class RedirectsTest extends TestCase
{
    public function testAcceptsOrdinaryRules(): void
    {
        $rule = RedirectRule::create('old-page/', '/new-page/', 302);
        self::assertNotNull($rule);
        self::assertSame('/old-page/', $rule->source);
        self::assertSame(302, $rule->status);
        self::assertSame(301, RedirectRule::create('/a', 'https://other.example.org/b', 999)->status);
    }

    public function testRejectsUnsafeOrPointlessRules(): void
    {
        self::assertNull(RedirectRule::create('/a', 'javascript:alert(1)'));
        self::assertNull(RedirectRule::create('/a', '//evil.test/x'));
        self::assertNull(RedirectRule::create('/a', 'https://user:pass@evil.test/'));
        self::assertNull(RedirectRule::create('/a', "/b\r\nSet-Cookie: x=1"));
        self::assertNull(RedirectRule::create('https://evil.test/a', '/b'));
        self::assertNull(RedirectRule::create('/', '/home/'));
        self::assertNull(RedirectRule::create('/a/../b', '/c'));
        self::assertNull(RedirectRule::create('/page', '/page/'));
        self::assertNull(RedirectRule::create('', '/b'));
    }

    public function testSourceIsEscapedForVercelPathSyntax(): void
    {
        $rule = RedirectRule::create('/price(usd)/:id*', '/pricing/');
        self::assertSame('/price\\(usd\\)/\\:id\\*', $rule->toVercel()['source']);
        self::assertSame(301, $rule->toVercel()['statusCode']);
    }

    public function testSettingsLines(): void
    {
        $collector = new RedirectCollector(['/old/ /new/ 302', '/gone/ https://example.org/', 'broken-line', '/bad/ javascript:x']);
        $rules = $collector->fromSettings();
        self::assertCount(2, $rules);
        self::assertSame(['/old/', '/gone/'], array_values(array_map(static fn(RedirectRule $rule): string => $rule->source, $rules)));
    }

    public function testVercelConfig(): void
    {
        $config = new VercelConfig(
            true,
            [RedirectRule::create('/old/', '/new/'), RedirectRule::create('/b/', '/c/', 308)],
            ['/feed' => 'feed/index.xml', '/' => 'index.html']
        );
        $data = json_decode($config->toJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($data['trailingSlash']);
        self::assertFalse($data['cleanUrls']);
        self::assertNull($data['framework']);
        self::assertSame([
            ['source' => '/b/', 'destination' => '/c/', 'statusCode' => 308],
            ['source' => '/old/', 'destination' => '/new/', 'statusCode' => 301],
        ], $data['redirects']);
        self::assertContains(['source' => '/feed/', 'destination' => '/feed/index.xml'], $data['rewrites']);
        self::assertSame(4, $config->routeCount());
        self::assertFalse($config->exceedsRouteLimit());
    }

    public function testVercelConfigWithoutTrailingSlashOrRoutes(): void
    {
        $config = new VercelConfig(false, [], ['/feed' => 'feed/index.xml']);
        $data = $config->toArray();
        self::assertFalse($data['trailingSlash']);
        self::assertArrayNotHasKey('redirects', $data);
        self::assertSame([['source' => '/feed', 'destination' => '/feed/index.xml']], $data['rewrites']);
    }
}
