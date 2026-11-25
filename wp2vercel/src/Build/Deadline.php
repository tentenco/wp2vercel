<?php

declare(strict_types=1);

namespace WP2Vercel\Build;

final class Deadline
{
    private readonly float $endsAt;

    public function __construct(float $seconds)
    {
        $this->endsAt = microtime(true) + $seconds;
    }

    public function passed(): bool
    {
        return microtime(true) >= $this->endsAt;
    }

    public function remaining(): float
    {
        return max(0.0, $this->endsAt - microtime(true));
    }
}
