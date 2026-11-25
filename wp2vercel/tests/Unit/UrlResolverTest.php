<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP2Vercel\Url\Origin;
use WP2Vercel\Url\UrlResolver;

final class UrlResolverTest extends TestCase
{
    public static function references(): array
    {
        return [
            'absolute same origin' => ['https://wp.internal.example/about/', 'https://wp.internal.example/about/'],
            'http variant of https origin' => ['http://wp.internal.example/about/', 'https://wp.internal.example/about/'],
            'protocol relative' => ['//wp.internal.example/a.css', 'https://wp.internal.example/a.css'],
            'root relative' => ['/contact/', 'https://wp.internal.example/contact/'],
            'relative to page' => ['sibling/', 'https://wp.internal.example/blog/post/sibling/'],
            'relative to file' => ['other.css', 'https://wp.internal.example/blog/post/other.css'],
            'parent traversal' => ['../../img/a.png', 'https://wp.internal.example/img/a.png'],
            'space in path' => ['/my file.pdf', 'https://wp.internal.example/my%20file.pdf'],
            'traversal cannot leave root' => ['../../../../etc/passwd', 'https://wp.internal.example/etc/passwd'],
            'fragment dropped' => ['/about/#team', 'https://wp.internal.example/about/'],
            'asset query dropped' => ['/wp-includes/a.js?ver=6.5', 'https://wp.internal.example/wp-includes/a.js'],
            'json escaped' => ['https:\/\/wp.internal.example\/x\/', 'https://wp.internal.example/x/'],
            'duplicate slashes collapsed' => ['/a//b/', 'https://wp.internal.example/a/b/'],
            'unicode slug is percent encoded' => ['/關於我們/', 'https://wp.internal.example/%E9%97%9C%E6%96%BC%E6%88%91%E5%80%91/'],
            'already encoded slug stays stable' => ['/%E9%97%9C/', 'https://wp.internal.example/%E9%97%9C/'],
            'page with query is dynamic' => ['/?s=term', null],
            'post id query is dynamic' => ['/?p=12', null],
            'external host' => ['https://example.org/a/', null],
            'lookalike host' => ['https://wp.internal.example.evil.test/a/', null],
            'different port' => ['https://wp.internal.example:8443/a/', null],
            'mailto' => ['mailto:a@example.com', null],
            'tel' => ['tel:+886', null],
            'data uri' => ['data:image/png;base64,AAAA', null],
            'javascript' => ['javascript:void(0)', null],
            'fragment only' => ['#top', null],
            'empty' => ['  ', null],
        ];
    }

    #[DataProvider('references')]
    public function testResolve(string $reference, ?string $expected): void
    {
        $resolver = new UrlResolver(new Origin('https://wp.internal.example'));
        self::assertSame($expected, $resolver->resolve($reference, 'https://wp.internal.example/blog/post/'));
    }

    public function testOriginWithPortOnlyMatchesThatPort(): void
    {
        $resolver = new UrlResolver(new Origin('http://localhost:8080'));
        self::assertSame('http://localhost:8080/a/', $resolver->resolve('http://localhost:8080/a/'));
        self::assertNull($resolver->resolve('http://localhost/a/'));
        self::assertNull($resolver->resolve('http://localhost:9090/a/'));
    }

    public function testPathOfResolvedUrl(): void
    {
        $resolver = new UrlResolver(new Origin('https://wp.internal.example'));
        self::assertSame('/a/b/', $resolver->path('https://wp.internal.example/a/b/'));
        self::assertSame('/', $resolver->path('https://wp.internal.example/'));
    }
}
