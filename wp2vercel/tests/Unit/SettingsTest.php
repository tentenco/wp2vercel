<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Settings;

final class SettingsTest extends TestCase
{
    public function testAPublicAddressTypedWithoutTheSchemeIsTakenAsHttps(): void
    {
        self::assertSame('https://www.example.com', Settings::clean('production_url', ' www.example.com/ '));
        self::assertSame('https://example.com:8443', Settings::clean('production_url', 'example.com:8443/blog'));
        self::assertSame('http://www.example.com', Settings::clean('production_url', 'http://www.example.com/'));
        self::assertSame('', Settings::clean('production_url', 'my site'));
    }

    public function testAFetchAddressIsNeverGuessed(): void
    {
        self::assertSame('http://localhost', Settings::clean('fetch_url', 'http://localhost/'));
        self::assertSame('http://wordpress:8080', Settings::clean('fetch_url', 'http://wordpress:8080'));
        self::assertSame('', Settings::clean('fetch_url', 'localhost'));
        self::assertSame('', Settings::clean('fetch_url', 'www.example.com'));
    }

    public function testANetlifySiteCanBeEnteredByNameAddressOrId(): void
    {
        self::assertSame('blue-cat.netlify.app', Settings::clean('netlify_site', 'blue-cat'));
        self::assertSame('blue-cat.netlify.app', Settings::clean('netlify_site', ' https://Blue-Cat.netlify.app/ '));
        self::assertSame('blue-cat.netlify.app', Settings::clean('netlify_site', 'https://app.netlify.com/sites/blue-cat/overview'));
        self::assertSame('blue-cat.netlify.app', Settings::clean('netlify_site', 'app.netlify.com/projects/blue-cat'));
        self::assertSame('3f2b8c1e-9d4a-4f6b-8e2c-1a2b3c4d5e6f', Settings::clean('netlify_site', '3F2B8C1E-9D4A-4F6B-8E2C-1A2B3C4D5E6F'));
        self::assertSame('www.example.com', Settings::clean('netlify_site', 'https://www.example.com/about/'));
        self::assertSame('', Settings::clean('netlify_site', 'my site'));
        self::assertSame(['netlify_site'], Settings::rejected(['netlify_site' => 'my site', 'deploy_target' => 'netlify']));
        self::assertSame('netlify', Settings::clean('deploy_target', 'netlify'));
        self::assertSame('none', Settings::clean('deploy_target', 'surge'));
    }

    public function testARepositoryCanBePastedAsItsGitHubAddress(): void
    {
        self::assertSame('tentenco/site', Settings::clean('github_repository', 'tentenco/site'));
        self::assertSame('tentenco/site', Settings::clean('github_repository', 'https://github.com/tentenco/site'));
        self::assertSame('tentenco/site', Settings::clean('github_repository', 'https://github.com/tentenco/site.git/'));
        self::assertSame('tentenco/site', Settings::clean('github_repository', 'git@github.com:tentenco/site.git'));
        self::assertSame('', Settings::clean('github_repository', 'https://gitlab.com/tentenco/site'));
        self::assertSame('', Settings::clean('github_repository', 'site'));
    }

    public function testOnlyValuesThatWereTypedAndRefusedAreReported(): void
    {
        self::assertSame(['fetch_url', 'github_repository'], Settings::rejected([
            'production_url' => 'www.example.com',
            'fetch_url' => 'localhost',
            'github_repository' => 'just-a-name',
            'github_branch' => '',
        ]));
        self::assertSame([], Settings::rejected(['production_url' => '', 'fetch_url' => '  ', 'github_repository' => '']));
        self::assertSame([], Settings::rejected(['fetch_concurrency' => 4]));
    }

    public function testABranchNameThatGitWouldRefuseIsReportedInsteadOfBeingSwappedForTheDefault(): void
    {
        self::assertSame(['github_branch'], Settings::rejected(['github_branch' => 'static site', 'github_base_branch' => 'main']));
        self::assertSame(['github_base_branch'], Settings::rejected(['github_branch' => 'release/static', 'github_base_branch' => 'ma in']));
        self::assertSame([], Settings::rejected(['github_branch' => '', 'github_base_branch' => ' main ']));
    }

    public function testAValueThatIsNotTextIsReportedAndNeverEmptiesTheField(): void
    {
        self::assertSame(['production_url', 'github_branch'], Settings::rejected(['production_url' => ['x'], 'github_branch' => ['y'], 'fetch_url' => '']));
    }
}
