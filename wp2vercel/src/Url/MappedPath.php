<?php

declare(strict_types=1);

namespace WP2Vercel\Url;

final class MappedPath
{
    public function __construct(
        public readonly string $file,
        public readonly ?string $rewriteSource,
    ) {
    }
}
