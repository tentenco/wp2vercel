<?php

declare(strict_types=1);

namespace WP2Vercel\Tests\Support;

use WP2Vercel\Output\Manifest;

final class TemporarySite
{
    public readonly string $directory;

    public function __construct(array $files)
    {
        $this->directory = sys_get_temp_dir() . '/wp2vercel-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/site', 0777, true);
        foreach ($files as $path => $content) {
            $target = $this->directory . '/site/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, $content);
        }
    }

    public function manifest(): Manifest
    {
        $manifest = new Manifest($this->directory . '/manifest.jsonl', $this->directory . '/site');
        $manifest->reset();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory . '/site', \FilesystemIterator::SKIP_DOTS));
        $paths = [];
        foreach ($iterator as $file) {
            $paths[] = substr($file->getPathname(), strlen($this->directory . '/site/'));
        }
        sort($paths);
        foreach ($paths as $path) {
            $manifest->append($path);
        }
        return $manifest;
    }

    public function path(string $relative): string
    {
        return $this->directory . '/site/' . $relative;
    }
}
