<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Scan\LeakScanner;
use WP2Vercel\Url\Origin;

final class LeakScannerTest extends TestCase
{
    public function testFindsEveryEncodingOfThePrivateHost(): void
    {
        $scanner = new LeakScanner([new Origin('https://wp.internal.example')]);
        $content = "line one\n<a href=\"https://wp.internal.example/a\">\n"
            . "{\"u\":\"https:\\/\\/wp.internal.example\\/b\"}\n"
            . "?u=https%3A%2F%2Fwp.internal.example%2Fc\n"
            . "mail admin@wp.internal.example\n";
        $findings = $scanner->scanText('index.html', $content);

        self::assertCount(4, $findings);
        self::assertSame([2, 3, 4, 5], array_map(static fn($finding): int => $finding->line, $findings));
        self::assertSame(LeakScanner::TYPE_ORIGIN_HOST, $findings[0]->type);
        self::assertStringContainsString('wp.internal.example/a', $findings[0]->excerpt);
    }

    public function testIgnoresPublicAndLookalikeHosts(): void
    {
        $scanner = new LeakScanner([new Origin('https://example.com')]);
        self::assertSame([], $scanner->scanText('a.html', 'https://www.example.com/ https://example.com.evil.test/ https://notexample.com/ https://example.community/'));
        self::assertCount(1, $scanner->scanText('a.html', 'see https://example.com.'));
    }

    public function testPortSpecificOrigin(): void
    {
        $scanner = new LeakScanner([new Origin('http://localhost:8080')]);
        self::assertCount(1, $scanner->scanText('a.js', 'fetch("http://localhost:8080/x")'));
        self::assertCount(1, $scanner->scanText('a.js', 'u=http%3A%2F%2Flocalhost%3A8080%2Fx'));
        self::assertSame([], $scanner->scanText('a.js', 'fetch("http://localhost:80801/x")'));
    }

    public function testFlagsSensitiveFilesAndSkipsBinaryContent(): void
    {
        $directory = sys_get_temp_dir() . '/wp2v-scan-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/a.png', 'https://wp.internal.example/ inside a binary');
        file_put_contents($directory . '/dump.sql', 'select 1');
        $scanner = new LeakScanner([new Origin('https://wp.internal.example')]);

        self::assertSame([], $scanner->scanFile('img/a.png', $directory . '/a.png'));
        $findings = $scanner->scanFile('backup/dump.sql', $directory . '/dump.sql');
        self::assertCount(1, $findings);
        self::assertSame(LeakScanner::TYPE_SENSITIVE_FILE, $findings[0]->type);
        foreach (['wp-config.php', 'x/wp-config.php.bak', '.git/config', 'a/.env', 'debug.log'] as $path) {
            self::assertNotSame([], $scanner->scanFile($path, $directory . '/missing'), $path);
        }
        self::assertSame([], $scanner->scanFile('wp-content/themes/t/config.json', $directory . '/missing'));
    }

    public function testSingleLabelHostOnlyMatchesInsideUrls(): void
    {
        $scanner = new LeakScanner([new Origin('http://wordpress')]);
        $noise = '<a href="https://wordpress.org">WordPress</a> {"imports":{"@wordpress/interactivity":"/x.js"}} wordpress-importer';
        self::assertSame([], $scanner->scanText('a.html', $noise));
        self::assertCount(3, $scanner->scanText('a.html', 'http://wordpress/x "http:\\/\\/wordpress\\/y" u=http%3A%2F%2Fwordpress%2Fz'));
    }

    public function testExtraRulesReportTheirOwnTypeAndIgnoreBrokenPatterns(): void
    {
        $scanner = new LeakScanner([], 200, [
            ['type' => 'rest_api', 'pattern' => '~/wp-json/~'],
            ['type' => 'broken', 'pattern' => '~[unclosed~'],
            ['type' => '', 'pattern' => '~x~'],
        ]);
        $findings = $scanner->scanText('a.html', "ok\n<link href=\"/wp-json/wp/v2\">\n");
        self::assertCount(1, $findings);
        self::assertSame('rest_api', $findings[0]->type);
        self::assertSame(2, $findings[0]->line);
    }

    public function testNoPrivateOriginsMeansNoHostFindings(): void
    {
        self::assertSame([], (new LeakScanner([]))->scanText('a.html', 'https://wp.internal.example/'));
    }
}
