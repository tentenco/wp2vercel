<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Scan\FormFinder;

final class FormFinderTest extends TestCase
{
    public function testFormsThatPostToTheSiteItselfAreCounted(): void
    {
        $finder = new FormFinder('https://www.example.com');

        self::assertSame(1, $finder->postsToTheSite('<form method="post" action="/contact/" id="plain-form">'));
        self::assertSame(1, $finder->postsToTheSite('<form action="/contact-2/#wpcf7-f36-p37-o1" method="post" class="wpcf7-form init" novalidate="novalidate">'));
        self::assertSame(1, $finder->postsToTheSite("<FORM METHOD='POST'>"));
        self::assertSame(1, $finder->postsToTheSite('<form method=post action=send.php>'));
        self::assertSame(1, $finder->postsToTheSite('<form method="post" action="https://www.example.com/wp-comments-post.php">'));
        self::assertSame(2, $finder->postsToTheSite('<form method="post"><input name="a"></form><p>x</p><form method="post" action="?step=2">'));
    }

    public function testSearchFormsAndFormsSentElsewhereAreLeftAlone(): void
    {
        $finder = new FormFinder('https://www.example.com');

        self::assertSame(0, $finder->postsToTheSite('<form role="search" method="get" action="/" class="wp-block-search">'));
        self::assertSame(0, $finder->postsToTheSite('<form action="/">'));
        self::assertSame(0, $finder->postsToTheSite('<form method="post" action="https://formspree.io/f/abcd">'));
        self::assertSame(0, $finder->postsToTheSite('<form method="post" action="//forms.example.net/send">'));
        self::assertSame(0, $finder->postsToTheSite('<form method="post" action="mailto:hello@example.com">'));
        self::assertSame(0, $finder->postsToTheSite('<p>No form here, only a data-method="post" attribute on a <div data-method="post">.</p>'));
    }

    public function testWithoutAPublicAddressOnlyRelativeActionsCount(): void
    {
        $finder = new FormFinder('');

        self::assertSame(1, $finder->postsToTheSite('<form method="post" action="/contact/">'));
        self::assertSame(0, $finder->postsToTheSite('<form method="post" action="https://www.example.com/contact/">'));
    }

    public function testAFileIsNamedByTheAddressVisitorsUse(): void
    {
        self::assertSame('/', FormFinder::pageAddress('index.html'));
        self::assertSame('/contact/', FormFinder::pageAddress('contact/index.html'));
        self::assertSame('/404.html', FormFinder::pageAddress('404.html'));
        self::assertSame('/en/contact/', FormFinder::pageAddress('en/contact/index.html'));
    }
}
