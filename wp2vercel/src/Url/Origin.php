<?php

declare(strict_types=1);

namespace WP2Vercel\Url;

use InvalidArgumentException;

final class Origin
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public readonly string $scheme;
    public readonly string $authority;

    public function __construct(string $url)
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            throw new InvalidArgumentException('Origin URL must include a host: ' . $url);
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            throw new InvalidArgumentException('Origin URL must be http or https: ' . $url);
        }
        $this->scheme = $scheme;
        $this->authority = self::authorityFrom($scheme, strtolower($parts['host']), $parts['port'] ?? null);
    }

    public function base(): string
    {
        return $this->scheme . '://' . $this->authority;
    }

    public function matchesAuthority(string $scheme, string $host, ?int $port): bool
    {
        $scheme = strtolower($scheme);
        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            return false;
        }
        return self::authorityFrom($scheme, strtolower($host), $port) === $this->authority;
    }

    public function sameAuthorityAs(self $other): bool
    {
        return $this->authority === $other->authority;
    }

    private static function authorityFrom(string $scheme, string $host, ?int $port): string
    {
        if ($port === null || $port === self::DEFAULT_PORTS[$scheme]) {
            return $host;
        }
        return $host . ':' . $port;
    }
}
