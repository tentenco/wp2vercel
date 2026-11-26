<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

use Closure;

final class Deadline
{
    private const STOP_CHECK_SECONDS = 1.0;

    private readonly float $endsAt;
    private float $nextStopCheck = 0.0;
    private bool $stopped = false;

    public function __construct(float $seconds, private readonly ?Closure $stopRequested = null)
    {
        $this->endsAt = microtime(true) + $seconds;
    }

    public function passed(): bool
    {
        $now = microtime(true);
        return $now >= $this->endsAt || $this->stopRequestedAt($now);
    }

    public function stopRequested(): bool
    {
        $this->nextStopCheck = 0.0;
        return $this->stopRequestedAt(microtime(true));
    }

    public function remaining(): float
    {
        return $this->stopped ? 0.0 : max(0.0, $this->endsAt - microtime(true));
    }

    private function stopRequestedAt(float $now): bool
    {
        if ($this->stopped || $this->stopRequested === null || $now < $this->nextStopCheck) {
            return $this->stopped;
        }
        $this->nextStopCheck = $now + self::STOP_CHECK_SECONDS;
        $this->stopped = (bool) ($this->stopRequested)();
        return $this->stopped;
    }
}
