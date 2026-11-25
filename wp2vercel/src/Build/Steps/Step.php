<?php

declare(strict_types=1);

namespace WP2Vercel\Build\Steps;

use WP2Vercel\Build\Build;
use WP2Vercel\Build\Deadline;

interface Step
{
    public function run(Build $build, Deadline $deadline): bool;
}
