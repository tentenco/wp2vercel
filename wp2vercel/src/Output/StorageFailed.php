<?php

declare(strict_types=1);

namespace WP2Vercel\Output;

use RuntimeException;

final class StorageFailed extends RuntimeException
{
    public static function because(string $what): self
    {
        return new self(sprintf(
            __('WordPress could not save the published files: %s. Check that the web server user owns wp-content/uploads and may write to it, and that the disk is not full.', 'wp2vercel'),
            $what
        ));
    }
}
