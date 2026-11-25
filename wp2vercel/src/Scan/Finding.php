<?php

declare(strict_types=1);

namespace WP2Vercel\Scan;

final class Finding
{
    public function __construct(
        public readonly string $type,
        public readonly string $file,
        public readonly int $line,
        public readonly string $excerpt,
    ) {
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'file' => $this->file, 'line' => $this->line, 'excerpt' => $this->excerpt];
    }
}
