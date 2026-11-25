<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Rewrite\UrlRewriter;
use WP2Vercel\Url\ContentKind;
use WP2Vercel\Url\Origin;

final class UrlRewriterTest extends TestCase
{
    private const ORIGIN = 'https://wp.internal.example';

    public function testHtmlBecomesRootRelativeAndHeadTagsUseProduction(): void
    {
        $rewriter = new UrlRewriter(new Origin(self::ORIGIN), 'https://www.example.com/');
        $html = '<link rel="canonical" href="https://wp.internal.example/about/">'
            . '<meta property="og:url" content="https://wp.internal.example/about/">'
            . '<link rel="stylesheet" href="https://wp.internal.example/style.css">'
            . '<script type="application/ld+json">{"url":"https:\/\/wp.internal.example\/about\/"}</script>'
            . '<a href="https://wp.internal.example/contact/">c</a><a href="https://wp.internal.example">home</a>'
            . '<a href="//wp.internal.example/x/">x</a>'
            . '<script>var u="https:\/\/wp.internal.example\/wp-content\/a.js";</script>'
            . '<a href="https://share.example.org/?u=https%3A%2F%2Fwp.internal.example%2Fabout%2F">share</a>';

        $out = $rewriter->rewrite($html, ContentKind::Html);

        self::assertStringContainsString('<link rel="canonical" href="https://www.example.com/about/">', $out);
        self::assertStringContainsString('content="https://www.example.com/about/"', $out);
        self::assertStringContainsString('<link rel="stylesheet" href="/style.css">', $out);
        self::assertStringContainsString('{"url":"https:\/\/www.example.com\/about\/"}', $out);
        self::assertStringContainsString('<a href="/contact/">c</a><a href="/">home</a>', $out);
        self::assertStringContainsString('<a href="/x/">x</a>', $out);
        self::assertStringContainsString('var u="\/wp-content\/a.js";', $out);
        self::assertStringContainsString('?u=https%3A%2F%2Fwww.example.com%2Fabout%2F', $out);
        self::assertStringNotContainsString('wp.internal.example', $out);
    }

    public function testWithoutProductionUrlEverythingIsRootRelative(): void
    {
        $rewriter = new UrlRewriter(new Origin(self::ORIGIN), '');
        $out = $rewriter->rewrite('<link rel="canonical" href="https://wp.internal.example/about/"><a href="http://wp.internal.example/?x=1">q</a>', ContentKind::Html);
        self::assertSame('<link rel="canonical" href="/about/"><a href="/?x=1">q</a>', $out);
    }

    public function testSitemapUsesProductionUrl(): void
    {
        $rewriter = new UrlRewriter(new Origin(self::ORIGIN), 'https://www.example.com');
        $out = $rewriter->rewrite('<loc>https://wp.internal.example/hello/</loc>', ContentKind::Xml);
        self::assertSame('<loc>https://www.example.com/hello/</loc>', $out);
    }

    public function testLookalikeAndSubdomainHostsAreLeftAlone(): void
    {
        $rewriter = new UrlRewriter(new Origin(self::ORIGIN), 'https://www.example.com');
        $html = '<a href="https://wp.internal.example.evil.test/a">a</a><a href="https://cdn.wp.internal.example/b">b</a><a href="https://wp.internal.example-2.test/c">c</a><a href="https://wp.internal.example:8443/d">d</a>';
        self::assertSame($html, $rewriter->rewrite($html, ContentKind::Html));
    }

    public function testOriginWithPort(): void
    {
        $rewriter = new UrlRewriter(new Origin('http://localhost:8080'), '');
        $out = $rewriter->rewrite('<a href="http://localhost:8080/a/">a</a> <a href="http://localhost/b/">b</a> "http:\/\/localhost:8080\/c"', ContentKind::Html);
        self::assertSame('<a href="/a/">a</a> <a href="http://localhost/b/">b</a> "\/c"', $out);
    }

    public function testCssAndBinary(): void
    {
        $rewriter = new UrlRewriter(new Origin(self::ORIGIN), 'https://www.example.com');
        self::assertSame('a{background:url(/img/a.png)}', $rewriter->rewrite('a{background:url(https://wp.internal.example/img/a.png)}', ContentKind::Css));
        $binary = "\x89PNG https://wp.internal.example/";
        self::assertSame($binary, $rewriter->rewrite($binary, ContentKind::Binary));
    }

    public function testRedirectLocation(): void
    {
        $rewriter = new UrlRewriter(new Origin(self::ORIGIN), 'https://www.example.com');
        self::assertSame('/new/', $rewriter->rewriteLocation('https://wp.internal.example/new/'));
        self::assertSame('https://elsewhere.example.org/x', $rewriter->rewriteLocation('https://elsewhere.example.org/x'));
    }
}
