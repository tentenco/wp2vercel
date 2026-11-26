<?php

declare(strict_types=1);

namespace WP2Vercel\Redirects;

use WP2Vercel\Url\UrlResolver;

final class VercelConfig
{
    public const FILE_NAME = 'vercel.json';
    public const MAX_ROUTES = 2048;

    private const SECURITY_HEADERS = [
        ['key' => 'X-Content-Type-Options', 'value' => 'nosniff'],
        ['key' => 'Referrer-Policy', 'value' => 'strict-origin-when-cross-origin'],
        ['key' => 'X-Frame-Options', 'value' => 'SAMEORIGIN'],
    ];

    public function __construct(
        private readonly bool $trailingSlash,
        private readonly array $redirects,
        private readonly array $rewrites,
        private readonly string $homeAt = '',
    ) {
    }

    public function routeCount(): int
    {
        return count($this->redirects) + count($this->rewrites) + ($this->homeAt === '' ? 0 : 1);
    }

    public function exceedsRouteLimit(): bool
    {
        return $this->routeCount() > self::MAX_ROUTES;
    }

    public function toArray(): array
    {
        $config = [
            '$schema' => 'https://openapi.vercel.sh/vercel.json',
            'framework' => null,
            'buildCommand' => null,
            'installCommand' => null,
            'outputDirectory' => '.',
            'cleanUrls' => false,
            'trailingSlash' => $this->trailingSlash,
            'headers' => [
                ['source' => '/(.*)', 'headers' => self::SECURITY_HEADERS],
            ],
        ];

        $redirects = [];
        foreach ($this->redirects as $rule) {
            if ($rule instanceof RedirectRule) {
                $redirects[$rule->source] = $rule->toVercel();
            }
        }
        if ($this->homeAt !== '') {
            $redirects['/'] = ['source' => '/', 'destination' => self::literalDestination($this->homeAt), 'statusCode' => 307];
        }
        if ($redirects !== []) {
            ksort($redirects);
            $config['redirects'] = array_values($redirects);
        }

        $rewrites = [];
        foreach ($this->rewrites as $source => $destination) {
            foreach ($this->sourceVariants((string) $source) as $variant) {
                $rewrites[$variant] = ['source' => $variant, 'destination' => '/' . ltrim((string) $destination, '/')];
            }
        }
        if ($rewrites !== []) {
            ksort($rewrites);
            $config['rewrites'] = array_values($rewrites);
        }

        return $config;
    }

    public function toJson(): string
    {
        return self::encode($this->toArray());
    }

    public static function encode(array $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function literalDestination(string $path): string
    {
        return implode('/', array_map(static fn(string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));
    }

    private function sourceVariants(string $source): array
    {
        $bare = '/' . trim($source, '/');
        if ($bare === '/') {
            return ['/'];
        }
        return [$this->trailingSlash && !UrlResolver::hasFileExtension($bare) ? $bare . '/' : $bare];
    }
}
