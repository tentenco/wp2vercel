<?php

declare(strict_types=1);

namespace WP2Vercel\Deploy;

use WP2Vercel\Build\Deadline;
use WP2Vercel\Output\Manifest;

interface Deployer
{
    public function configurationError(): ?string;

    public function testConnection(): string;

    public function advance(array $state, Manifest $manifest, Deadline $deadline, callable $log): array;
}
