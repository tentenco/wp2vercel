<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Build\Build;
use WP2Vercel\Build\BuildRunner;

final class BuildWatchdogTest extends TestCase
{
    private const NOW = 1795630000;

    public function testRunsThatNeverCameBackAreCountedPerPublishAndStep(): void
    {
        self::assertSame(0, BuildRunner::unfinishedRuns(false, 34, 'crawl'));
        self::assertSame(3, BuildRunner::unfinishedRuns(['build' => 34, 'step' => 'crawl', 'count' => 3], 34, 'crawl'));
    }

    public function testAnotherPublishOrALaterStepStartsTheCountAgain(): void
    {
        self::assertSame(0, BuildRunner::unfinishedRuns(['build' => 33, 'step' => 'crawl', 'count' => 4], 34, 'crawl'));
        self::assertSame(0, BuildRunner::unfinishedRuns(['build' => 34, 'step' => 'crawl', 'count' => 4], 34, 'scan'));
        self::assertSame(0, BuildRunner::unfinishedRuns('broken', 34, 'crawl'));
    }

    public function testARunningPublishIsIdleForTheTimeSinceItWasLastSaved(): void
    {
        self::assertSame(420, $this->build(Build::STATUS_RUNNING, [], 420)->idleSeconds(self::NOW));
    }

    public function testWaitingForADecisionOrForARateLimitIsNotIdle(): void
    {
        self::assertSame(0, $this->build(Build::STATUS_WAITING, ['waiting' => ['reason' => 'approval']], 9000)->idleSeconds(self::NOW));
        self::assertSame(0, $this->build(Build::STATUS_RUNNING, ['deployment' => ['wait_until' => self::NOW + 600]], 900)->idleSeconds(self::NOW));
        self::assertSame(900, $this->build(Build::STATUS_RUNNING, ['deployment' => ['wait_until' => self::NOW - 5]], 900)->idleSeconds(self::NOW));
    }

    public function testSettingsWaitWhileAPublishRunsButNotWhileItWaitsForApproval(): void
    {
        self::assertNull(BuildRunner::holdsSettings(null));
        self::assertSame(34, BuildRunner::holdsSettings($this->build(Build::STATUS_RUNNING, [], 5))?->id);
        self::assertSame(34, BuildRunner::holdsSettings($this->build(Build::STATUS_PENDING, [], 5))?->id);
        self::assertNull(BuildRunner::holdsSettings($this->build(Build::STATUS_WAITING, ['waiting' => ['reason' => 'approval']], 5)));
        self::assertNull(BuildRunner::holdsSettings($this->build(Build::STATUS_COMPLETED, [], 5)));
        self::assertStringContainsString('Publish #34 is running', BuildRunner::settingsWaitMessage(34));
    }

    public function testAFinishedPublishIsNeverIdle(): void
    {
        self::assertSame(0, $this->build(Build::STATUS_COMPLETED, [], 9000)->idleSeconds(self::NOW));
        self::assertSame(0, $this->build(Build::STATUS_FAILED, [], 9000)->idleSeconds(self::NOW));
    }

    private function build(string $status, array $state, int $savedSecondsAgo): Build
    {
        $saved = gmdate('Y-m-d H:i:s', self::NOW - $savedSecondsAgo);
        return new Build(34, Build::TYPE_FULL, $status, 'crawl', $state, '', $saved, $saved, null);
    }
}
