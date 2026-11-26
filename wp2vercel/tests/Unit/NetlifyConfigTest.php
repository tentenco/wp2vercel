<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Redirects\NetlifyConfig;
use WP2Vercel\Redirects\RedirectRule;
use WP2Vercel\Redirects\VercelConfig;

final class NetlifyConfigTest extends TestCase
{
    public function testTheVercelRulesBecomeNetlifyRedirectsAndHeaders(): void
    {
        $vercel = (new VercelConfig(
            true,
            [RedirectRule::create('/old-page/', '/new-page/', 301), RedirectRule::create('/a (b)/', 'https://example.com/x', 302)],
            ['/con' => '_con'],
            '/%E5%8D%9A%E5%AE%A2/'
        ))->toArray();
        $vercel['redirects'] = array_merge([
            [
                'source' => '/',
                'has' => [['type' => 'header', 'key' => 'accept-language', 'value' => 'ja.*']],
                'missing' => [['type' => 'header', 'key' => 'referer', 'value' => 'https?://example\.com/.*']],
                'destination' => '/ja/',
                'permanent' => false,
            ],
            ['source' => '/blog/:slug', 'destination' => '/posts/:slug', 'permanent' => true],
            ['source' => '/x', 'has' => [['type' => 'cookie', 'key' => 'beta']], 'destination' => '/y', 'permanent' => false],
        ], $vercel['redirects']);

        $netlify = new NetlifyConfig($vercel);

        self::assertSame(
            "/ /ja/ 302! Language=ja\n"
            . "/ /%E5%8D%9A%E5%AE%A2/ 307!\n"
            . "/a%20(b)/ https://example.com/x 302!\n"
            . "/old-page/ /new-page/ 301!\n"
            . "/con/ /_con 200\n",
            $netlify->redirects()
        );
        self::assertSame(['/blog/:slug', '/x'], $netlify->skipped());
        self::assertSame(
            "/*\n  X-Content-Type-Options: nosniff\n  Referrer-Policy: strict-origin-when-cross-origin\n  X-Frame-Options: SAMEORIGIN\n",
            $netlify->headers()
        );
    }

    public function testNothingToRedirectWritesNoRedirectsFile(): void
    {
        $netlify = new NetlifyConfig(['headers' => []]);
        self::assertSame('', $netlify->redirects());
        self::assertSame('', $netlify->headers());
        self::assertSame([], $netlify->skipped());
    }
}
