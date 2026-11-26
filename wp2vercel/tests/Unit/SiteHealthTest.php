<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Admin\SiteHealth;

final class SiteHealthTest extends TestCase
{
    public function testTheSupportInformationDescribesTheSetupAndTheLatestPublish(): void
    {
        $fields = SiteHealth::fields([
            'version' => '0.2.0',
            'target' => 'github',
            'production_url' => 'https://www.example.com',
            'fetch_url' => 'http://127.0.0.1:8080',
            'home_url' => 'https://cms.example.com/',
            'permalinks' => '/%postname%/',
            'cron_disabled' => true,
            'time_limit' => 30,
            'latest' => ['id' => 27, 'type' => 'single', 'status' => 'failed', 'finished_at' => '2026-11-25 17:41:10', 'message' => 'GitHub did not accept the access token.'],
        ]);

        self::assertSame('0.2.0', $fields['version']['value']);
        self::assertSame('github', $fields['target']['value']);
        self::assertSame('https://www.example.com', $fields['production_url']['value']);
        self::assertSame('30 seconds', $fields['time_limit']['value']);
        self::assertStringContainsString('DISABLE_WP_CRON is set', $fields['cron']['value']);
        self::assertSame('#27, single, failed, 2026-11-25 17:41:10: GitHub did not accept the access token.', $fields['latest']['value']);
    }

    public function testAddressesOfThePrivateWordPressAreLeftOutOfTheCopiedText(): void
    {
        $fields = SiteHealth::fields(['fetch_url' => 'http://127.0.0.1:8080', 'home_url' => 'https://cms.example.com/']);

        self::assertTrue($fields['fetch_url']['private']);
        self::assertTrue($fields['home_url']['private']);
        self::assertArrayNotHasKey('private', $fields['production_url']);
    }

    public function testAFreshSiteReadsAsNotSetUpYet(): void
    {
        $fields = SiteHealth::fields(['version' => '0.2.0', 'target' => 'none']);

        self::assertSame('not set', $fields['production_url']['value']);
        self::assertSame('same as the WordPress address', $fields['fetch_url']['value']);
        self::assertSame('none yet', $fields['latest']['value']);
        self::assertSame('none', $fields['time_limit']['value']);
        self::assertSame('started by visits', $fields['cron']['value']);
        self::assertStringStartsWith('plain', $fields['permalinks']['value']);
    }

    public function testNoFieldCarriesAToken(): void
    {
        self::assertSame([], array_filter(array_keys(SiteHealth::fields([])), static fn(string $key): bool => str_contains($key, 'token') || str_contains($key, 'secret') || str_contains($key, 'password')));
    }
}
