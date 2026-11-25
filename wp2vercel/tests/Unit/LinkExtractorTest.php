<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Extract\LinkExtractor;
use WP2Vercel\Url\ContentKind;
use WP2Vercel\Url\Origin;

final class LinkExtractorTest extends TestCase
{
    private LinkExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new LinkExtractor(new Origin('https://wp.internal.example'));
    }

    public function testHtmlAttributes(): void
    {
        $html = <<<'HTML'
        <html><head>
          <link rel="stylesheet" href="/wp-content/themes/t/style.css?ver=1">
          <link rel="preload" as="image" imagesrcset="/img/a-300.jpg 300w, /img/a-600.jpg 600w">
          <meta property="og:image" content="https://wp.internal.example/img/og.png">
          <meta name="description" content="/not-a-url/">
          <script src="/wp-includes/js/jquery.js"></script>
          <style>.hero{background:url("/img/hero.webp")} @import '/css/extra.css';</style>
        </head><body>
          <a href="/about/">About</a>
          <a href="/caf&eacute;/?x=1&amp;y=2">Cafe</a>
          <img src="/img/a.jpg" srcset="/img/a-1x.jpg 1x, /img/a-2x.jpg 2x" data-src="/img/lazy.jpg">
          <video poster="/img/poster.jpg"><source src="/video/a.mp4"></video>
          <div style="background-image:url(&quot;/img/inline.png&quot;)"></div>
          <script>var cfg = {"ajax":"https:\/\/wp.internal.example\/wp-content\/plugins\/p\/data.json","home":"https://wp.internal.example/landing/"};</script>
        </body></html>
        HTML;

        $found = $this->extractor->extract($html, ContentKind::Html);

        foreach ([
            '/wp-content/themes/t/style.css?ver=1',
            '/img/a-300.jpg',
            '/img/a-600.jpg',
            'https://wp.internal.example/img/og.png',
            '/wp-includes/js/jquery.js',
            '/img/hero.webp',
            '/css/extra.css',
            '/about/',
            '/café/?x=1&y=2',
            '/img/a.jpg',
            '/img/a-1x.jpg',
            '/img/a-2x.jpg',
            '/img/lazy.jpg',
            '/img/poster.jpg',
            '/video/a.mp4',
            '/img/inline.png',
            'https://wp.internal.example/wp-content/plugins/p/data.json',
            'https://wp.internal.example/landing/',
        ] as $expected) {
            self::assertContains($expected, $found);
        }
        self::assertNotContains('/not-a-url/', $found);
    }

    public function testCss(): void
    {
        $css = "@import url(base.css);\n.a{background:url( '../img/a.png' )}\n.b{background:url(data:image/png;base64,AAAA)}\n@font-face{src:url(\"fonts/a.woff2\") format('woff2')}";
        $found = $this->extractor->extract($css, ContentKind::Css);
        self::assertEqualsCanonicalizing(['base.css', '../img/a.png', 'fonts/a.woff2'], $found);
    }

    public function testSitemapAndStylesheet(): void
    {
        $xml = '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="https://wp.internal.example/wp-sitemap.xsl" ?>'
            . '<urlset><url><loc>https://wp.internal.example/hello/</loc></url><url><loc><![CDATA[https://wp.internal.example/a&amp;b/]]></loc></url></urlset>';
        $found = $this->extractor->extract($xml, ContentKind::Xml);
        self::assertContains('https://wp.internal.example/wp-sitemap.xsl', $found);
        self::assertContains('https://wp.internal.example/hello/', $found);
    }

    public function testRobotsSitemapLine(): void
    {
        $found = $this->extractor->extract("User-agent: *\nDisallow: /wp-admin/\nSitemap: https://wp.internal.example/wp-sitemap.xml\n", ContentKind::Text);
        self::assertSame(['https://wp.internal.example/wp-sitemap.xml'], $found);
    }

    public function testScriptOnlyYieldsOriginAbsoluteUrls(): void
    {
        $js = 'var a="https://wp.internal.example/chunks/1.js", b="/relative/ignored.js", c="https://cdn.example.org/x.js";';
        self::assertSame(['https://wp.internal.example/chunks/1.js'], $this->extractor->extract($js, ContentKind::Script));
    }

    public function testScriptCodeAndTemplatePlaceholdersAreNotLinks(): void
    {
        $html = '<script>const u = new URL(r); fetch(`${base}/x`); el.style.background = "url(" + t + ")";</script>'
            . '<a href="{sitemap:loc}">x</a><a href="/real/">y</a>';
        self::assertSame(['/real/'], $this->extractor->extract($html, ContentKind::Html));
        self::assertSame([], $this->extractor->extract('<a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a>', ContentKind::Xml));
    }

    public function testMalformedHtmlDoesNotThrow(): void
    {
        $found = $this->extractor->extract('<a href="/ok/"><div <<< <img src=/img/x.png <a href=', ContentKind::Html);
        self::assertContains('/ok/', $found);
    }
}
