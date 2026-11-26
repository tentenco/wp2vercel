<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2Vercel\Build\Deadline;

final class DeadlineTest extends TestCase
{
    public function testACancelStopsTheRunningStepWithoutWaitingForItsTime(): void
    {
        $asked = 0;
        $cancelled = false;
        $deadline = new Deadline(60, function () use (&$asked, &$cancelled): bool {
            $asked++;
            return $cancelled;
        });

        self::assertFalse($deadline->passed());
        for ($i = 0; $i < 1000; $i++) {
            $deadline->passed();
        }
        self::assertSame(1, $asked);

        $cancelled = true;
        usleep(1100000);
        self::assertTrue($deadline->passed());
        self::assertSame(0.0, $deadline->remaining());
        self::assertSame(2, $asked);
        self::assertTrue($deadline->passed());
        self::assertSame(2, $asked);
    }

    public function testTheLastLookBeforePuttingTheCopyInPlaceDoesNotWaitForTheNextSecond(): void
    {
        $cancelled = false;
        $deadline = new Deadline(60, function () use (&$cancelled): bool {
            return $cancelled;
        });
        self::assertFalse($deadline->passed());
        $cancelled = true;
        self::assertFalse($deadline->passed());
        self::assertTrue($deadline->stopRequested());
        self::assertTrue($deadline->passed());
        self::assertFalse((new Deadline(60))->stopRequested());
    }

    public function testWithoutAStopCheckOnlyTheTimeCounts(): void
    {
        self::assertFalse((new Deadline(60))->passed());
        self::assertTrue((new Deadline(0))->passed());
    }
}
