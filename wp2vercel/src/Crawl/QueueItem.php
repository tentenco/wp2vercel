<?php

declare(strict_types=1);

namespace WP2Vercel\Crawl;

final class QueueItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $foundOn,
        public readonly int $attempts,
    ) {
    }
}
