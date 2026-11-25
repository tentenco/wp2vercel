<?php

declare(strict_types=1);

namespace WP2Vercel\Output;

final class Manifest
{
    private ?array $entries = null;

    public function __construct(private readonly string $file, private readonly string $siteDirectory)
    {
    }

    public function reset(): void
    {
        file_put_contents($this->file, '');
        $this->entries = null;
    }

    public function append(string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);
        $size = (int) filesize($absolute);
        $line = json_encode([
            'path' => $relativePath,
            'sha1' => sha1_file($absolute),
            'git' => self::gitBlobSha($absolute, $size),
            'size' => $size,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->file, $line . "\n", FILE_APPEND);
        $this->entries = null;
    }

    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        $this->entries = [];
        $handle = is_file($this->file) ? fopen($this->file, 'rb') : false;
        if ($handle === false) {
            return $this->entries;
        }
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (is_array($entry) && isset($entry['path'], $entry['sha1'], $entry['git'], $entry['size'])) {
                $this->entries[] = $entry;
            }
        }
        fclose($handle);
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries());
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->siteDirectory . '/' . $relativePath;
    }

    public function pathBySha1(): array
    {
        $paths = [];
        foreach ($this->entries() as $entry) {
            $paths[$entry['sha1']] = $entry['path'];
        }
        return $paths;
    }

    public static function gitBlobSha(string $absolutePath, int $size): string
    {
        $context = hash_init('sha1');
        hash_update($context, 'blob ' . $size . "\0");
        hash_update_file($context, $absolutePath);
        return hash_final($context);
    }
}
