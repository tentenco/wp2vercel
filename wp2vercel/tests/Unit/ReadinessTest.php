<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Build\Readiness;
use WP2Vercel\Crawl\FetchResult;
use WP2Vercel\Crawl\Queue;
use WP2Vercel\Settings;

final class ReadinessTest extends TestCase
{
    private const HOME = 'http://localhost:8097/';

    private static function states(array $items): array
    {
        return array_column($items, 'state', 'id');
    }

    public function testASiteThatIsReadyHasNothingToDo(): void
    {
        $home = Readiness::fetched(self::HOME, new FetchResult(200, 'text/html', '', '<html></html>'));
        $items = Readiness::items(true, self::HOME, $home, 'https://www.example.com', Settings::TARGET_VERCEL, null);

        self::assertSame('', $home);
        self::assertSame(['permalinks' => 'ok', 'reach' => 'ok', 'address' => 'ok', 'target' => 'ok'], self::states($items));
        self::assertStringContainsString(self::HOME, $items[1]['text']);
    }

    public function testAFreshInstallListsWhatBlocksAndWhatIsOptional(): void
    {
        $home = Readiness::fetched(self::HOME, FetchResult::failure('cURL error 7: Failed to connect to localhost port 8097'));
        $items = Readiness::items(false, self::HOME, $home, '', Settings::TARGET_NONE, null);

        self::assertSame(['permalinks' => 'todo', 'reach' => 'todo', 'address' => 'note', 'target' => 'note'], self::states($items));
        self::assertStringContainsString('Settings → Permalinks', $items[0]['text']);
        self::assertStringContainsString('cURL error 7: Failed to connect to localhost port 8097', $items[1]['text']);
        self::assertStringContainsString('Fetch address', $items[1]['text']);
    }

    public function testAnIncompletePublishTargetIsNamed(): void
    {
        $items = Readiness::items(true, self::HOME, '', '', Settings::TARGET_GITHUB, 'Add a GitHub access token before publishing to GitHub.');

        self::assertSame('todo', self::states($items)['target']);
        self::assertSame('Publish target: GitHub. Add a GitHub access token before publishing to GitHub.', $items[3]['text']);
    }

    public function testTheCheckBeforePublishingExplainsEachKindOfAnswer(): void
    {
        self::assertStringContainsString('redirects to https://example.com/en/', Readiness::fetched(self::HOME, new FetchResult(302, '', 'https://example.com/en/', '')));
        self::assertStringContainsString('Basic auth user and password', Readiness::fetched(self::HOME, new FetchResult(401, '', '', '')));
        self::assertStringContainsString('answered HTTP 500 instead of a page', Readiness::fetched(self::HOME, new FetchResult(500, '', '', 'error')));
        self::assertStringContainsString('came back empty', Readiness::fetched(self::HOME, new FetchResult(200, 'text/html', '', "  \n")));
    }

    public function testAFailedPublishRepeatsTheReasonTheCrawlRecorded(): void
    {
        $refused = Readiness::queued(self::HOME, ['status' => Queue::FAILED, 'http_status' => '0', 'note' => 'cURL error 7: Failed to connect']);
        self::assertStringContainsString('could not load its own home page from ' . self::HOME . ' (cURL error 7: Failed to connect)', $refused);
        self::assertStringContainsString('Fetch address', $refused);

        self::assertStringContainsString('answered HTTP 503', Readiness::queued(self::HOME, ['status' => Queue::FAILED, 'http_status' => '0', 'note' => 'HTTP 503']));
        self::assertStringContainsString('answered HTTP 404', Readiness::queued(self::HOME, ['status' => Queue::SKIPPED, 'http_status' => '404', 'note' => 'Not found']));
        self::assertStringContainsString('answered HTTP 403, so WordPress is not allowed', Readiness::queued(self::HOME, ['status' => Queue::SKIPPED, 'http_status' => '403', 'note' => 'Password protected or forbidden']));
        self::assertStringContainsString('redirects to /en/', Readiness::queued(self::HOME, ['status' => Queue::REDIRECT, 'http_status' => '301', 'note' => '/en/']));
        self::assertStringContainsString('could not be saved (Empty HTML response)', Readiness::queued(self::HOME, ['status' => Queue::FAILED, 'http_status' => '200', 'note' => 'Empty HTML response']));
    }

    public function testAHomePageThatWasNeverRecordedStillPointsAtTheFetchAddress(): void
    {
        $unknown = Readiness::queued(self::HOME, null);

        self::assertStringContainsString(self::HOME, $unknown);
        self::assertStringContainsString('Fetch address', $unknown);
    }

    public function testAPublishFolderThatCannotBeWrittenIsNamedBeforeTheFirstPublish(): void
    {
        $items = Readiness::items(true, 'https://cms.example.com/', '', 'https://www.example.com', 'none', null, '/var/www/html/wp-content/uploads');
        $storage = array_values(array_filter($items, static fn(array $item): bool => $item['id'] === 'storage'));

        self::assertCount(1, $storage);
        self::assertSame(Readiness::TODO, $storage[0]['state']);
        self::assertStringContainsString('cannot write to /var/www/html/wp-content/uploads', $storage[0]['text']);
        self::assertSame([], array_filter(
            Readiness::items(true, 'https://cms.example.com/', '', 'https://www.example.com', 'none', null),
            static fn(array $item): bool => $item['id'] === 'storage'
        ));
    }

    public function testAStorageFailureSaysWhatToCheckInsteadOfNamingASourceFile(): void
    {
        $message = \WP2Vercel\Output\StorageFailed::because('could not create the folder /srv/uploads/wp2vercel-ab/staging-3/site')->getMessage();

        self::assertStringContainsString('could not create the folder /srv/uploads/wp2vercel-ab/staging-3/site', $message);
        self::assertStringContainsString('web server user owns wp-content/uploads', $message);
        self::assertStringNotContainsString('.php', $message);
    }
}
