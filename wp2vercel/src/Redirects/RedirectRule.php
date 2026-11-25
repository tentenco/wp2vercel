<?php

declare(strict_types=1);

namespace WP2Vercel\Redirects;

final class RedirectRule
{
    private const ALLOWED_STATUS = [301, 302, 303, 307, 308];

    private function __construct(
        public readonly string $source,
        public readonly string $destination,
        public readonly int $status,
    ) {
    }

    public static function create(string $source, string $destination, int $status = 301): ?self
    {
        $source = self::normalizeSource($source);
        $destination = trim($destination);
        if ($source === null || !self::isSafeDestination($destination)) {
            return null;
        }
        if (self::withoutTrailingSlash($source) === self::withoutTrailingSlash(strtok($destination, '?#') ?: '/')) {
            return null;
        }
        return new self($source, $destination, in_array($status, self::ALLOWED_STATUS, true) ? $status : 301);
    }

    public static function fromArray(array $data): ?self
    {
        return self::create((string) ($data['source'] ?? ''), (string) ($data['destination'] ?? ''), (int) ($data['status'] ?? 301));
    }

    public function toArray(): array
    {
        return ['source' => $this->source, 'destination' => $this->destination, 'status' => $this->status];
    }

    public function toVercel(): array
    {
        return [
            'source' => self::escapePathToRegexp($this->source),
            'destination' => $this->destination,
            'statusCode' => $this->status,
        ];
    }

    private static function normalizeSource(string $source): ?string
    {
        $source = trim($source);
        if ($source === '' || preg_match('~^[a-z][a-z0-9+.\-]*:~i', $source) || str_starts_with($source, '//')) {
            return null;
        }
        $source = '/' . ltrim(strtok($source, '?#') ?: '', '/');
        if ($source === '/' || str_contains($source, '..')) {
            return null;
        }
        return $source;
    }

    private static function isSafeDestination(string $destination): bool
    {
        if ($destination === '' || preg_match('~[\x00-\x20<>"\\\\]~', $destination)) {
            return false;
        }
        if (str_starts_with($destination, '/')) {
            return !str_starts_with($destination, '//');
        }
        $parts = parse_url($destination);
        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private static function escapePathToRegexp(string $path): string
    {
        return preg_replace('~([:*?+(){}\[\]\\\\!])~', '\\\\$1', $path) ?? $path;
    }

    private static function withoutTrailingSlash(string $path): string
    {
        return rtrim($path, '/') ?: '/';
    }
}
