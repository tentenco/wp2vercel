<?php

declare(strict_types=1);

namespace WP2Vercel\Crawl;

final class FetchResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $location,
        public readonly string $body,
        public readonly string $error = '',
        public readonly ?string $localFile = null,
    ) {
    }

    public static function failure(string $error): self
    {
        return new self(0, '', '', '', $error);
    }

    public static function local(string $file): self
    {
        return new self(200, '', '', '', '', $file);
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true) && $this->location !== '';
    }

    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}
